<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 17G / #113 — Feed Public Render Contract（防回归）。
 *
 * 三 host 实测矩阵暴露出三类问题，本测试将其固化为契约：
 *   1. 最小字段实体（后台新建、metadata 缺键）导致前台 / feed 500；
 *   2. sitemap / llms / geo / rss / search 收录会 404、500、noindex、
 *      停用栏目、draft、非 core、跨站的 URL（DB 有 → feed 有 → 前台 404）；
 *   3. geo / canonical 使用错误单数 URL（/product/、/service/）。
 *
 * 核心准入规则（Public Render Contract）：
 *   任何进入 sitemap / geo.json / llms.txt / feed.xml / search 的公开资源，
 *   必须 Published + 当前 Site 可见 + 未 noindex（内容还要求栏目启用）+
 *   Canonical 有效 + 前台 HTTP 200。无前台落地页的实体是合法图谱节点，
 *   但不得输出会 404 的 url。
 *
 * 三站点：
 *   A (a.test)  完整目录站：org(含 production/cooperation) + core/noncore/draft/
 *               noindex 产品 + service + 可见/noindex 知识文章 + 停用栏目文章。
 *   B (b.test)  完全空站：feed 必须安全空集，业务页 404，不得泄漏 A 的任何资源。
 *   C (c.test)  最小 org 站（仅公司名，无 production/cooperation）：
 *               /factory//cooperation/ 必须 404（不是 500），about/contact 200。
 */
class FeedPublicRenderContractTest extends TestCase
{
    use RefreshDatabase;

    private Site $a;
    private Site $b;
    private Site $c;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();
        // 严格多站：未匹配 Host 不回退 default，保证 host ↔ site 一一对应。
        config(['site.default_fallback' => false]);

        $this->a = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->a->update(['name' => 'Site A', 'domain' => 'a.test', 'status' => 'active']);

