<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Models\Setting;
use App\Support\Analytics\AnalyticsConfig;
use App\Support\Head\HeadCodeSanitizer;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18H-3 — Analytics / Consent / 受控动态 CSP / Head Code 防回归。
 *
 * 核心产品契约（旧实现前台零统计、seo_head_code 以 {!! !!} raw 输出 <script>、
 * GA/GTM/Meta 外链全被 CSP 静默拦截）：
 *   A) 默认全部关闭：无 provider、前台不输出 GeoAnalytics、CSP 不放开任何第三方域；
 *   B) 启用 provider：前台输出统一事件层 + consent banner，CSP 仅放开该 provider 域；
 *   C) 关闭后 CSP 对应域移除（最小权限、不残留）；
 *   D) 校验：启用但 ID 空 / ID 格式非法被拒；合法配置保存；
 *   E) Locale：/en 事件层 LOCALE=en、zh 为 zh-CN；
 *   F) Multi-Site：A 站 GA4、B 站 Meta，CSP / 配置不串站；
 *   G) HeadCodeSanitizer：仅允许 meta/link，<script> / javascript: 剔除并标记 invalid。
 *
 * 注：consent 未同意前第三方脚本不加载属浏览器运行时行为（JS），此处通过
 * "CSP 是允许而非加载 + banner 输出 + CONFIG" 在服务端可断言层验证。
 */
class AnalyticsConsentCsp18H3Test extends TestCase
{
    use RefreshDatabase;

