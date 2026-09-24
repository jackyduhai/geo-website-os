<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18F — Localization（zh-CN + English 前端）防回归。
 *
 * 一个测试文件覆盖六类契约：
 *   1) LocaleRouting  zh 默认 / en 前缀；站点未启用 en 时 /en 404；
 *   2) LocalizedSEO   canonical / og:locale 随语言；
 *   3) LocalizedSchema 页面 inLanguage 随语言；
 *   4) LocalizedSitemap 两语 sitemap 各自只列本语言 URL；
 *   5) LocalizedSearch  搜索只返回当前语言结果；
 *   6) MultiSite × Locale  en-only Site B：zh 404 / en 200 / 双向隔离。
 *
 * 注意：organization Entity 是 Catalog 读模型的根（company / production /
 * product_lines 等结构化数据的载体），任何产品 / 场景前台页都要求站点主体组织
 * Entity 存在，因此产品类用例先 makeSiteOrganization()。
 */
class Localization18FTest extends TestCase
{
    use RefreshDatabase;

    private Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed(DefaultSettingSeeder::class);
        Catalog::flush();
        PageCache::flush();
    }

    private function enableBilingual(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    private function makeSiteOrganization(bool $bilingual = false): Entity
    {
        $org = Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'site-organization',
            'name' => '示例公司',
            'summary' => '示例公司简介',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        if ($bilingual) {
            $org->createTranslation('en', [
                'slug' => 'site-organization',
                'name' => 'Example Company',
                'summary' => 'Example company profile',
                'description' => 'Example company profile',
            ]);
        }
        Catalog::flush();
        PageCache::flush();

        return $org;
    }

    private function makeCoreProduct(): Entity
    {
        $product = Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => 'demo-product',
            'name' => '演示产品',
            'summary' => '演示产品摘要',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['core' => true],
        ]);
        $product->createTranslation('en', [
            'slug' => 'demo-product',
            'name' => 'Demo Product',
            'summary' => 'Demo product summary',
            'description' => 'Demo product description',
        ]);
        Catalog::flush();
        PageCache::flush();

        return $product;
    }

    // ----------------------------------------------------------------
    // 1) LocaleRouting
    // ----------------------------------------------------------------

    public function test_zh_home_is_default_ok(): void
    {
        $r = $this->get('/');
        $r->assertOk();
        $this->assertStringContainsString('<html lang="zh-CN"', $r->getContent());
    }

    public function test_en_home_404_when_language_not_enabled(): void
    {
        $this->get('/en')->assertNotFound();
    }

    public function test_en_home_ok_when_enabled(): void
    {
        $this->enableBilingual();
        $r = $this->get('/en');
        $r->assertOk();
        $this->assertStringContainsString('<html lang="en"', $r->getContent());
    }

    // ----------------------------------------------------------------
    // 2) LocalizedSEO — canonical / og:locale
    // ----------------------------------------------------------------

    public function test_product_canonical_and_og_locale_are_localized(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        $zh = $this->get('/products/demo-product');
        $zh->assertOk();
        $zhc = $zh->getContent();
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/products/demo-product"', $zhc);
        $this->assertStringContainsString(
            '<meta property="og:locale" content="zh_CN"', $zhc);

        $en = $this->get('/en/products/demo-product');
        $en->assertOk();
        $enc = $en->getContent();
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/en/products/demo-product"', $enc);
        $this->assertStringContainsString(
            '<meta property="og:locale" content="en_US"', $enc);
    }

    // ----------------------------------------------------------------
    // 2b) Hreflang — 每个 locale 的对等链接指向其自身 URL（不被当前请求
    //     locale 污染；英文页 zh-CN 不得错误带 /en 前缀）
    // ----------------------------------------------------------------

    public function test_hreflang_alternates_point_to_each_locale_own_url(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        // 首页：中文与英文视图输出的 hreflang 集合必须完全一致、各自正确。
        // zh-CN / x-default 指向根（带尾斜杠），en 指向 /en（无尾斜杠）。
        $expectHome = [
            'zh-CN' => 'http://localhost/',
            'en' => 'http://localhost/en',
            'x-default' => 'http://localhost/',
        ];
        foreach (['/', '/en'] as $url) {
            preg_match_all(
                '/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"\/?>/',
                $this->get($url)->getContent(), $mm, PREG_SET_ORDER);
            $map = [];
            foreach ($mm as $x) { $map[$x[1]] = $x[2]; }
            $this->assertSame($expectHome, $map, "home {$url} hreflang mismatch");
        }

        // 英文产品详情：zh-CN / x-default 必须指向「中文详情」（无 /en），
        // en 指向英文详情。
        preg_match_all(
            '/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"\/?>/',
            $this->get('/en/products/demo-product')->getContent(), $dm, PREG_SET_ORDER);
        $dmap = [];
        foreach ($dm as $x) { $dmap[$x[1]] = $x[2]; }
        $this->assertSame('http://localhost/products/demo-product', $dmap['zh-CN']);
        $this->assertSame('http://localhost/en/products/demo-product', $dmap['en']);
        $this->assertSame('http://localhost/products/demo-product', $dmap['x-default']);
    }

    // ----------------------------------------------------------------
    // 3) LocalizedSchema — inLanguage
    // ----------------------------------------------------------------

    public function test_product_page_inlanguage_is_localized(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        $zhc = $this->get('/products/demo-product')->getContent();
        $this->assertStringContainsString('"inLanguage":"zh-CN"', $zhc);
        $this->assertStringNotContainsString('"inLanguage":"en"', $zhc);

        $enc = $this->get('/en/products/demo-product')->getContent();
        $this->assertStringContainsString('"inLanguage":"en"', $enc);
        $this->assertStringNotContainsString('"inLanguage":"zh-CN"', $enc);
    }

    // ----------------------------------------------------------------
    // 4) LocalizedSitemap
    // ----------------------------------------------------------------

    public function test_sitemaps_list_only_their_locale_urls(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        $zh = $this->get('/sitemap.xml');
        $zh->assertOk();
        $zhc = $zh->getContent();
        $this->assertStringContainsString(
            '<loc>http://localhost/products/demo-product</loc>', $zhc);
        $this->assertStringNotContainsString('/en/products', $zhc);
        // 首页 loc：默认语言为无尾斜杠根地址
        $this->assertStringContainsString('<loc>http://localhost</loc>', $zhc);

        $en = $this->get('/en/sitemap.xml');
        $en->assertOk();
        $enc = $en->getContent();
        $this->assertStringContainsString(
            '<loc>http://localhost/en/products/demo-product</loc>', $enc);
        $this->assertStringNotContainsString(
            '<loc>http://localhost/products/demo-product</loc>', $enc);
        // 英文 sitemap 首页 loc 必须是英文首页 /en，不得泄漏无 /en 的根地址
        $this->assertStringContainsString('<loc>http://localhost/en</loc>', $enc);
        $this->assertStringNotContainsString('<loc>http://localhost</loc>', $enc);
    }

    // ----------------------------------------------------------------
    // 5) LocalizedSearch
    // ----------------------------------------------------------------

    public function test_search_returns_only_current_locale_results(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        $en = $this->get('/en/search?q=' . urlencode('Demo'));
        $en->assertOk();
        $enc = $en->getContent();
        $this->assertStringContainsString('Demo Product', $enc);
        $this->assertStringNotContainsString('演示产品', $enc);

        $zh = $this->get('/search?q=' . urlencode('演示'));
        $zh->assertOk();
        $this->assertStringContainsString('演示产品', $zh->getContent());
    }

    // ----------------------------------------------------------------
    // 7) LocalizedLinks — en 页所有站内链接保持 /en（TD-51 防回归）
    // ----------------------------------------------------------------

    public function test_en_internal_links_keep_en_prefix(): void
    {
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        foreach (['/en', '/en/products/'] as $url) {
            $html = $this->get($url)->getContent();
            preg_match_all('/<a\b[^>]*?href="(http:\/\/localhost[^"]*)"[^>]*>/', $html, $mm, PREG_SET_ORDER);
            $bad = [];
            foreach ($mm as $x) {
                $tag = $x[0];
                $href = $x[1];
                if (str_contains($tag, 'ls-link')) {
                    continue; // 语言切换器指向另一语言，合法
                }
                $path = (string) parse_url($href, PHP_URL_PATH);
                if (! str_starts_with($path, '/en')) {
                    $bad[] = $href;
                }
            }
            $this->assertSame([], $bad, "en page {$url} has non-/en internal links: " . implode(', ', $bad));
        }
    }

    // ----------------------------------------------------------------
    // 6) MultiSite × Locale — en-only Site B
    // ----------------------------------------------------------------

    public function test_en_only_site_is_isolated_and_zh_routes_404(): void
    {
        // A is bilingual and holds the site organization + demo product.
        $this->enableBilingual();
        $this->makeSiteOrganization(true);
        $this->makeCoreProduct();

        // Build an independent en-only Site B.
        $b = Site::create([
            'slug' => 'acme',
            'name' => 'Acme Global',
            'domain' => 'acme.test',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'description' => 'Acme Global corporate website.',
            'metadata' => ['organization' => 'Acme Global'],
        ]);
        SiteContext::setSite($b);
        $this->seed(DefaultSettingSeeder::class);
        Setting::set('site_supported_locales', ['en']);
        Setting::set('site_default_locale', 'en');
        Setting::set('geo_org_name', 'Acme Global Ltd');
        Setting::set('geo_org_en_name', 'Acme Global');
        Entity::create([
            'site_id' => $b->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'acme-global',
            'name' => 'Acme Global',
            'summary' => 'Acme Global corporate website.',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        Catalog::flush();
        PageCache::flush();

        // B zh routes 404 (language not provided).
        $this->get('http://acme.test/')->assertNotFound();
        $this->get('http://acme.test/sitemap.xml')->assertNotFound();

        // B en routes 200 with B identity.
        $bHome = $this->get('http://acme.test/en');
        $bHome->assertOk();
        $bhc = $bHome->getContent();
        $this->assertStringContainsString('Acme Global', $bhc);
        $this->assertStringNotContainsString('演示产品', $bhc);
        $this->assertStringNotContainsString('Demo Product', $bhc);

        $bSm = $this->get('http://acme.test/en/sitemap.xml');
        $bSm->assertOk();
        $bsc = $bSm->getContent();
        $this->assertStringContainsString('acme.test/en', $bsc);
        $this->assertStringNotContainsString('demo-product', $bsc);

        // A never sees B.
        $aHome = $this->get('http://localhost/');
        $aHome->assertOk();
        $this->assertStringNotContainsString('Acme Global', $aHome->getContent());
    }
}