        $this->b = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->c = Site::create([
            'name' => 'Site C', 'slug' => 'site-c', 'domain' => 'c.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $this->seedSiteC();
        $this->seedSiteA();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        SiteContext::clear();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedSiteA(): void
    {
        SiteContext::withSite($this->a, function () {
            $now = now();

            // 组织主体：带 production（hasProduction）与 cooperation（hasCooperation），
            // 使 /products//solutions//factory//cooperation//about//contact 全部成页。
            Entity::create([
                'site_id' => $this->a->id,
                'type' => 'organization',
                'slug' => 'acme-org',
                'name' => 'Acme Example',
                'summary' => 'Acme example organization.',
                'status' => 'published',
                'published_at' => $now,
                'sort_order' => 0,
                'metadata' => [
                    'company' => [
                        'name' => 'Acme Example',
                        'brand' => 'Acme',
                        'industry' => 'Industrial materials',
                        'area_sqm' => 1200,
                        'annual_capacity_tons' => 500,
                        'address' => ['full' => '1 Example Way'],
                    ],
                    'production' => [
                        'workshops' => [
                            ['id' => 'w1', 'name' => 'Workshop One', 'desc' => '', 'image' => null, 'image_alt' => ''],
                        ],
                        'sales_regions' => ['Global'],
                    ],
                    'cooperation' => [
                        'types' => [
                            ['id' => 'oem', 'name' => 'OEM', 'fit' => '', 'includes' => [], 'cta' => ''],
                        ],
                        'process' => [
                            ['step' => 1, 'name' => 'Inquiry', 'desc' => ''],
                        ],
                    ],
                ],
            ]);

            // 核心产品：有独立详情页 /products/{slug}
            Entity::create([
                'site_id' => $this->a->id, 'type' => 'product', 'slug' => 'core-product',
                'name' => 'Core Product', 'summary' => 'A core product with a detail page.',
                'status' => 'published', 'published_at' => $now, 'sort_order' => 1,
                'metadata' => ['core' => true, 'short_name' => 'Core', 'tagline' => 'Core tagline'],
            ]);
            // 非核心产品：published 但是图谱节点，无详情页，不得输出 url / 进 sitemap
            Entity::create([
                'site_id' => $this->a->id, 'type' => 'product', 'slug' => 'noncore-product',
                'name' => 'Noncore Product', 'summary' => 'No standalone detail page.',
                'status' => 'published', 'published_at' => $now, 'sort_order' => 2,
                'metadata' => ['core' => false],
            ]);
            // draft 核心产品：任何 feed 都不得出现
            Entity::create([
                'site_id' => $this->a->id, 'type' => 'product', 'slug' => 'draft-product',
                'name' => 'Draft Product', 'status' => 'draft', 'sort_order' => 3,
                'metadata' => ['core' => true],
            ]);
            // noindex 核心产品：详情仍 200，但不进任何 feed
            $noindexProduct = Entity::create([
                'site_id' => $this->a->id, 'type' => 'product', 'slug' => 'noindex-product',
                'name' => 'Noindex Product', 'summary' => 'Renderable but excluded from feeds.',
                'status' => 'published', 'published_at' => $now, 'sort_order' => 4,
                'metadata' => ['core' => true],
            ]);
            SeoMeta::create([
                'site_id' => $this->a->id, 'entity_id' => $noindexProduct->id,
                'noindex' => true, 'title' => 'Noindex Product',
            ]);

            // 服务场景：/solutions/{slug}/
            Entity::create([
                'site_id' => $this->a->id, 'type' => 'service', 'slug' => 'a-service',
                'name' => 'A Service Scene', 'summary' => 'A renderable service scene.',
                'status' => 'published', 'published_at' => $now, 'sort_order' => 5,
                'metadata' => ['order' => 1, 'combo' => []],
            ]);

            // 知识栏目（启用）+ 可见文章 / noindex 文章
            $knowledge = Category::create([
                'site_id' => $this->a->id, 'type' => 'list', 'slug' => 'knowledge',
                'name' => 'Knowledge', 'is_active' => true, 'parent_id' => null,
                'is_nav' => true, 'sort' => 1,
            ]);
            Content::create([
                'site_id' => $this->a->id, 'category_id' => $knowledge->id, 'type' => 'article',
                'slug' => 'visible-article', 'title' => 'Visible Knowledge Article',
                'summary' => 'Visible article summary.', 'body' => 'Visible body.',
                'status' => 'published', 'published_at' => $now,
            ]);
            $noindexArticle = Content::create([
                'site_id' => $this->a->id, 'category_id' => $knowledge->id, 'type' => 'article',
                'slug' => 'noindex-article', 'title' => 'Noindex Article',
                'summary' => 'Marked noindex.', 'body' => 'Noindex body.',
                'status' => 'published', 'published_at' => $now,
            ]);
            SeoMeta::create([
                'site_id' => $this->a->id, 'content_id' => $noindexArticle->id,
                'noindex' => true, 'title' => 'Noindex Article',
            ]);

            // 停用栏目 + 其下已发布文章：不进任何 feed / search
            $inactive = Category::create([
                'site_id' => $this->a->id, 'type' => 'list', 'slug' => 'inactive-cat',
                'name' => 'Inactive', 'is_active' => false, 'parent_id' => null,
                'is_nav' => false, 'sort' => 99,
            ]);
            Content::create([
                'site_id' => $this->a->id, 'category_id' => $inactive->id, 'type' => 'article',
                'slug' => 'inactive-cat-article', 'title' => 'Inactive Cat Article',
                'summary' => 'Under a disabled category.', 'body' => 'Inactive body.',
                'status' => 'published', 'published_at' => $now,
            ]);
        });
    }

    private function seedSiteC(): void
    {
        SiteContext::withSite($this->c, function () {
            // 仅公司名的最小 organization：无 production、无 cooperation。
            Entity::create([
                'site_id' => $this->c->id,
                'type' => 'organization',
                'slug' => 'minimal-org',
                'name' => 'Example Minimal Co',
                'summary' => 'A minimal example organization.',
                'status' => 'published',
                'published_at' => now(),
                'sort_order' => 0,
                'metadata' => ['company' => ['name' => 'Example Minimal Co']],
            ]);
        });
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function getOn(string $host, string $path)
    {
        return $this->get('http://' . $host . $path);
    }

    /** @return string[] absolute URLs found in sitemap.xml <loc> */
    private function sitemapUrls(string $host): array
    {
        $xml = $this->getOn($host, '/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);

        return $m[1];
    }

    /** @return string[] absolute markdown / bare URLs found in llms.txt */
    private function llmsUrls(string $host): array
    {
        $txt = $this->getOn($host, '/llms.txt')->assertOk()->getContent();
        preg_match_all('~https?://' . preg_quote($host, '~') . '([^)\s#]*)~', $txt, $m);
        $paths = array_unique($m[1]);
        $urls = [];
        foreach ($paths as $p) {
            $urls[] = 'http://' . $host . $p;
        }

        return $urls;
    }

    private function geo(string $host): array
    {
        return json_decode(
            $this->getOn($host, '/geo.json')->assertOk()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function assertPathReachable(string $host, string $absoluteUrl): void
    {
        $path = parse_url($absoluteUrl, PHP_URL_PATH) ?: '/';
        $this->getOn($host, $path)->assertOk("feed 收录的 URL 必须前台 200: {$absoluteUrl}");
    }

    // ---------------------------------------------------------------
    // 1. Sitemap 收录的每个 URL 必须前台 200（最强契约，覆盖 500/404 泄漏）
    // ---------------------------------------------------------------

    public function test_sitemap_every_listed_url_returns_200_on_full_site(): void
    {
        foreach ($this->sitemapUrls('a.test') as $loc) {
            $this->assertPathReachable('a.test', $loc);
        }
    }

    public function test_sitemap_every_listed_url_returns_200_on_minimal_org_site(): void
    {
        foreach ($this->sitemapUrls('c.test') as $loc) {
            $this->assertPathReachable('c.test', $loc);
        }
    }

    public function test_sitemap_every_listed_url_returns_200_on_empty_site(): void
    {
        foreach ($this->sitemapUrls('b.test') as $loc) {
            $this->assertPathReachable('b.test', $loc);
        }
    }

    // ---------------------------------------------------------------
    // 2. Feed 准入：只收 indexable 资源
    // ---------------------------------------------------------------

    public function test_sitemap_excludes_non_renderable_and_noindex_resources(): void
    {
        $xml = $this->getOn('a.test', '/sitemap.xml')->getContent();

        // 必须收录：核心产品、服务场景、可见知识文章
        $this->assertStringContainsString('/products/core-product', $xml);
        $this->assertStringContainsString('/solutions/a-service', $xml);
        $this->assertStringContainsString('/knowledge/visible-article', $xml);
        $this->assertStringContainsString('/factory/', $xml);
        $this->assertStringContainsString('/cooperation/', $xml);

        // 不得收录：非 core / draft / noindex 产品、noindex / 停用栏目文章
        foreach (['noncore-product', 'draft-product', 'noindex-product',
                      'noindex-article', 'inactive-cat-article', 'inactive-cat'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $xml, "sitemap 不得收录 {$forbidden}");
        }
    }

    public function test_llms_excludes_non_renderable_and_noindex_resources(): void
    {
        $txt = $this->getOn('a.test', '/llms.txt')->getContent();

        $this->assertStringContainsString('/products/core-product', $txt);
        $this->assertStringContainsString('/solutions/a-service', $txt);
        $this->assertStringContainsString('/knowledge/visible-article', $txt);

        foreach (['noncore-product', 'draft-product', 'noindex-product',
                      'noindex-article', 'inactive-cat-article'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $txt, "llms 不得收录 {$forbidden}");
        }

        // llms 列出的每个链接必须可达
        foreach ($this->llmsUrls('a.test') as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $this->getOn('a.test', $path)->assertOk("llms 收录的 URL 必须前台 200: {$url}");
        }
    }

    public function test_rss_excludes_noindex_and_inactive_category_articles(): void
    {
        $rss = $this->getOn('a.test', '/feed.xml')->assertOk()->getContent();

        $this->assertStringContainsString('visible-article', $rss);
        $this->assertStringNotContainsString('noindex-article', $rss);
        $this->assertStringNotContainsString('inactive-cat-article', $rss);
    }

    public function test_search_excludes_noindex_and_inactive_category_articles(): void
    {
        $html = $this->getOn('a.test', '/search?q=article')->assertOk()->getContent();

        // 标题 / 摘要经 <mark> 搜索词高亮（18H-1）：去标签后断言文本连续，并确认高亮生效。
        $this->assertStringContainsString('Visible Knowledge Article', strip_tags($html));
        $this->assertStringContainsString('<mark>Article</mark>', $html);
        $this->assertStringNotContainsString('Noindex Article', $html);
        $this->assertStringNotContainsString('Inactive Cat Article', $html);
    }

    // ---------------------------------------------------------------
    // 3. geo.json：仅可渲染实体输出 url，且 url 必须可达
    // ---------------------------------------------------------------

    public function test_geo_entity_url_only_for_renderable_entities_and_reachable(): void
    {
        $geo = $this->geo('a.test');
        $bySlug = [];
        foreach ($geo['entities'] as $node) {
            $bySlug[$node['slug']] = $node;
        }

        // 核心产品：有 url，且为复数 /products/{slug}（无尾斜杠），前台 200
        $this->assertArrayHasKey('core-product', $bySlug);
        $this->assertSame('https://a.test/products/core-product', $bySlug['core-product']['url']);
        $this->assertPathReachable('a.test', $bySlug['core-product']['url']);

        // 服务场景：有 url，/solutions/{slug}/（带尾斜杠）
        $this->assertArrayHasKey('a-service', $bySlug);
        $this->assertSame('https://a.test/solutions/a-service/', $bySlug['a-service']['url']);
        $this->assertPathReachable('a.test', $bySlug['a-service']['url']);

        // 组织 / 非核心产品：合法图谱节点但无落地页 → 不得输出 url
        $this->assertArrayHasKey('acme-org', $bySlug);
        $this->assertArrayNotHasKey('url', $bySlug['acme-org']);
        $this->assertArrayHasKey('noncore-product', $bySlug);
        $this->assertArrayNotHasKey('url', $bySlug['noncore-product']);

        // draft / noindex 实体不得进入图谱
        $this->assertArrayNotHasKey('draft-product', $bySlug);
        $this->assertArrayNotHasKey('noindex-product', $bySlug);
    }

    public function test_geo_contents_only_indexable_and_urls_reachable(): void
    {
        $geo = $this->geo('a.test');
        $slugs = array_column($geo['contents'], 'slug');

        $this->assertContains('visible-article', $slugs);
        $this->assertNotContains('noindex-article', $slugs);
        $this->assertNotContains('inactive-cat-article', $slugs);

        foreach ($geo['contents'] as $node) {
            $this->assertArrayHasKey('url', $node);
            $this->assertSame('https://a.test/knowledge/visible-article', $node['url']);
            $this->assertFalse($node['noindex']);
            $this->assertPathReachable('a.test', $node['url']);
        }
    }

    // ---------------------------------------------------------------
    // 4. 空站 B：feed 安全空集，业务页 404，不跨站泄漏
    // ---------------------------------------------------------------

    public function test_empty_site_feeds_are_safe_and_catalog_pages_404(): void
    {
        // 首页与知识总览仍可渲染（不依赖 Catalog）
        $this->getOn('b.test', '/')->assertOk();
        $this->getOn('b.test', '/knowledge/')->assertOk();

        // 目录类业务页：空站一律 404（不是 500）
        foreach (['/products/', '/solutions/', '/factory/', '/cooperation/',
                      '/about/profile/', '/contact/'] as $p) {
            $this->getOn('b.test', $p)->assertNotFound("空站 {$p} 必须 404");
        }

        $geo = $this->geo('b.test');
        $this->assertSame([], $geo['entities']);
        $this->assertSame([], $geo['contents']);
        $this->assertSame([], $geo['relations']);

        $sitemap = $this->getOn('b.test', '/sitemap.xml')->getContent();
        foreach (['/products/', '/solutions/', '/factory/', '/cooperation/',
                      '/about/', '/contact/'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sitemap);
        }

        $llms = $this->getOn('b.test', '/llms.txt')->assertOk()->getContent();
        foreach (['/products/', '/factory/', '/cooperation/'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $llms);
        }
    }

    // ---------------------------------------------------------------
    // 5. 最小 org 站 C：factory/cooperation 实质 404（非 500），about/contact 200
    // ---------------------------------------------------------------

    public function test_minimal_org_site_404s_factory_cooperation_but_renders_about_contact(): void
    {
        $this->getOn('c.test', '/')->assertOk();
        $this->getOn('c.test', '/factory/')->assertNotFound();
        $this->getOn('c.test', '/cooperation/')->assertNotFound();
        $this->getOn('c.test', '/about/profile/')->assertOk();
        $this->getOn('c.test', '/contact/')->assertOk();

        $sitemap = $this->getOn('c.test', '/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/factory/', $sitemap);
        $this->assertStringNotContainsString('/cooperation/', $sitemap);
        $this->assertStringContainsString('/about/profile/', $sitemap);
        $this->assertStringContainsString('/contact/', $sitemap);

        // llms 不得输出 factory / cooperation 链接，也不得拼"0 车间 / 年产能"空壳
        $llms = $this->getOn('c.test', '/llms.txt')->getContent();
        $this->assertStringNotContainsString('/factory/', $llms);
        $this->assertStringNotContainsString('/cooperation/', $llms);
    }

    // ---------------------------------------------------------------
    // 6. 跨站隔离：A 的资源绝不进入 B / C 的 feed 或前台
    // ---------------------------------------------------------------

    public function test_feeds_and_frontend_do_not_leak_across_sites(): void
    {
        $aOnly = ['core-product', 'a-service', 'visible-article', 'acme-org'];

        foreach (['b.test', 'c.test'] as $otherHost) {
            $sitemap = $this->getOn($otherHost, '/sitemap.xml')->getContent();
            $llms = $this->getOn($otherHost, '/llms.txt')->getContent();
            $geo = json_decode($this->getOn($otherHost, '/geo.json')->getContent(), true);
            $geoJson = json_encode($geo, JSON_UNESCAPED_UNICODE);

            foreach ($aOnly as $slug) {
                $this->assertStringNotContainsString($slug, $sitemap, "{$otherHost} sitemap 泄漏 {$slug}");
                $this->assertStringNotContainsString($slug, $llms, "{$otherHost} llms 泄漏 {$slug}");
                $this->assertStringNotContainsString($slug, $geoJson, "{$otherHost} geo 泄漏 {$slug}");
            }

            // A 的详情 / 文章在其它 host 下必须 404
            $this->getOn($otherHost, '/products/core-product')->assertNotFound();
            $this->getOn($otherHost, '/solutions/a-service/')->assertNotFound();
            $this->getOn($otherHost, '/knowledge/visible-article')->assertNotFound();
        }

        // A 站也不得看到 C 的最小组织
        $aGeo = json_decode($this->getOn('a.test', '/geo.json')->getContent(), true);
        $this->assertStringNotContainsString('minimal-org', json_encode($aGeo));
    }

    // ---------------------------------------------------------------
    // 7. 产品页 Product JSON-LD 的 manufacturer.@id 必须与 Organization 节点同源
    //    （回归：ProductController::productSchema 曾硬编码 config('app.url')=localhost，
    //     导致 manufacturer 指向 localhost 而 Organization @id 指向真实 host，图谱断链）
    // ---------------------------------------------------------------
    public function test_product_jsonld_manufacturer_shares_organization_origin(): void
    {
        $html = $this->getOn('a.test', '/products/core-product')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $org = null;
        $product = null;
        foreach ($m[1] as $json) {
            $n = json_decode($json, true);
            if (($n['@type'] ?? null) === 'Organization') {
                $org = $n;
            }
            if (($n['@type'] ?? null) === 'Product') {
                $product = $n;
            }
        }

        $this->assertNotNull($org, '页面必须包含 Organization JSON-LD');
        $this->assertNotNull($product, '页面必须包含 Product JSON-LD');
        $this->assertSame($org['@id'], $product['manufacturer']['@id']);
        $this->assertStringContainsString('a.test', $product['manufacturer']['@id']);
        $this->assertStringNotContainsString('localhost', $product['manufacturer']['@id']);
    }
}