    private Site $default;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed(DefaultSettingSeeder::class);
        $this->admin = User::create([
            'name' => '管理员', 'email' => 'admin@example.test',
            'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
        PageCache::flush();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
    }

    private function analyticsPayload(array $override = []): array
    {
        return array_merge([
            'analytics_ga4_enabled' => 0, 'analytics_ga4_id' => '',
            'analytics_gtm_enabled' => 0, 'analytics_gtm_id' => '',
            'analytics_meta_enabled' => 0, 'analytics_meta_id' => '',
            'analytics_consent_required' => 1,
        ], $override);
    }

    private function enableGa4(string $id = 'G-TEST12345'): void
    {
        Setting::set('analytics_ga4_enabled', '1');
        Setting::set('analytics_ga4_id', $id);
        Setting::flush();
        PageCache::flush();
    }

    // ---------- A) 默认全关 ----------

    public function test_no_provider_config_and_no_analytics_script(): void
    {
        $cfg = app(AnalyticsConfig::class);
        $this->assertFalse($cfg->hasAnyProvider());
        $this->assertSame([], $cfg->enabledProviders());

        $r = $this->get('/');
        $r->assertOk();
        $r->assertDontSee('GeoAnalytics', false);
    }

    public function test_csp_clean_by_default(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('google-analytics', $csp);
        $this->assertStringNotContainsString('googletagmanager', $csp);
        $this->assertStringNotContainsString('facebook', $csp);
    }

    // ---------- B) 启用 provider ----------

    public function test_enable_ga4_emits_analytics_and_consent_banner(): void
    {
        $this->enableGa4();
        $this->assertSame(['ga4'], app(AnalyticsConfig::class)->enabledProviders());

        $r = $this->get('/');
        $r->assertOk();
        $r->assertSee('GeoAnalytics', false);
        // consent_required 默认 true → consent banner（role=dialog）输出。
        $r->assertSee('role="dialog"', false);
        $r->assertSee('var LOCALE = "zh-CN"', false);
    }

    public function test_csp_includes_ga4_hosts(): void
    {
        $this->enableGa4();
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('google-analytics.com', $csp);
        $this->assertStringContainsString('googletagmanager.com', $csp);
        $this->assertStringNotContainsString('facebook', $csp);
    }

    public function test_csp_includes_meta_hosts(): void
    {
        Setting::set('analytics_meta_enabled', '1');
        Setting::set('analytics_meta_id', '123456789');
        Setting::flush();
        PageCache::flush();

        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('connect.facebook.net', $csp);
        $this->assertStringContainsString('www.facebook.com', $csp);
        $this->assertStringNotContainsString('google-analytics', $csp);
    }

    // ---------- C) 关闭后移除 ----------

    public function test_csp_removes_hosts_after_disable(): void
    {
        $this->enableGa4();
        $this->assertStringContainsString('googletagmanager',
            $this->get('/')->headers->get('Content-Security-Policy'));

        Setting::set('analytics_ga4_enabled', '0');
        Setting::flush();
        PageCache::flush();

        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('googletagmanager', $csp);
        $this->assertStringNotContainsString('google-analytics', $csp);
    }

    // ---------- D) 校验 ----------

    public function test_enable_provider_without_id_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update', 'analytics'),
                $this->analyticsPayload(['analytics_ga4_enabled' => 1, 'analytics_ga4_id' => '']))
            ->assertSessionHasErrors('analytics_ga4_id');
    }

    public function test_invalid_ga4_id_format_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update', 'analytics'),
                $this->analyticsPayload(['analytics_ga4_enabled' => 1, 'analytics_ga4_id' => 'BADID']))
            ->assertSessionHasErrors('analytics_ga4_id');
    }

    public function test_valid_ga4_config_saved(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update', 'analytics'),
                $this->analyticsPayload(['analytics_ga4_enabled' => 1, 'analytics_ga4_id' => 'G-ABCDEF1234']))
            ->assertRedirect();

        $this->assertSame('1', Setting::get('analytics_ga4_enabled'));
        $this->assertSame('G-ABCDEF1234', Setting::get('analytics_ga4_id'));
    }

    public function test_consent_not_required_config(): void
    {
        $this->enableGa4();
        Setting::set('analytics_consent_required', '0');
        Setting::flush();
        PageCache::flush();

        $r = $this->get('/');
        $r->assertOk();
        $r->assertSee('"consentRequired":false', false);
    }

    // ---------- E) Locale ----------

    public function test_en_frontend_analytics_locale(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        $this->enableGa4();

        $r = $this->get('/en');
        $r->assertOk();
        $r->assertSee('GeoAnalytics', false);
        $r->assertSee('var LOCALE = "en"', false);
        $r->assertDontSee('var LOCALE = "zh-CN"', false);
    }

    // ---------- F) Multi-Site ----------

    public function test_analytics_isolated_across_sites(): void
    {
        $this->enableGa4();

        $b = Site::create([
            'slug' => 'acme', 'name' => 'Acme Global', 'domain' => 'acme.test',
            'status' => Site::STATUS_ACTIVE, 'is_default' => false,
            'description' => 'Acme.', 'metadata' => [],
        ]);

        SiteContext::withSite($b, function () {
            $this->seed(DefaultSettingSeeder::class);
            Setting::set('analytics_meta_enabled', '1');
            Setting::set('analytics_meta_id', '987654321');
            Setting::flush();
        });

        // A：google 域、无 facebook。
        $cspA = $this->get('http://localhost/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('googletagmanager', $cspA);
        $this->assertStringNotContainsString('facebook', $cspA);

        // B：facebook 域、无 google。
        $cspB = $this->get('http://acme.test/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('facebook', $cspB);
        $this->assertStringNotContainsString('googletagmanager', $cspB);
        $this->assertStringNotContainsString('google-analytics', $cspB);
    }

    // ---------- G) HeadCodeSanitizer ----------

    public function test_head_code_allows_meta_and_link(): void
    {
        $r = app(HeadCodeSanitizer::class)->sanitize(
            '<meta name="verify" content="abc"><link rel="canonical" href="https://example.com/">');
        $this->assertFalse($r['invalid']);
        $this->assertStringContainsString('<meta', $r['html']);
        $this->assertStringContainsString('<link', $r['html']);
    }

    public function test_head_code_strips_script_and_marks_invalid(): void
    {
        $r = app(HeadCodeSanitizer::class)->sanitize(
            '<meta name="a" content="b"><script>alert(1)</script>');
        $this->assertTrue($r['invalid']);
        $this->assertStringContainsString('<meta', $r['html']);
        $this->assertStringNotContainsString('<script', $r['html']);
    }

    public function test_head_code_rejects_javascript_href(): void
    {
        $r = app(HeadCodeSanitizer::class)->sanitize(
            '<link rel="x" href="javascript:alert(1)">');
        $this->assertTrue($r['invalid']);
        $this->assertStringNotContainsString('javascript:', $r['html']);
    }
}
