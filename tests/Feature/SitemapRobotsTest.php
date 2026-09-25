<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 07：Sitemap / Robots / Indexability。
 *
 * 规则统一来源（冻结）：
 *   - 可索引判定：SeoMeta.noindex → Content.noindex → false（sitemap 排除链）
 *   - 收录范围：published 且归属启用栏目；draft / 停用栏目内容 / noindex 一律排除
 *   - URL：语义公开路径（Content::url / Category::url），目录型带斜杠、详情型不带
 *   - 多站隔离：sitemap 按当前 Site 输出
 */
class SitemapRobotsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['name' => 'Test Site', 'domain' => 'example.com']);
        SiteContext::setSite($this->site);
    }

    private function knowledgeContent(string $slug, array $attrs = []): Content
    {
        $cat = Category::firstOrCreate(
            ['slug' => 'knowledge', 'site_id' => $this->site->id],
            ['type' => 'list', 'name' => 'Knowledge', 'is_active' => true]
        );

        return Content::create(array_merge([
            'site_id'      => $this->site->id,
            'category_id'  => $cat->id,
            'type'         => 'article',
            'slug'         => $slug,
            'title'        => 'Article ' . $slug,
            'status'       => 'published',
            'published_at' => now(),
        ], $attrs));
    }

    private function locs(): array
    {
        $xml = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);

        return $m[1];
    }

    public function test_sitemap_includes_home_and_published_content_with_semantic_url(): void
    {
        $this->knowledgeContent('sitemap-article');

        $locs = $this->locs();

        // TD-09：sitemap 是声明性 feed，<loc> 按当前站点规范 domain（example.com）裁决，
        // 不跟随测试请求 origin（localhost）。这里仅把 host 预期从 url()（localhost）修正为
        // 站点规范地址；收录 / 排除与「详情型无尾斜杠」的断言强度保持不变。
        $this->assertContains('https://example.com', $locs);
        $this->assertContains('https://example.com/knowledge/sitemap-article', $locs);
        // 详情型 URL 无尾斜杠，与 CanonicalizeSlash 规则一致
        $this->assertNotContains('https://example.com/knowledge/sitemap-article/', $locs);
    }

    public function test_sitemap_excludes_noindex_draft_and_inactive_category(): void
    {
        // P0-B 后 noindex 唯一来源是 SeoMeta（contents.noindex 列已删除）
        $noindexArticle = $this->knowledgeContent('legacy-noindex');
        \App\Models\SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $noindexArticle->id, 'noindex' => true,
        ]);
        $this->knowledgeContent('draft-article', ['status' => 'draft']);

        $inactive = Category::create([
            'site_id' => $this->site->id, 'type' => 'list', 'slug' => 'archived-cat',
            'name' => 'Archived', 'is_active' => false,
        ]);
        Content::create([
            'site_id' => $this->site->id, 'category_id' => $inactive->id,
            'type' => 'post', 'slug' => 'inactive-cat-article', 'title' => 'X',
            'status' => 'published', 'published_at' => now(),
        ]);

        $locs = $this->locs();

        // TD-09：loc 按站点规范 domain 输出，排除断言也必须用 example.com，
        // 否则用 localhost 断言「不包含」会恒真、失去排除验证意义。
        $this->assertNotContains('https://example.com/knowledge/legacy-noindex', $locs);
        $this->assertNotContains('https://example.com/knowledge/draft-article', $locs);
        $this->assertNotContains('https://example.com/archived-cat/inactive-cat-article', $locs);
    }

    public function test_sitemap_includes_indexable_content_with_seo_meta(): void
    {
        // 对照：有 SeoMeta 但未 noindex 的内容正常收录
        $article = $this->knowledgeContent('seometa-ok');
        \App\Models\SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $article->id, 'noindex' => false,
        ]);

        // TD-09：loc 按站点规范 domain（example.com）裁决，而非请求 origin（localhost）。
        $this->assertContains('https://example.com/knowledge/seometa-ok', $this->locs());
    }

    public function test_sitemap_is_site_scoped(): void
    {
        // P-STEP 14 / D.2：默认站 A 播种“站点隔离”的 Example 目录（Catalog 投影为本站 Entity）。
        // 旧 Facts 是全局配置，空站也能渲染；Catalog 只取本站 Entity，B 站不得看到 A 站目录。
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        // 先在 A 站上下文收集本站目录的规范路径，供后续双向断言。
        // TD-102：/contact/ 是每站都有的官网基础页（TD-100 后空站也 200、sitemap 无条件收录），
        // 不属于 A 站专有目录，故不列入“跨站泄漏”排除清单；B 站应收录其“本站” /contact/。
        $catalogPaths = [
            '/products/', '/solutions/', '/factory/', '/cooperation/',
            '/about/profile/', '/about/history/', '/about/culture/',
        ];
        foreach (\App\Support\Catalog::productLines() as $line) {
            $catalogPaths[] = '/products/' . $line['slug'] . '/';
        }
        foreach (\App\Support\Catalog::coreProductSlugs() as $slug) {
            $catalogPaths[] = '/products/' . $slug;
        }
        foreach (\App\Support\Catalog::scenes() as $scene) {
            $catalogPaths[] = '/solutions/' . $scene['slug'] . '/';
        }

        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);
        Content::create([
            'site_id' => $siteB->id, 'type' => 'page', 'slug' => 'site-b-page',
            'title' => 'Page B', 'status' => 'published', 'published_at' => now(),
        ]);

        // —— A 站视角：必须完整收录本站目录，且不泄漏任何 B 站地址 ——
        $xmlA = $this->get('https://example.com/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>(.*?)</loc>#', $xmlA, $mA);
        $locsA = $mA[1];

        $this->assertContains('https://example.com', $locsA);
        $this->assertContains('https://example.com/products/', $locsA);
        $this->assertContains('https://example.com/solutions/', $locsA);
        $this->assertContains('https://example.com/factory/', $locsA);
        $this->assertContains('https://example.com/cooperation/', $locsA);
        foreach (\App\Support\Catalog::coreProductSlugs() as $slug) {
            $this->assertContains('https://example.com/products/' . $slug, $locsA);
        }
        foreach (\App\Support\Catalog::scenes() as $scene) {
            $this->assertContains('https://example.com/solutions/' . $scene['slug'] . '/', $locsA);
        }
        $this->assertNotContains('https://b.example.com/page/site-b-page', $locsA);
        // A 站每条 loc 的 host 必须严格是 example.com（不得出现 b.example.com）
        foreach ($locsA as $loc) {
            $this->assertSame('example.com', parse_url($loc, PHP_URL_HOST), "A sitemap leaked host in: $loc");
        }

        // —— B 站视角（D.2 核心回归）：空站绝不能渲染 A 站 / 全局目录 ——
        $xmlB = $this->get('https://b.example.com/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>(.*?)</loc>#', $xmlB, $mB);
        $locsB = $mB[1];

        $this->assertContains('https://b.example.com', $locsB);
        // TD-102：B 站收录自己的 /contact/ 基础页（host=b.example.com、200），不是 A 站泄漏
        $this->assertContains('https://b.example.com/contact/', $locsB);
        // B 站每条 loc 的 host 必须严格是 b.example.com（不得回落到 example.com）
        foreach ($locsB as $loc) {
            $this->assertSame('b.example.com', parse_url($loc, PHP_URL_HOST), "B sitemap leaked host in: $loc");
        }
        // B 站 sitemap 绝不含 A 站任何目录路径（产品 / 场景 / 体系 / 工厂 / 合作 / 关于）
        foreach ($catalogPaths as $path) {
            $this->assertStringNotContainsString($path, $xmlB, "B sitemap leaked A catalog path: $path");
        }
        // 也不得收录 A 站首页实体（<loc>https://example.com</loc>，区别于子域 b.example.com）
        $this->assertStringNotContainsString('<loc>https://example.com</loc>', $xmlB);
    }

    public function test_sitemap_xml_is_valid_structure(): void
    {
        $this->knowledgeContent('xml-article');

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        // XML 结构可解析
        simplexml_load_string($xml);
        $this->assertTrue(true);
    }

    public function test_robots_disallows_admin_and_allows_ai_crawlers(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Sitemap: ', $body);

        foreach ((array) config('geo.ai_crawlers', []) as $ua) {
            $this->assertStringContainsString('User-agent: ' . $ua, $body);
        }
    }
}
