<?php

namespace Tests\Feature;

use App\Support\PageCache;
use App\Support\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 05：Theme Architecture（Engine ≠ Theme）。
 *
 * 主题契约：
 *   - 清单（theme.json）+ 发现（扫描 resources/themes）+ 激活（站点设置）+ 回退；
 *   - 激活主题同名视图覆盖基础视图，未覆盖视图自动回退；
 *   - 主题只消费公开视图数据（$seo 归一化 / 控制器变量），禁止读取
 *     Facts / 引擎内部 Service / 内部缓存（静态扫描锁定）；
 *   - 切换主题不得影响 SEO / Schema / GEO / Sitemap 引擎行为。
 */
class ThemeArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        ThemeManager::activate(ThemeManager::DEFAULT_THEME);
        PageCache::flush();
        parent::tearDown();
    }

    public function test_discovers_two_themes_with_valid_manifests(): void
    {
        $themes = ThemeManager::all();

        $this->assertArrayHasKey('default', $themes);
        $this->assertArrayHasKey('example', $themes);
        $this->assertSame('Default Theme', $themes['default']['name']);
        $this->assertSame('Example Theme', $themes['example']['name']);
    }

    public function test_default_theme_renders_base_views(): void
    {
        $this->assertSame('default', ThemeManager::active());

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-theme="example"', $html);
        // 基础主题布局仍消费归一化 $seo（引擎输出）
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
    }

    public function test_example_theme_overrides_home_and_layout(): void
    {
        $this->assertTrue(ThemeManager::activate('example'));
        ThemeManager::resetRequestMemo();

        $html = $this->get('/')->assertOk()->getContent();

        // 主题覆盖生效：example 布局与首页渲染
        $this->assertStringContainsString('data-theme="example"', $html);
        $this->assertStringContainsString('data-theme="example-home"', $html);

        // 主题契约：SEO head 仍由引擎（SeoHeadComposer → Resolver）供给，
        // 主题只透传，canonical / robots / og 与 default 主题一致。
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('<meta property="og:title" content="', $html);

        // 回退：主题未提供的视图（如 sitemap 引擎产物 / 错误页）仍可用
        $this->get('/sitemap.xml')->assertOk();
        $graph = $this->get('/geo.json')->assertOk()->json();
        $this->assertSame('geo-os/graph/v1', $graph['$schema']);
    }

    public function test_theme_switch_round_trip_keeps_engine_output_identical(): void
    {
        // 引擎输出（canonical / sitemap URL 集 / geo.json）在两个主题下逐字节一致
        $sitemapBefore = $this->get('/sitemap.xml')->getContent();

        $homeDefault = $this->get('/')->getContent();
        preg_match('#<link rel="canonical" href="([^"]+)"#', $homeDefault, $mDefault);
        $canonicalDefault = $mDefault[1] ?? null;

        ThemeManager::activate('example');
        ThemeManager::resetRequestMemo();

        $homeExample = $this->get('/')->getContent();
        preg_match('#<link rel="canonical" href="([^"]+)"#', $homeExample, $mExample);

        $this->assertSame($canonicalDefault, $mExample[1] ?? null, 'canonical 不得随主题改变');
        $this->assertSame($sitemapBefore, $this->get('/sitemap.xml')->getContent(), 'sitemap 不得随主题改变');

        ThemeManager::activate(ThemeManager::DEFAULT_THEME);
        ThemeManager::resetRequestMemo();

        $homeBack = $this->get('/')->getContent();
        $this->assertStringNotContainsString('data-theme="example"', $homeBack);
    }

    public function test_theme_views_have_no_engine_leakage(): void
    {
        // 静态扫描：示例主题视图不得引用 Facts / 引擎 Service / 内部缓存 / DB 门面
        $forbidden = ['Facts::', 'SchemaBuilder', 'SeoMetaResolver', 'DB::', 'Cache::', 'Setting::get(', 'PageCache::'];
        foreach (glob(resource_path('themes/example/views/**/*.blade.php')) ?: [] as $file) {
            $src = file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($file) . " 引用了引擎内部: {$needle}");
            }
        }
    }
}
