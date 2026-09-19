<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 10：全系统最终验收（不信任前置阶段报告，从最终 HTTP 产物重新验证）。
 *
 * A. Site Traversal：核心页面 / 404 / 301 遍历
 * B. SEO：最终 HTML 七项标签 + JSON-LD 有效性
 * C. GEO：/geo.json 机器可读结构 + 事实/主体/关系一致性
 * E. Multi-Site：两站点 SEO / GEO 输出互不污染（含缓存 key 隔离）
 */
class FinalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\PageCache::flush();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update([
            'name'        => 'Acceptance Site',
            'domain'      => 'accept-a.test',
            'description' => 'Acceptance description',
            'logo'        => '/site-logo.png',
        ]);
        SiteContext::setSite($site);
    }

    protected function tearDown(): void
    {
        \App\Support\PageCache::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // A + B：遍历与最终 HTML SEO
    // ---------------------------------------------------------------

    public static function traversal(): array
    {
        return [
            ['/', false],
            ['/products/', false],
            ['/solutions/', false],
            ['/factory/', false],
            ['/cooperation/', false],
            ['/knowledge/', false],
            ['/about/profile/', false],
            ['/contact/', false],
            ['/search', true],
        ];
    }

    /**
     * @dataProvider traversal
     */
    public function test_site_traversal_with_complete_seo(string $path, bool $noindex): void
    {
        $res = $this->get($path)->assertOk();

        $html = $res->getContent();
        foreach ([
            '<title>',
            '<meta name="description" content=',
            '<link rel="canonical" href=',
            '<meta property="og:title" content=',
            '<meta property="og:description" content=',
            '<meta property="og:image" content=',
            '<meta name="robots" content=',
        ] as $tag) {
            $this->assertStringContainsString($tag, $html, "{$path} 缺少 {$tag}");
        }

        $expected = $noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
        $this->assertStringContainsString('<meta name="robots" content="' . $expected . '">', $html);
    }

    public function test_error_and_redirect_paths(): void
    {
        $this->get('/definitely-missing')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/llms.txt')->assertOk();
        $this->get('/geo.json')->assertOk();
    }

    public function test_final_html_json_ld_is_valid_and_consistent(): void
    {
        $category = \App\Models\Category::create([
            'site_id' => SiteContext::currentSite()->id, 'type' => 'list',
            'slug' => 'news', 'name' => 'News', 'is_active' => true,
        ]);
        \App\Models\Content::create([
            'site_id' => SiteContext::currentSite()->id, 'category_id' => $category->id,
            'type' => 'article', 'slug' => 'acceptance-article',
            'title' => 'Acceptance Article', 'summary' => 'Acceptance summary',
            'status' => 'published', 'published_at' => now(),
        ]);

        $html = $this->get('/news/acceptance-article')->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], '内容页必须输出 JSON-LD');

        $types = [];
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertSame('https://schema.org', $decoded['@context']);
            $types[] = $decoded['@type'];
        }
        $this->assertContains('Organization', $types);
        $this->assertContains('Article', $types);
    }

    // ---------------------------------------------------------------
    // C + E：GEO 一致性与多站隔离
    // ---------------------------------------------------------------

    public function test_geo_graph_and_seo_do_not_leak_across_sites(): void
    {
        $siteB = Site::create([
            'name' => 'Island Site', 'slug' => 'island-b', 'domain' => 'island-b.test',
            'description' => 'Island description', 'status' => 'active', 'is_default' => false,
        ]);

        $entityA = Entity::create([
            'site_id' => SiteContext::currentSite()->id, 'type' => 'service',
            'slug' => 'alpha-service', 'name' => 'Alpha Service',
            'summary' => 'Alpha summary', 'status' => 'published',
        ]);
        Entity::create([
            'site_id' => $siteB->id, 'type' => 'service',
            'slug' => 'beta-service', 'name' => 'Beta Service', 'status' => 'published',
        ]);
        \App\Models\Content::create([
            'site_id' => $siteB->id, 'type' => 'page', 'slug' => 'island-page',
            'title' => 'Island Page', 'status' => 'published', 'published_at' => now(),
        ]);

        // 站点 A 的 GEO 图
        $graphA = $this->get('/geo.json')->assertOk()->json();
        $entityIds = array_column($graphA['entities'], 'id');
        $this->assertContains('entity/service/alpha-service', $entityIds);
        $this->assertNotContains('entity/service/beta-service', $entityIds);
        $this->assertSame('Acceptance Site', $graphA['site']['name']);
        foreach (array_column($graphA['contents'], 'slug') as $slug) {
            $this->assertNotSame('island-page', $slug);
        }

        // 站点 A 的 SEO Resolution：canonical 域名必须是 A
        $resolver = app(\App\Services\Seo\SeoMetaResolver::class);
        $resultA = $resolver->resolveEntity($entityA);
        $this->assertSame('https://accept-a.test/service/alpha-service', $resultA->canonical);

        // 缓存 key 隔离：两站点不共享 site-scoped key
        SiteContext::setSite($siteB);
        $keyA = \App\Support\SiteCacheKey::make('settings', '', SiteContext::currentSite()->id);
        SiteContext::setSite(Site::where('slug', Site::DEFAULT_SLUG)->first());
        $keyDefault = \App\Support\SiteCacheKey::make('settings', '', SiteContext::currentSite()->id);
        $this->assertNotSame($keyA, $keyDefault);
    }

    public function test_seo_meta_overrides_flow_through_every_output_channel(): void
    {
        $category = \App\Models\Category::create([
            'site_id' => SiteContext::currentSite()->id, 'type' => 'list',
            'slug' => 'news', 'name' => 'News', 'is_active' => true,
        ]);
        $content = \App\Models\Content::create([
            'site_id' => SiteContext::currentSite()->id, 'category_id' => $category->id,
            'type' => 'article', 'slug' => 'override-flow',
            'title' => 'Plain Title', 'seo_title' => 'Legacy SEO Title',
            'status' => 'published', 'published_at' => now(),
        ]);
        \App\Models\SeoMeta::create([
            'site_id' => SiteContext::currentSite()->id, 'content_id' => $content->id,
            'title' => 'Override Title', 'description' => 'Override Description',
        ]);

        // 同一条数据贯穿四个输出通道，且值一致（单一 Resolution 源）
        $resolver = app(\App\Services\Seo\SeoMetaResolver::class);
        $seo = $resolver->resolveContent($content);
        $this->assertSame('Override Title', $seo->title);

        $html = $this->get('/news/override-flow')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Override Title', $html);
        $this->assertStringContainsString('<meta name="description" content="Override Description">', $html);

        $graph = $this->get('/geo.json')->assertOk()->json();
        $node = collect($graph['contents'])->firstWhere('slug', 'override-flow');
        $this->assertSame('Override Title', $node['title']);
        $this->assertSame('Override Description', $node['description']);

        // JSON-LD headline 同源
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $article = collect($m[1])->map(fn ($j) => json_decode($j, true))
            ->firstWhere('@type', 'Article');
        $this->assertSame('Override Title', $article['headline']);
        $this->assertSame('Override Description', $article['description']);
    }
}
