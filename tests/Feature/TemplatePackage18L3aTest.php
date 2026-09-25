<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\Templates\RecipeApplier;
use App\Support\Templates\TemplateManifestValidator;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRegistry;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-3a — Template Ecosystem Foundation + Manufacturing OS Demo 防回归。
 *
 * 验证模板包生态的最小闭环：
 *   - Pack 物理结构被发现、Manifest 经校验（含字段 / 行业 / 版本 / 依赖）；
 *   - 激活后包级模板注册进双来源 Registry，停用即移除；
 *   - Recipe 加载、按语言幂等落地 Page / Block（locale map 解析、内部 URL 加 /en）；
 *   - products 写 sidebar、contact 增量不重复、about 新建组合页；
 *   - 幂等、不覆盖手动区块、固定 main 槽不被写入；
 *   - 中英页面真实 HTTP 200 且内容语言正确。
 */
class TemplatePackage18L3aTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    private const PACK = 'manufacturing-pro';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        $this->seed(DefaultFormSeeder::class);
        $this->seed(SystemPageSeeder::class);

        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    /** 激活包并应用一个配方，返回统计。 */
    private function applyRecipe(string $key): array
    {
        TemplatePackageManager::activate(self::PACK);
        $recipe = TemplatePackageManager::recipe(self::PACK, $key);
        $this->assertNotNull($recipe, "配方不存在：{$key}");

        return RecipeApplier::apply($this->site, self::PACK, $recipe);
    }

    private function homePage(string $locale): Page
    {
        return Page::where('is_home', true)->where('locale', $locale)->firstOrFail();
    }

    private function systemPage(string $key, string $locale): Page
    {
        return Page::where('system_key', $key)->where('is_system', true)
            ->where('locale', $locale)->firstOrFail();
    }

    /** 创建最小双语站点组织（产品总览门禁需要 company）。 */
    private function makeCompany(): void
    {
        $org = Entity::create([
            'site_id' => $this->site->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'site-organization',
            'name' => '示例公司',
            'summary' => '示例公司简介',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        $org->createTranslation('en', [
            'slug' => 'site-organization',
            'name' => 'Example Company',
            'summary' => 'Example company profile',
            'description' => 'Example company profile',
        ]);
        Catalog::flush();
        PageCache::flush();
    }

    // ----------------------------------------------------------------
    // 1. Pack 发现 / 校验
    // ----------------------------------------------------------------

    public function test_pack_is_discovered_and_valid(): void
    {
        $this->assertArrayHasKey(self::PACK, TemplatePackageManager::all());
        $this->assertTrue(TemplatePackageManager::exists(self::PACK));
        $this->assertArrayNotHasKey(self::PACK, TemplatePackageManager::invalidPacks());
        $this->assertTrue(TemplateManifestValidator::isValid(
            resource_path('templates/' . self::PACK)
        ));
    }

    public function test_manifest_declares_required_fields(): void
    {
        $manifest = TemplatePackageManager::all()[self::PACK];

        $this->assertSame(self::PACK, $manifest['id']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['version']);
        $this->assertContains('manufacturing', $manifest['industry']);
        $this->assertContains('zh-CN', $manifest['locales']);
        $this->assertContains('en', $manifest['locales']);
        $this->assertNotEmpty($manifest['theme']);
        $this->assertContains('hero', $manifest['requires']['components']);
        $this->assertContains('form_reference', $manifest['requires']['components']);
        $this->assertContains('home', $manifest['pages']);
        $this->assertArrayHasKey('author', $manifest);
        $this->assertArrayHasKey('license', $manifest);
    }

    public function test_validator_returns_errors_for_broken_manifest(): void
    {
        $tmp = sys_get_temp_dir() . '/geo-badpack-' . uniqid();
        @mkdir($tmp);
        file_put_contents($tmp . '/manifest.json', json_encode([
            'id' => basename($tmp),
            'name' => 'X',
            'version' => 'not-semver',
            'industry' => ['not-an-industry'],
            'locales' => ['fr-FR'],
            'theme' => 'default',
            'requires' => ['components' => ['not-a-block']],
            'pages' => [],
        ]));

        $errors = TemplateManifestValidator::validate($tmp);
        $this->assertNotEmpty($errors);
        $this->assertFalse(TemplateManifestValidator::isValid($tmp));

        @unlink($tmp . '/manifest.json');
        @rmdir($tmp);
    }

    // ----------------------------------------------------------------
    // 2. 激活 / 包模板注册
    // ----------------------------------------------------------------

    public function test_activate_registers_pack_templates_and_deactivate_removes(): void
    {
        $this->assertTrue(TemplatePackageManager::activate(self::PACK));
        $this->assertSame(self::PACK, TemplatePackageManager::active());
        $this->assertTrue(TemplateRegistry::has('mfg-home'));
        $this->assertTrue(TemplateRegistry::has('mfg-landing'));

        TemplatePackageManager::deactivate();
        $this->assertSame('', TemplatePackageManager::active());
        $this->assertFalse(TemplateRegistry::has('mfg-home'));
        $this->assertFalse(TemplateRegistry::has('mfg-landing'));
    }

    public function test_recipes_are_loaded(): void
    {
        $keys = array_keys(TemplatePackageManager::recipes(self::PACK));
        foreach (['homepage', 'products', 'contact', 'about'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    // ----------------------------------------------------------------
    // 3. Homepage 配方（双语 + URL 前缀）
    // ----------------------------------------------------------------

    public function test_apply_homepage_creates_bilingual_blocks(): void
    {
        $r = $this->applyRecipe('homepage');

        $this->assertGreaterThan(0, $r['created']);
        $this->assertContains('zh-CN', $r['locales']);
        $this->assertContains('en', $r['locales']);

        $zh = $this->homePage('zh-CN');
        $en = $this->homePage('en');

        $this->assertSame(7, $zh->blocks()->where('slot', 'main')->count());
        $this->assertSame(7, $en->blocks()->where('slot', 'main')->count());

        // 中英两行属于同一逻辑首页。
        $this->assertSame($zh->translation_group, $en->translation_group);

        $zhHero = $zh->blocks()->where('type', 'hero')->firstOrFail()->cfg();
        $enHero = $en->blocks()->where('type', 'hero')->firstOrFail()->cfg();

        $this->assertSame('值得信赖的制造合作伙伴', $zhHero['title']);
        $this->assertSame('Your Trusted Manufacturing Partner', $enHero['title']);

        $this->assertSame('/contact/', $zhHero['buttons'][0]['url']);
        $this->assertSame('/en/contact/', $enHero['buttons'][0]['url']);
        $this->assertSame('/en/products/', $enHero['buttons'][1]['url']);
    }

    public function test_homepage_renders_bilingual_http_200(): void
    {
        $this->applyRecipe('homepage');

        $zh = $this->get('http://localhost/')->assertOk()->getContent();
        $en = $this->get('http://localhost/en/')->assertOk()->getContent();

        $this->assertStringContainsString('值得信赖的制造合作伙伴', $zh);
        $this->assertStringContainsString('Your Trusted Manufacturing Partner', $en);
        $this->assertStringNotContainsString('值得信赖的制造合作伙伴', $en);
    }

    // ----------------------------------------------------------------
    // 4. Products 配方（sidebar）
    // ----------------------------------------------------------------

    public function test_apply_products_fills_sidebar(): void
    {
        $this->applyRecipe('products');

        $zh = $this->systemPage('products', 'zh-CN');
        $en = $this->systemPage('products', 'en');

        $this->assertSame(2, $zh->blocks()->where('slot', 'sidebar')->count());
        $this->assertSame(2, $en->blocks()->where('slot', 'sidebar')->count());
        $this->assertSame(0, $zh->blocks()->where('slot', 'main')->count());
    }

    public function test_products_sidebar_renders_http_200(): void
    {
        $this->makeCompany();
        $this->applyRecipe('products');

        $this->get('http://localhost/products/')->assertOk()
            ->assertSee('需要选型建议？');
        $this->get('http://localhost/en/products/')->assertOk()
            ->assertSee('Need Help Choosing?');
    }

    // ----------------------------------------------------------------
    // 5. Contact 配方（增量、不重复）
    // ----------------------------------------------------------------

    public function test_apply_contact_is_incremental_without_duplicates(): void
    {
        $this->applyRecipe('contact');

        $zh = $this->systemPage('contact', 'zh-CN');
        $main = $zh->blocks()->where('slot', 'main')->orderBy('sort')->get();

        $types = $main->pluck('type')->all();
        $this->assertContains('contact_info', $types);
        $this->assertContains('form_reference', $types);
        $this->assertContains('rich_text', $types);
        $this->assertSame(1, $main->where('type', 'rich_text')->count());

        // 配方 rich_text 排在 seeder 的 contact_info(0) / form_reference(1) 之后。
        $this->assertSame(2, $main->firstWhere('type', 'rich_text')->sort);
    }

    public function test_contact_renders_http_200(): void
    {
        $this->applyRecipe('contact');

        $this->get('http://localhost/contact/')->assertOk()->assertSee('我们的服务承诺');
        $this->get('http://localhost/en/contact/')->assertOk()->assertSee('Our Commitment');
    }

    // ----------------------------------------------------------------
    // 6. About 配方（新建组合页）
    // ----------------------------------------------------------------

    public function test_apply_about_creates_composed_page(): void
    {
        $r = $this->applyRecipe('about');
        $this->assertGreaterThan(0, $r['pages']);

        $zh = Page::where('slug', 'about-company')->where('locale', 'zh-CN')->firstOrFail();
        $en = Page::where('slug', 'about-company')->where('locale', 'en')->firstOrFail();

        $this->assertSame(5, $zh->blocks()->where('slot', 'main')->count());
        $this->assertSame(5, $en->blocks()->where('slot', 'main')->count());
        $this->assertSame($zh->translation_group, $en->translation_group);
    }

    public function test_about_renders_http_200(): void
    {
        $this->applyRecipe('about');

        $this->get('http://localhost/about-company')->assertOk()
            ->assertSee('专注制造 · 持续创造价值');
        $this->get('http://localhost/en/about-company')->assertOk()
            ->assertSee('Focused on Manufacturing, Built on Value');
    }

    // ----------------------------------------------------------------
    // 7. 幂等 / 手动区块保护 / 固定槽
    // ----------------------------------------------------------------

    public function test_apply_is_idempotent(): void
    {
        $this->applyRecipe('homepage');
        $second = $this->applyRecipe('homepage');

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['deleted']);
        $this->assertSame(7, $this->homePage('zh-CN')->blocks()->where('slot', 'main')->count());
    }

    public function test_manual_blocks_are_not_touched(): void
    {
        $home = $this->homePage('zh-CN');
        PageBlock::create([
            'site_id' => $this->site->id,
            'page' => 'home',
            'slot' => 'main',
            'type' => 'rich_text',
            'sort' => 99,
            'limit' => 0,
            'is_active' => true,
            'page_id' => $home->id,
            'content' => json_encode(['title' => '手动保留区块'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->applyRecipe('homepage');

        $manual = $home->blocks()->where('sort', 99)->first();
        $this->assertNotNull($manual);
        $this->assertSame('手动保留区块', $manual->cfg()['title']);
        $this->assertSame(8, $home->blocks()->where('slot', 'main')->count());
    }

    public function test_listing_fixed_main_slot_is_not_persisted(): void
    {
        $this->applyRecipe('products');

        // products main 是 sys_products 虚拟直驱，配方不能往 main 落块。
        $zh = $this->systemPage('products', 'zh-CN');
        $this->assertSame(0, $zh->blocks()->where('slot', 'main')->count());
    }
}
