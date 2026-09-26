<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRecipeValidator;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-3b — Template Ecosystem Completion 防回归。
 *
 * 验证模板从「可分发 Package」升级为「模板生态系统」：
 *   - 8 个 Business OS 包全部被发现、清单有效，且声明 AI/GEO 可理解 metadata
 *     （identity / purpose / entities / conversion / seo，受控词表）；
 *   - 三层 Validator（清单 / 配方 / 运行时）inspect 给出 errors / warnings / stats，
 *     坏 block、非法 source mode 被 ERROR 阻断；template:validate 命令全包通过；
 *   - 生命周期命令 activate（校验 + 骨架）/ bootstrap（默认值，空值才写）/ deactivate（幂等）；
 *   - 各包首页配方双语真实 HTTP 200，叙事（narrative）差异化；
 *   - Admin 模板中心：鉴权、index / compare 渲染、HTTP 激活与初始化。
 */
class TemplateEcosystem18L3bTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected User $super;

    private const PACKS = [
        'manufacturing-pro', 'saas-pro', 'commerce-pro', 'service-pro',
        'healthcare-pro', 'education-pro', 'construction-pro', 'export-pro',
    ];

    private const TITLES = [
        'manufacturing-pro' => ['值得信赖的制造合作伙伴', 'Your Trusted Manufacturing Partner'],
        'saas-pro'           => ['让业务更高效的智能平台', 'The Smart Platform That Scales Your Business'],
        'commerce-pro'       => ['用心做好每一件产品', 'Crafted With Care, Made for You'],
        'service-pro'        => ['专业团队，为你解决关键问题', 'Expert Teams Solving What Matters Most'],
        'healthcare-pro'     => ['以专业守护健康与信任', 'Professional Care for Health and Trust'],
        'education-pro'      => ['让每一次学习都有收获', 'Make Every Lesson Count'],
        'construction-pro'   => ['以匠心筑就每一个项目', 'Building Every Project With Craft'],
        'export-pro'         => ['值得信赖的出口合作伙伴', 'Your Reliable Export Partner'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        $this->seed(DefaultFormSeeder::class);
        $this->seed(SystemPageSeeder::class);

        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        $this->super = User::create([
            'name' => '超管',
            'email' => 'admin@example.com',
            'password' => bcrypt('Admin@123456'),
            'is_super_admin' => true,
        ]);

        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function homePage(string $locale): Page
    {
        return Page::where('is_home', true)->where('locale', $locale)->firstOrFail();
    }

    /** 在临时目录建一个清单有效但配方可定制的包，返回路径（测试后清理）。 */
    private function makeTempPack(array $homeRecipe): string
    {
        $tmp = sys_get_temp_dir() . '/geo-3btest-' . uniqid();
        @mkdir($tmp . '/recipes', 0777, true);

        $manifest = [
            'id' => basename($tmp),
            'name' => 'Temp Pack',
            'version' => '1.0.0',
            'industry' => ['saas-technology'],
            'locales' => ['zh-CN', 'en'],
            'theme' => 'default',
            'identity' => ['name' => 'Temp', 'industry' => 'saas-technology'],
            'purpose' => ['primary' => 'lead_generation'],
            'entities' => ['Organization'],
            'conversion' => ['primary' => 'inquiry'],
            'seo' => ['recommended_schema' => ['Organization']],
            'requires' => [
                'platform' => '1.0.0',
                'theme_api' => '1.0.0',
                'component_api' => '1.0.0',
                'geo_contract' => '1.0.0',
                'seo_contract' => '1.0.0',
                'components' => ['hero'],
            ],
            'pages' => ['home'],
            'author' => 'test',
            'license' => 'MIT',
        ];
        file_put_contents($tmp . '/manifest.json', json_encode($manifest));
        file_put_contents(
            $tmp . '/recipes/homepage.json',
            json_encode($homeRecipe, JSON_UNESCAPED_UNICODE)
        );

        return $tmp;
    }

    // ----------------------------------------------------------------
    // 1. 八大 Business OS 包发现 / Metadata
    // ----------------------------------------------------------------

    public function test_all_eight_packs_are_discovered_and_valid(): void
    {
        $all = TemplatePackageManager::all();
        $invalid = TemplatePackageManager::invalidPacks();

        foreach (self::PACKS as $pack) {
            $this->assertArrayHasKey($pack, $all, "缺少有效包：{$pack}");
            $this->assertArrayNotHasKey($pack, $invalid, "包不应无效：{$pack}");
            $this->assertTrue(TemplatePackageManager::exists($pack));
        }
    }

    public function test_each_pack_declares_ai_geo_metadata(): void
    {
        foreach (TemplatePackageManager::all() as $id => $m) {
            $this->assertNotEmpty($m['identity']['name'] ?? null, "{$id} identity.name");
            $this->assertNotEmpty($m['identity']['industry'] ?? null, "{$id} identity.industry");
            $this->assertNotEmpty($m['purpose']['primary'] ?? null, "{$id} purpose.primary");
            $this->assertNotEmpty($m['entities'] ?? [], "{$id} entities");
            $this->assertNotEmpty($m['conversion']['primary'] ?? null, "{$id} conversion.primary");
            $this->assertNotEmpty($m['seo']['recommended_schema'] ?? [], "{$id} seo schema");
        }
    }

    public function test_inspect_reports_zero_errors_and_warnings_for_all_packs(): void
    {
        foreach (self::PACKS as $pack) {
            $r = TemplateRecipeValidator::inspect(
                TemplatePackageManager::basePath() . '/' . $pack
            );
            $this->assertSame([], $r['errors'], "{$pack} errors: " . implode(';', $r['errors']));
            $this->assertSame([], $r['warnings'], "{$pack} warnings: " . implode(';', $r['warnings']));
        }
    }

    public function test_inspect_reports_recipe_and_block_stats(): void
    {
        $r = TemplateRecipeValidator::inspect(
            TemplatePackageManager::basePath() . '/manufacturing-pro'
        );

        $this->assertSame(4, $r['stats']['recipes']);
        $this->assertSame(15, $r['stats']['blocks']);
    }

    // ----------------------------------------------------------------
    // 2. 三层 Validator 阻断
    // ----------------------------------------------------------------

    public function test_validator_flags_unknown_block_in_recipe(): void
    {
        $tmp = $this->makeTempPack([
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [
                ['slot' => 'main', 'type' => 'not_a_real_block', 'content' => []],
            ],
        ]);

        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertNotEmpty($r['errors']);

        @unlink($tmp . '/recipes/homepage.json');
        @unlink($tmp . '/manifest.json');
        @rmdir($tmp . '/recipes');
        @rmdir($tmp);
    }

    public function test_validator_flags_invalid_source_mode(): void
    {
        $tmp = $this->makeTempPack([
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [
                ['slot' => 'main', 'type' => 'product_grid',
                    'content' => ['source' => ['mode' => 'banana'], 'limit' => 6]],
            ],
        ]);

        $r = TemplateRecipeValidator::inspect($tmp);
        $joined = implode(';', $r['errors']);
        $this->assertStringContainsString('mode', $joined);

        @unlink($tmp . '/recipes/homepage.json');
        @unlink($tmp . '/manifest.json');
        @rmdir($tmp . '/recipes');
        @rmdir($tmp);
    }

    public function test_validate_command_exits_zero_for_all_packs(): void
    {
        $this->artisan('template:validate')->assertExitCode(0);
    }

    // ----------------------------------------------------------------
    // 3. 生命周期命令
    // ----------------------------------------------------------------

    public function test_activate_command_validates_and_builds_skeleton(): void
    {
        $this->artisan('template:activate saas-pro')->assertExitCode(0);

        $this->assertSame('saas-pro', TemplatePackageManager::active());
        $this->assertSame(7, $this->homePage('zh-CN')->blocks()->where('slot', 'main')->count());
        $this->assertSame(7, $this->homePage('en')->blocks()->where('slot', 'main')->count());
    }

    public function test_bootstrap_command_applies_and_reports(): void
    {
        // 空值才落地：先清空，bootstrap 应写入包建议的双语。
        Setting::set('site_supported_locales', []);

        $this->artisan('template:bootstrap manufacturing-pro')->assertExitCode(0);

        $this->assertContains('en', Setting::get('site_supported_locales'));
    }

    public function test_bootstrap_skips_existing_values(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $tmp = sys_get_temp_dir() . '/geo-3bboot-' . uniqid();
        // 直接调 Installer 验证：已有值应进入 skipped。
        $report = \App\Support\Templates\TemplateDefaultsInstaller::bootstrap(
            $this->site, 'manufacturing-pro', false
        );

        $this->assertContains('site_supported_locales', $report['settings']['skipped']);
        $this->assertNotContains('site_supported_locales', $report['settings']['applied']);

        @rmdir($tmp);
    }

    public function test_deactivate_command_is_idempotent(): void
    {
        $this->artisan('template:activate export-pro')->assertExitCode(0);
        $this->artisan('template:deactivate')->assertExitCode(0);
        $this->assertSame('', TemplatePackageManager::active());

        // 再次停用不应报错。
        $this->artisan('template:deactivate')->assertExitCode(0);
    }

    // ----------------------------------------------------------------
    // 4. 叙事渲染（双语 200、差异化）
    // ----------------------------------------------------------------

    public function test_each_pack_homepage_renders_bilingual_200(): void
    {
        foreach (self::PACKS as $pack) {
            TemplatePackageManager::deactivate();
            $this->artisan("template:activate {$pack}")->assertExitCode(0);

            [$zhTitle, $enTitle] = self::TITLES[$pack];

            $zh = $this->get('http://localhost/')->assertOk()->getContent();
            $en = $this->get('http://localhost/en/')->assertOk()->getContent();

            $this->assertStringContainsString($zhTitle, $zh, "{$pack} zh title");
            $this->assertStringContainsString($enTitle, $en, "{$pack} en title");
            $this->assertStringNotContainsString($zhTitle, $en, "{$pack} zh leaked into en");
        }
    }

    public function test_packs_have_distinct_narratives(): void
    {
        $zhTitles = array_map(static fn ($pair) => $pair[0], self::TITLES);
        $this->assertSame(count($zhTitles), count(array_unique($zhTitles)));
    }

    // ----------------------------------------------------------------
    // 5. Admin 模板中心
    // ----------------------------------------------------------------

    public function test_admin_index_requires_auth_and_renders(): void
    {
        $this->get(route('admin.templates.index'))->assertRedirect();

        $this->actingAs($this->super)
            ->get(route('admin.templates.index'))
            ->assertOk()
            ->assertSee('Manufacturing OS Pro')
            ->assertSee('SaaS Technology OS Pro');
    }

    public function test_admin_compare_renders(): void
    {
        $this->actingAs($this->super)
            ->get(route('admin.templates.compare', 'saas-pro'))
            ->assertOk()
            ->assertSee('核心模板')
            ->assertSee('SaaS Technology OS Pro');
    }

    public function test_admin_preview_page_and_screenshot(): void
    {
        $this->get(route('admin.templates.preview', 'manufacturing-pro'))->assertRedirect();

        $this->actingAs($this->super)
            ->get(route('admin.templates.preview', 'manufacturing-pro'))
            ->assertOk()
            ->assertSee('Manufacturing OS Pro');

        $this->actingAs($this->super)
            ->get(route('admin.templates.screenshot', ['manufacturing-pro', 'desktop']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($this->super)
            ->get(route('admin.templates.screenshot', ['manufacturing-pro', 'mobile']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($this->super)
            ->get('/admin/templates/manufacturing-pro/preview/evil')
            ->assertNotFound();

        $this->actingAs($this->super)
            ->get(route('admin.templates.preview', 'no-such-pack'))
            ->assertNotFound();
    }

    public function test_admin_activate_and_bootstrap_via_http(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.templates.activate', 'saas-pro'))
            ->assertRedirect();

        $this->assertSame('saas-pro', TemplatePackageManager::active());

        $this->actingAs($this->super)
            ->post(route('admin.templates.bootstrap', 'saas-pro'))
            ->assertRedirect();

        $this->actingAs($this->super)
            ->post(route('admin.templates.deactivate'))
            ->assertRedirect();

        $this->assertSame('', TemplatePackageManager::active());
    }
}
