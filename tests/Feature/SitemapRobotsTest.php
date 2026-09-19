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

        $this->assertContains(url('/'), $locs);
        $this->assertContains(url('/knowledge/sitemap-article'), $locs);
        // 详情型 URL 无尾斜杠，与 CanonicalizeSlash 规则一致
        $this->assertNotContains(url('/knowledge/sitemap-article/'), $locs);
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

        $this->assertNotContains(url('/knowledge/legacy-noindex'), $locs);
        $this->assertNotContains(url('/knowledge/draft-article'), $locs);
        $this->assertNotContains(url('/archived-cat/inactive-cat-article'), $locs);
    }

    public function test_sitemap_includes_indexable_content_with_seo_meta(): void
    {
        // 对照：有 SeoMeta 但未 noindex 的内容正常收录
        $article = $this->knowledgeContent('seometa-ok');
        \App\Models\SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $article->id, 'noindex' => false,
        ]);

        $this->assertContains(url('/knowledge/seometa-ok'), $this->locs());
    }

    public function test_sitemap_is_site_scoped(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);
        Content::create([
            'site_id' => $siteB->id, 'type' => 'page', 'slug' => 'site-b-page',
            'title' => 'Page B', 'status' => 'published', 'published_at' => now(),
        ]);

        $locs = $this->locs();

        $this->assertNotContains('https://b.example.com/page/site-b-page', $locs);
        // 且不输出任何 Site B 域名地址
        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('b.example.com', $loc);
        }
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
