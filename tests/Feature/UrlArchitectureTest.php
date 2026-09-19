<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 04：Generic URL / Route / Redirect Architecture。
 *
 * URL 责任边界（详见 docs/audit/url-architecture-boundaries.md）：
 *   - Canonical（SEO 规范地址）：UrlResolverInterface / GenericUrlResolver，唯一来源
 *   - 站内业务 URL：GeoUrlGenerator（原 Demo Tenant AUrlGenerator，P0-A 已通用化替换）
 *   - 语义公开路径：Content::url() / Category::url()（栏目路径 + slug）
 *   - 地址规范化：CanonicalizeSlash（尾斜杠）+ HandleRedirects（后台 301/302 规则）
 *
 * 本测试从最终 HTTP 响应锁定边界行为：
 *   1. 内容经错误栏目路径访问 → 301 到语义规范路径（Controller 不重写 Resolver canonical）
 *   2. 未知路径 → 404（分发器既不猜测内容也不猜测栏目）
 *   3. 跳转目标路径上的页面 canonical 仍由 UrlResolver 生成（与 301 无关、无查询串）
 */
class UrlArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update([
            'name'        => 'Test Site',
            'domain'      => 'example.com',
            'description' => 'Site Description',
            'logo'        => '/site-logo.png',
        ]);
        SiteContext::setSite($site);

        \App\Models\Category::create([
            'site_id'   => $site->id,
            'type'      => 'list',
            'slug'      => 'news',
            'name'      => 'News',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function content(string $slug): \App\Models\Content
    {
        return \App\Models\Content::create([
            'site_id'      => SiteContext::currentSite()->id,
            'category_id'  => \App\Models\Category::where('slug', 'news')->firstOrFail()->id,
            'type'         => 'news',
            'slug'         => $slug,
            'title'        => 'URL Article ' . $slug,
            'status'       => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_content_via_wrong_category_path_redirects_301_to_canonical_path(): void
    {
        $this->content('moved-article');

        $res = $this->get('/wrong-segment/moved-article');

        $res->assertStatus(301);
        $this->assertStringEndsWith('/news/moved-article', (string) $res->headers->get('Location'));
    }

    public function test_unknown_path_is_404(): void
    {
        $this->get('/definitely-not-a-page')->assertNotFound();
        $this->get('/news/not-published-slug')->assertNotFound();
    }

    public function test_canonical_path_serves_200_with_resolver_canonical(): void
    {
        $this->content('canonical-path-article');

        $html = $this->get('/news/canonical-path-article')->assertOk()->getContent();

        // 语义路径 /news/{slug} 命中页面；canonical 仍由 UrlResolver 按冻结契约
        // 生成（/{content_type}/{slug}），二者职责不同、互不覆盖。
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://example.com/news/canonical-path-article">',
            $html
        );
    }

    public function test_redirect_rules_fire_before_canonicalization(): void
    {
        $this->content('legacy-article');

        // 后台 301 规则优先于一切页面渲染（旧 URL 先跳转）
        \App\Models\Redirect::create([
            'from_path' => '/old-knowledge',
            'to_path'   => '/news/legacy-article',
            'code'      => 301,
            'is_active' => true,
        ]);
        \App\Http\Middleware\HandleRedirects::flushRules();

        $res = $this->get('/old-knowledge');

        $res->assertStatus(301);
        $this->assertStringEndsWith('/news/legacy-article', (string) $res->headers->get('Location'));
    }
}
