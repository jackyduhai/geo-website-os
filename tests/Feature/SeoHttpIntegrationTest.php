<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Services\Seo\SeoMetaResolver;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.6-D：HTTP 层 SEO 集成证据。
 *
 * 验证完整链路：Controller → SeoMetaResolver → SeoResult → Blade → 最终 HTML。
 * 最终 Response HTML 中的 title / description / canonical / og:* / robots
 * 必须与 SeoMetaResolver 的 Resolution 结果一致——Controller 与 Blade
 * 不得对 Resolver 结果做二次修改（canonical 不得被改写、不得引入查询串等）。
 *
 * 注意：测试间调用 PageCache::flush()，避免整页静态化缓存跨用例串页。
 */
class SeoHttpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();

        // HTTP 测试经由 ResolveSite 中间件绑定 default site；
        // 这里只改其字段，不新建站点，保证与请求链路一致。
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update([
            'name'        => 'Test Site',
            'domain'      => 'example.com',
            'description' => 'Site Description',
            'logo'        => '/site-logo.png',
        ]);
        SiteContext::setSite($this->site);
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function media(string $path): Media
    {
        return Media::create([
            'site_id'       => $this->site->id,
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => basename($path),
            'mime'          => 'image/jpeg',
            'size'          => 100000,
        ]);
    }

    private function category(string $slug): Category
    {
        return Category::create([
            'site_id'   => $this->site->id,
            'type'      => 'list',
            'slug'      => $slug,
            'name'      => ucfirst($slug),
            'is_active' => true,
        ]);
    }

    private function content(Category $category, string $slug, array $attrs = []): Content
    {
        return Content::create(array_merge([
            'site_id'      => $this->site->id,
            'category_id'  => $category->id,
            'type'         => $category->slug,
            'slug'         => $slug,
            'title'        => 'Content Title',
            'status'       => 'published',
            'published_at' => now(),
        ], $attrs));
    }

    // ---------------------------------------------------------------
    // 1. Home：GET / 的最终 HTML 必须与 resolveSite 一致
    // ---------------------------------------------------------------

    public function test_home_html_seo_matches_resolver_site_result(): void
    {
        $resolver = app(SeoMetaResolver::class);
        $expected = $resolver->resolveSite($this->site);

        $html = $this->get('/')->assertOk()->getContent();

        // <title>：Home 传入 title_full（= Resolver title），不叠加后缀
        $this->assertStringContainsString('<title>' . $expected->title . '</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="' . $expected->description . '">', $html);
        // canonical 必须来自 GenericUrlResolver：HTTPS + 首页带尾斜杠
        $this->assertStringContainsString('<link rel="canonical" href="' . $expected->canonical . '">', $html);
        $this->assertSame('https://example.com/', $expected->canonical);
        $this->assertStringContainsString('<meta property="og:title" content="' . $expected->ogTitle . '">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="' . $expected->ogDescription . '">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="' . $expected->ogImage . '">', $html);
        // robots：默认可索引
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $html);
    }

    public function test_home_canonical_has_no_query_string_in_html(): void
    {
        $html = $this->get('/?utm_source=external')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/">', $html);
        $this->assertStringNotContainsString('href="https://example.com/?', $html);
    }

    // ---------------------------------------------------------------
    // 2. Content：GET /{content_type}/{slug} 的最终 HTML 必须与 resolveContent 一致
    // ---------------------------------------------------------------

    public function test_content_html_seo_matches_resolver_content_result(): void
    {
        $cover = $this->media('/uploads/cover.jpg');
        $category = $this->category('news');
        $content = $this->content($category, 'resolver-article', [
            'title'     => 'Content Title',
            'summary'   => 'Content Summary',
            'cover_id'  => $cover->id,
        ]);

        $expected = app(SeoMetaResolver::class)->resolveContent($content);

        $html = $this->get('/news/resolver-article')->assertOk()->getContent();

        // <title>：Content 页不传 title_full，站点后缀由布局层统一拼接，
        // 但前缀必须是 Resolver 的 title（无 SeoMeta 时取 Content.title）。
        $this->assertStringContainsString('<title>' . $expected->title, $html);
        $this->assertSame('Content Title', $expected->title);
        $this->assertSame('Content Summary', $expected->description);
        $this->assertStringContainsString('<meta name="description" content="' . $expected->description . '">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="' . $expected->canonical . '">', $html);
        // canonical：HTTPS、无查询串、内容页无尾斜杠，且来自 UrlResolver 而非旧 URL 生成器
        $this->assertSame('https://example.com/news/resolver-article', $expected->canonical);
        $this->assertStringContainsString('<meta property="og:title" content="' . $expected->ogTitle . '">', $html);
        $this->assertSame('Content Title', $expected->ogTitle);
        $this->assertStringContainsString('<meta property="og:description" content="' . $expected->ogDescription . '">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="' . $expected->ogImage . '">', $html);
        // og:image 取 Media::url() 绝对公开地址（含 host + /storage），不再是裸相对 path（UAT Bug#3）。
        $this->assertSame($cover->url(), $expected->ogImage);
    }

    public function test_content_canonical_has_no_query_string_in_html(): void
    {
        $category = $this->category('news');
        $this->content($category, 'canonical-article', ['title' => 'Canonical Article']);

        $html = $this->get('/news/canonical-article?utm_campaign=x')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/news/canonical-article">', $html);
        $this->assertStringNotContainsString('canonical-article?', $html);
    }

    // ---------------------------------------------------------------
    // 3. SeoMeta 显式覆盖：最终 HTML 必须使用显式值
    // ---------------------------------------------------------------

    public function test_explicit_seo_meta_overrides_all_layers_in_html(): void
    {
        $cover = $this->media('/uploads/cover.jpg');
        $category = $this->category('news');
        $content = $this->content($category, 'override-article', [
            'title'     => 'Content Title',
            'summary'   => 'Content Summary',
            'cover_id'  => $cover->id,
        ]);

        SeoMeta::create([
            'site_id'       => $this->site->id,
            'content_id'    => $content->id,
            'title'         => 'Explicit Title',
            'description'   => 'Explicit Description',
            'canonical'     => 'https://explicit.example.com/explicit',
            'og_image_path' => '/uploads/explicit-og.jpg',
        ]);

        $expected = app(SeoMetaResolver::class)->resolveContent($content);

        $html = $this->get('/news/override-article')->assertOk()->getContent();

        $this->assertSame('Explicit Title', $expected->title);
        $this->assertSame('Explicit Description', $expected->description);
        $this->assertSame('https://explicit.example.com/explicit', $expected->canonical);
        $this->assertSame('/uploads/explicit-og.jpg', $expected->ogImage);

        $this->assertStringContainsString('<title>Explicit Title', $html);
        $this->assertStringContainsString('<meta name="description" content="Explicit Description">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://explicit.example.com/explicit">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Explicit Title">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Explicit Description">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="/uploads/explicit-og.jpg">', $html);
    }

    // ---------------------------------------------------------------
    // 4. Content fallback：无 SeoMeta 时 HTML 沿继承链回退
    // ---------------------------------------------------------------

    public function test_content_falls_back_to_summary_in_html(): void
    {
        // P0-B 后链：SeoMeta → Content.title / summary → Site → System
        // 无 SeoMeta 时：标题取 Content.title，描述取 Content.summary。
        $category = $this->category('news');
        $this->content($category, 'plain-article', [
            'title'   => 'Plain Title Only',
            'summary' => 'Plain Summary',
        ]);

        $html = $this->get('/news/plain-article')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Plain Title Only', $html);
        $this->assertStringContainsString('<meta name="description" content="Plain Summary">', $html);
    }

    // ---------------------------------------------------------------
    // 5. KnowledgeController：转发 PageController 后 Resolver → SeoResult → Blade 仍有效
    // ---------------------------------------------------------------

    public function test_knowledge_article_forwards_to_dispatcher_and_resolves_seo(): void
    {
        $cover = $this->media('/uploads/knowledge-cover.jpg');
        $category = $this->category('knowledge');
        $this->content($category, 'knowledge-article', [
            'type'      => 'article',
            'title'     => 'Knowledge Article Title',
            'summary'   => 'Knowledge Summary',
            'cover_id'  => $cover->id,
        ]);

        $html = $this->get('/knowledge/knowledge-article')->assertOk()->getContent();

        // /knowledge/{slug} 命中 KnowledgeController::channel，非栏目 slug
        // 转交 PageController::dispatch → resolveContent → Blade。SEO 必须仍是 Resolver 输出。
        $this->assertStringContainsString('<title>Knowledge Article Title', $html);
        $this->assertStringContainsString('<meta name="description" content="Knowledge Summary">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/article/knowledge-article">', $html);
        // 封面经 Media::url() 绝对化（UAT Bug#3）。
        $this->assertStringContainsString('<meta property="og:image" content="' . $cover->url() . '">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $html);
    }

    public function test_knowledge_index_page_renders(): void
    {
        $this->get('/knowledge/')->assertOk();
    }

    // ---------------------------------------------------------------
    // 6. 架构守护：核心 Controller 不得在 SEO 链路上绕过 Resolver
    // ---------------------------------------------------------------

    public function test_core_controllers_do_not_reimplement_seo_resolution(): void
    {
        $home = file_get_contents(app_path('Http/Controllers/Site/HomeController.php'));
        // Home 的 SEO Resolution 唯一入口是 SeoMetaResolver::resolveSite
        $this->assertStringContainsString('SeoMetaResolver', $home);
        $this->assertStringContainsString('resolveSite(', $home);
        // 不得回读旧 Setting 链路参与 SEO
        $this->assertStringNotContainsString("Setting::get('site_name'", $home);
        $this->assertStringNotContainsString("Setting::get('seo_default_desc'", $home);

        $page = file_get_contents(app_path('Http/Controllers/Site/PageController.php'));
        // Content 的 SEO Resolution 唯一入口是 SeoMetaResolver::resolveContent
        $this->assertStringContainsString('resolveContent(', $page);
        // 内容渲染不得使用 legacy meta 辅助方法拼 SEO
        $this->assertStringNotContainsString('metaTitle()', $page);
        $this->assertStringNotContainsString('metaDescription()', $page);
        $this->assertStringNotContainsString('canonicalUrl()', $page);
    }
}
