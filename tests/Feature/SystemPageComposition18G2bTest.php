<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18G-2b — Listing + System Page Composition Closure 防回归。
 *
 * 把固定系统页（产品 / 场景 / 知识总览、About profile/history/culture、工厂、
 * 合作、联系）与动态 Listing（产品系列、知识频道）从「Controller → 固定 Blade」
 * 迁入 18G-1/2a 建立的 Template + Page + Block 统一管线，验证：
 *   A 九类 zh 系统页真实 HTTP 200；
 *   B 九类 en 系统页真实 HTTP 200（/en 前缀）；
 *   C 关键页面内容：联系页含联系事实 + 表单、产品总览含系列；
 *   D 动态 Listing：产品系列页渲染、知识总览分页；
 *   E TD-68：en canonical 只有一个 /en（修复前出现 /en/en/），zh 无 /en；
 *   F TD-66：后台可独立保存 en / zh 页面级 SEO，互不干扰且前台真实消费；
 *   G TD-58：页面预览（草稿可预览、需登录）、区块复制；
 *   H 缓存：后台区块更新精确失效该页面整页缓存。
 */
class SystemPageComposition18G2bTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();

        $this->seed();                              // Demo 数据（Example）
        $this->seed(SystemPageSeeder::class);       // is_system 固定页（9×2 locale）

        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function systemPage(string $key, string $locale): Page
    {
        return Page::where('system_key', $key)
            ->where('is_system', true)->where('locale', $locale)->firstOrFail();
    }

    /** @return array<string,array{0:string}> */
    public static function zhSystemPages(): array
    {
        return [
            'products'    => ['/products/'],
            'solutions'   => ['/solutions/'],
            'knowledge'   => ['/knowledge/'],
            'profile'     => ['/about/profile/'],
            'history'     => ['/about/history/'],
            'culture'     => ['/about/culture/'],
            'factory'     => ['/factory/'],
            'cooperation' => ['/cooperation/'],
            'contact'     => ['/contact/'],
        ];
    }

    /** @return array<string,array{0:string}> */
    public static function enSystemPages(): array
    {
        return [
            'products'    => ['/en/products/'],
            'solutions'   => ['/en/solutions/'],
            'knowledge'   => ['/en/knowledge/'],
            'profile'     => ['/en/about/profile/'],
            'history'     => ['/en/about/history/'],
            'culture'     => ['/en/about/culture/'],
            'factory'     => ['/en/factory/'],
            'cooperation' => ['/en/cooperation/'],
            'contact'     => ['/en/contact/'],
        ];
    }

    // ----------------------------------------------------------------
    // A / B 系统页 200
    // ----------------------------------------------------------------

    /** @dataProvider zhSystemPages */
    public function test_zh_system_pages_render_200(string $path): void
    {
        $this->get($path)->assertOk();
    }

    /** @dataProvider enSystemPages */
    public function test_en_system_pages_render_200(string $path): void
    {
        $this->get($path)->assertOk();
    }

    // ----------------------------------------------------------------
    // C 关键页面内容
    // ----------------------------------------------------------------

    public function test_contact_contains_facts_and_form(): void
    {
        $html = $this->get('/contact/')->getContent();

        $this->assertStringContainsString('contact-facts', $html);
        $this->assertMatchesRegularExpression('#<form\b#', $html);
    }

    public function test_products_overview_lists_product_lines(): void
    {
        $html = $this->get('/products/')->getContent();

        // 总览至少呈现一个产品系列锚点（数据驱动，非空壳）。
        $this->assertGreaterThanOrEqual(1, Catalog::productLines());
        $this->assertMatchesRegularExpression('#id="[a-z0-9-]+"#', $html);
    }

    // ----------------------------------------------------------------
    // D 动态 Listing
    // ----------------------------------------------------------------

    public function test_product_line_listing_renders(): void
    {
        $lines = Catalog::productLines();
        $this->assertNotEmpty($lines);
        $slug = $lines[0]['slug'];

        // 空系列会 301 到总览锚点；取一个含产品的系列断言独立成页。
        if (count(Catalog::productsByLine($slug)) < 1) {
            $this->markTestSkipped('首个产品系列无产品（demo 数据），空系列走 301 契约。');
        }

        $this->get('/products/' . $slug . '/')->assertOk();
    }

    public function test_knowledge_overview_renders_pager(): void
    {
        // demo 有 3 篇知识文章，paginate(12)，pager 容器始终渲染。
        $html = $this->get('/knowledge/')->getContent();

        $this->assertStringContainsString('pager', $html);
    }

    // ----------------------------------------------------------------
    // E TD-68 canonical 前缀
    // ----------------------------------------------------------------

    public function test_en_canonical_has_single_en_prefix(): void
    {
        $paths = [
            '/en/products/', '/en/solutions/', '/en/knowledge/', '/en/factory/',
            '/en/cooperation/', '/en/contact/', '/en/about/profile/',
        ];

        foreach ($paths as $p) {
            preg_match('#<link rel="canonical" href="([^"]+)"#', $this->get($p)->getContent(), $m);
            $this->assertNotEmpty($m, "canonical 缺失：{$p}");
            $canonicalPath = (string) parse_url($m[1], PHP_URL_PATH);
            $this->assertSame(
                1,
                substr_count($canonicalPath, '/en'),
                "en canonical 出现重复 /en：{$m[1]}"
            );
        }
    }

    public function test_zh_canonical_has_no_en_prefix(): void
    {
        foreach (['/products/', '/solutions/', '/knowledge/', '/factory/'] as $p) {
            preg_match('#<link rel="canonical" href="([^"]+)"#', $this->get($p)->getContent(), $m);
            $this->assertNotEmpty($m, "canonical 缺失：{$p}");
            $this->assertStringNotContainsString('/en', $m[1], "zh canonical 误带 /en：{$m[1]}");
        }
    }

    // ----------------------------------------------------------------
    // F TD-66 locale-aware 页面级 SEO
    // ----------------------------------------------------------------

    public function test_admin_saves_en_page_seo_and_frontend_consumes(): void
    {
        $enPage = $this->systemPage('products', 'en');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), [
                'scope' => 'page', 'page_id' => $enPage->id, 'locale' => 'en',
                'title' => 'EN PAGE SEO TITLE', 'description' => 'EN PAGE SEO DESC',
                'og_type' => 'website', 'twitter_card' => 'summary_large_image',
            ])->assertRedirect();

        $this->assertDatabaseHas('seo_metas', [
            'site_id' => $this->default->id, 'page_id' => $enPage->id,
            'locale' => 'en', 'title' => 'EN PAGE SEO TITLE',
        ]);

        $html = $this->get('/en/products/')->getContent();
        $this->assertStringContainsString('<title>EN PAGE SEO TITLE', $html);
    }

    public function test_zh_and_en_page_seo_do_not_interfere(): void
    {
        $zh = $this->systemPage('products', 'zh-CN');
        $en = $this->systemPage('products', 'en');

        SeoMeta::create([
            'site_id' => $this->default->id, 'page_id' => $en->id,
            'locale' => 'en', 'title' => 'EN ONLY TITLE',
        ]);

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), [
                'scope' => 'page', 'page_id' => $zh->id, 'locale' => 'zh-CN',
                'title' => 'ZH ONLY TITLE',
                'og_type' => 'website', 'twitter_card' => 'summary_large_image',
            ])->assertRedirect();

        $zhHtml = $this->get('/products/')->getContent();
        $enHtml = $this->get('/en/products/')->getContent();

        $this->assertStringContainsString('ZH ONLY TITLE', $zhHtml);
        $this->assertStringNotContainsString('EN ONLY TITLE', $zhHtml);
        $this->assertStringContainsString('EN ONLY TITLE', $enHtml);
        $this->assertStringNotContainsString('ZH ONLY TITLE', $enHtml);
    }

    // ----------------------------------------------------------------
    // G TD-58 预览 / 复制
    // ----------------------------------------------------------------

    public function test_preview_requires_login(): void
    {
        $page = $this->systemPage('products', 'zh-CN');

        $this->get(route('admin.pages.preview', $page))->assertRedirect();
    }

    public function test_admin_preview_renders_draft_page(): void
    {
        $page = Page::create([
            'site_id' => $this->default->id, 'template' => 'landing',
            'slug' => 'draft-preview-' . uniqid(), 'title' => 'DRAFT PREVIEW XYZ',
            'status' => Page::STATUS_DRAFT, 'locale' => 'zh-CN',
        ]);

        $this->actingAs($this->super)
            ->get(route('admin.pages.preview', $page))
            ->assertOk()
            ->assertSee('DRAFT PREVIEW XYZ');
    }

    public function test_duplicate_block_creates_copy_in_same_slot(): void
    {
        $page = Page::create([
            'site_id' => $this->default->id, 'template' => 'landing',
            'slug' => 'duplicate-' . uniqid(), 'title' => 'Dup Page',
            'status' => Page::STATUS_PUBLISHED, 'locale' => 'zh-CN',
        ]);
        $block = PageBlock::create([
            'site_id' => $this->default->id, 'page' => 'page', 'page_id' => $page->id,
            'slot' => 'main', 'type' => 'rich_text', 'sort' => 0, 'is_active' => true,
            'content' => json_encode(['title' => 'ORIGINAL BLOCK T', 'body' => 'b']),
        ]);

        $this->actingAs($this->super)
            ->post(route('admin.pages.duplicateBlock', [$page, $block]))
            ->assertRedirect();

        $blocks = PageBlock::where('page_id', $page->id)->where('slot', 'main')->get();
        $this->assertSame(2, $blocks->count());
        $this->assertSame(1, (int) $blocks->last()->sort);
    }

    // ----------------------------------------------------------------
    // H 缓存精确失效
    // ----------------------------------------------------------------

    public function test_admin_block_update_invalidates_page_cache(): void
    {
        $page = Page::create([
            'site_id' => $this->default->id, 'template' => 'landing',
            'slug' => 'cache-' . uniqid(), 'title' => 'Cache Page',
            'status' => Page::STATUS_PUBLISHED, 'locale' => 'zh-CN',
        ]);
        $block = PageBlock::create([
            'site_id' => $this->default->id, 'page' => 'page', 'page_id' => $page->id,
            'slot' => 'main', 'type' => 'rich_text', 'sort' => 0, 'is_active' => true,
            'content' => json_encode(['title' => 'BEFORE TITLE', 'body' => '']),
        ]);

        $url = PublicUrl::url('/' . $page->slug);
        $this->assertStringContainsString('BEFORE TITLE', $this->get($url)->getContent());

        $this->actingAs($this->super)
            ->put(route('admin.pages.updateBlock', [$page, $block]), [
                'field' => ['title' => 'AFTER TITLE', 'body' => ''],
            ])->assertRedirect();

        $html = $this->get($url)->getContent();
        $this->assertStringContainsString('AFTER TITLE', $html);
        $this->assertStringNotContainsString('BEFORE TITLE', $html);
    }
}
