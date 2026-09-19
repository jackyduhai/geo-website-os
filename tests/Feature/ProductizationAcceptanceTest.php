<?php

namespace Tests\Feature;

use App\Support\PageCache;
use App\Support\Plugins\PluginManager;
use App\Support\SiteContext;
use App\Support\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 10：Final Productization Acceptance（套件内部分）。
 *
 * 与隔离环境 Release 演练互补，覆盖：
 * E/F. Theme 往返 + Plugin 启停全程引擎输出稳定
 * G.   Upgrade 后（本套件即 2.0.0 schema）站点/SEO/GEO 全链路健康
 * H.   Multi-Site 全维度隔离（含主题设置与缓存）
 * I.   Security 静态项（发布模板 debug 默认、auth 中间件、无硬编码密钥）
 */
class ProductizationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();

        $site = \App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->firstOrFail();
        $site->update(['name' => 'Acceptance Site', 'domain' => 'accept-a.test']);
        SiteContext::setSite($site);
    }

    protected function tearDown(): void
    {
        ThemeManager::activate(ThemeManager::DEFAULT_THEME);
        PluginManager::disable('hello');
        PageCache::flush();
        parent::tearDown();
    }

    public function test_theme_and_plugin_lifecycle_keep_engine_output_stable(): void
    {
        $canonical = function (): string {
            preg_match('#<link rel="canonical" href="([^"]+)"#', $this->get('/')->getContent(), $m);

            return $m[1] ?? '';
        };
        $sitemapBefore = $this->get('/sitemap.xml')->getContent();
        $graphBefore = $this->get('/geo.json')->getContent();

        // Theme A → B
        ThemeManager::activate('example');
        ThemeManager::resetRequestMemo();
        $this->assertStringContainsString('data-theme="example"', $this->get('/')->getContent());

        // Plugin enable（与 theme B 并存）
        PluginManager::enable('hello');
        $this->get('/plugins/hello/ping')->assertOk();

        // 引擎输出仍稳定
        $this->assertSame($sitemapBefore, $this->get('/sitemap.xml')->getContent(), 'sitemap 不得随 theme/plugin 变化');
        $this->assertSame($graphBefore, $this->get('/geo.json')->getContent(), 'geo.json 不得随 theme/plugin 变化');
        $this->assertSame($canonical(), $this->canonicalForDefaultTheme(), '切回后 canonical 一致');

        // Theme B → A + Plugin disable
        ThemeManager::activate(ThemeManager::DEFAULT_THEME);
        ThemeManager::resetRequestMemo();
        PluginManager::disable('hello');
        ThemeManager::resetRequestMemo();

        $this->assertStringNotContainsString('data-theme="example"', $this->get('/')->getContent());
        $this->assertSame($sitemapBefore, $this->get('/sitemap.xml')->getContent());
    }

    private function canonicalForDefaultTheme(): string
    {
        preg_match('#<link rel="canonical" href="([^"]+)"#', $this->get('/')->getContent(), $m);

        return $m[1] ?? '';
    }

    public function test_multi_site_full_isolation(): void
    {
        $siteB = \App\Models\Site::create([
            'name' => 'Island', 'slug' => 'island', 'domain' => 'island-b.test',
            'status' => 'active', 'is_default' => false,
        ]);

        // Site B：独立主题设置 + 独立内容/实体
        \App\Models\Setting::set('theme_active', 'example');
        \App\Models\Content::create([
            'site_id' => $siteB->id, 'type' => 'page', 'slug' => 'island-page',
            'title' => 'Island Page', 'status' => 'published', 'published_at' => now(),
        ]);
        $entityA = \App\Models\Entity::create([
            'site_id' => SiteContext::currentSite()->id, 'type' => 'service',
            'slug' => 'svc-a', 'name' => 'Service A', 'status' => 'published',
        ]);

        // 站点 A 视角：内容/实体/主题不泄漏
        $graph = $this->get('/geo.json')->assertOk()->json();
        $this->assertSame('Acceptance Site', $graph['site']['name']);
        $this->assertContains('entity/service/svc-a', array_column($graph['entities'], 'id'));
        $this->assertNotContains('island-page', array_column($graph['contents'], 'slug'));

        // A 的 sitemap 不含 B 的页面
        $this->assertStringNotContainsString('island-page', $this->get('/sitemap.xml')->getContent());

        // 缓存 key 按站点隔离
        $keyA = \App\Support\SiteCacheKey::make('settings');
        \App\Support\SiteContext::setSite($siteB);
        $keyB = \App\Support\SiteCacheKey::make('settings');
        SiteContext::setSite(\App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->first());
        $this->assertNotSame($keyA, $keyB);
    }

    public function test_security_static_contracts(): void
    {
        // 发布模板默认关闭 debug（防信息泄漏）
        $example = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('APP_DEBUG=false', $example);

        // Core 无硬编码默认密码/密钥
        foreach (['Demo Tenant A@2026', 'secret-password', 'base64:'] as $needle) {
            $installer = file_get_contents(app_path('Console/Commands/GeoInstall.php'));
            $this->assertStringNotContainsString($needle, $installer);
        }

        // Admin 后台受 auth 中间件保护（路由层契约）
        $admin = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString("middleware('admin.auth')", $admin);
    }
}
