<?php

namespace Tests\Feature;

use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 02：Blade SEO Single Source。
 *
 * 布局头部（layouts/site.blade.php）是全站唯一 SEO 输出点，必须只消费
 * SeoHeadComposer 归一化后的 $seo——Blade 不读取 Setting / URL 生成器，
 * 不自行拼接 SEO 兜底；兜底优先级统一收敛在 Composer：
 *   Controller $seo → SeoMetaResolver::resolveSite() → 遗留站点设置 → 展示默认
 */
class SeoHeadComposerTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    /** 直接渲染布局，取归一化后的 $seo 输出 */
    private function renderHead(array $data = []): string
    {
        return view('layouts.site', $data)->render();
    }

    public function test_blade_head_is_pure_consumer_without_setting_reads(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/site.blade.php'));

        // 仅检查 <head> SEO 区块（<title> 至 favicon 之前）；
        // <style> 中的主题 Token 属于展示层合法的 siteSettings 用途，不在 SEO 范围。
        $start = strpos($blade, '<title>');
        $end = strpos($blade, '<link rel="icon"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $head = substr($blade, $start, $end - $start);

        // SEO 头部区域不得再回读站点设置或拼接旧 URL
        $this->assertStringNotContainsString('$siteSettings[', $head);
        $this->assertStringNotContainsString('url()->current()', $head);
        // 不得硬编码业务名作为 SEO 兜底
        $this->assertStringNotContainsString('Demo Tenant A食品', $head);
    }

    public function test_missing_seo_keys_fall_back_to_site_resolution(): void
    {
        $html = $this->renderHead(['seo' => ['title' => 'Only Title']]);

        $this->assertStringContainsString('<meta name="description" content="Site Description">', $html);
        // canonical 由 GenericUrlResolver 生成（HTTPS + 站点域名 + 首页尾斜杠）
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/">', $html);
        // og:image 沿 Resolver 链取 Site.logo，不再从 Blade 直读设置
        $this->assertStringContainsString('<meta property="og:image" content="/site-logo.png">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Only Title">', $html);
    }

    public function test_no_seo_input_at_all_yields_site_resolution(): void
    {
        $html = $this->renderHead();

        $this->assertStringContainsString('<title>Test Site', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Test Site">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $html);
    }

    public function test_title_has_no_dangling_separator_when_site_name_suffix_empty(): void
    {
        // 回归 P-STEP 14：站点名后缀为空时，内页标题不得被拼成 "文章标题 - "（悬挂分隔符）。
        // TD-12 后 site_name 由 Site.name 单向镜像、正常情况非空；这里直接清空底层
        // site_name（绕过镜像），人为构造后缀缺失的防御场景。
        \App\Models\Setting::withoutSiteScope()
            ->where('key', 'site_name')->update(['value' => '']);
        \App\Models\Setting::flush();
        $html = $this->renderHead(['seo' => ['title' => '某内页标题']]);

        $this->assertStringContainsString('<title>某内页标题</title>', $html);
        $this->assertStringNotContainsString(' - </title>', $html);
    }

    public function test_title_joins_suffix_with_separator_only_when_present(): void
    {
        // 后缀存在时仍正常拼接为 "标题 - 站名"
        \App\Models\Setting::set('site_name', '我的站点');
        \App\Models\Setting::flush();

        $html = $this->renderHead(['seo' => ['title' => '某内页标题']]);

        $this->assertStringContainsString('<title>某内页标题 - 我的站点</title>', $html);
    }

    public function test_blank_suffix_follows_site_name_without_homepage_duplication(): void
    {
        // 出厂 seo_title_suffix 留空：suffix 回退 site_name（镜像 Site.name），换品牌自动跟随。
        // 首页 title 已等于站点名时去重，不得渲染成 "站名 - 站名"。
        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update(['name' => 'Aurora Living']);
        \App\Models\Setting::flush();

        $home = $this->renderHead();
        $this->assertStringContainsString('<title>Aurora Living</title>', $home);
        $this->assertStringNotContainsString('Aurora Living - Aurora Living', $home);

        // 内页 title 与品牌 suffix 不同，正常拼接品牌名
        $inner = $this->renderHead(['seo' => ['title' => '某产品详情']]);
        $this->assertStringContainsString('<title>某产品详情 - Aurora Living</title>', $inner);

        // 显式 suffix 覆盖，优先于站点名
        \App\Models\Setting::set('seo_title_suffix', '自定义品牌后缀');
        \App\Models\Setting::flush();
        $custom = $this->renderHead(['seo' => ['title' => '某产品详情']]);
        $this->assertStringContainsString('<title>某产品详情 - 自定义品牌后缀</title>', $custom);
    }

    public function test_legacy_settings_only_apply_after_site_resolution(): void
    {
        // Site.description / Site.logo 为空时，兜底才落到遗留设置
        Site::where('slug', Site::DEFAULT_SLUG)->update(['description' => null, 'logo' => null]);
        SiteContext::currentSite()?->refresh();
        \App\Models\Setting::flush();

        \App\Models\Setting::set('seo_default_desc', 'Legacy Default Desc');
        \App\Models\Setting::set('seo_og_image', '/legacy-og.png');

        $html = $this->renderHead();

        $this->assertStringContainsString('<meta name="description" content="Legacy Default Desc">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="/legacy-og.png">', $html);
    }

    public function test_site_level_seo_meta_feeds_unmigrated_page_gaps(): void
    {
        // 站点级 SeoMeta 是 Site 之上的最高优先级：未迁移页缺省键应取到它
        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SeoMeta::create([
            'site_id'     => $site->id,
            'title'       => 'Site Meta Title',
            'description' => 'Site Meta Description',
        ]);

        $html = $this->renderHead(['seo' => ['title' => 'Page Title']]);

        $this->assertStringContainsString('<meta name="description" content="Site Meta Description">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Page Title">', $html);
    }

    public function test_search_page_renders_through_normalized_head(): void
    {
        // 未迁移 Controller（Search）经归一化后头部完整、noindex 正确输出
        $html = $this->get('/search')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Test Site">', $html);
    }
}
