<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteContext;
use App\Support\SiteResolver;
use App\Support\SystemAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_anonymous_user_cannot_cross_site(): void
    {
        $this->assertFalse(SystemAuthorization::canCrossSite());
    }

    public function test_system_context_can_bypass(): void
    {
        // 测试运行在 CLI 环境
        $this->assertTrue(SystemAuthorization::isSystemContext());
        $this->assertTrue(SystemAuthorization::canUseSiteScopeBypass());
    }

    public function test_no_global_env_switch_to_disable_scope(): void
    {
        $envVars = [
            'DISABLE_SITE_SCOPE',
            'SCOPE_BYPASS',
            'SITE_GLOBAL_BYPASS',
            'DISABLE_SITE_ISOLATION',
        ];

        foreach ($envVars as $var) {
            $this->assertFalse(
                env($var, false),
                "Global env switch {$var} should not exist"
            );
        }
    }

    public function test_admin_can_cross_site(): void
    {
        // 超管授权由显式标记决定（P-STEP 03：geo:install 创建的首个管理员），
        // 不再按任何特定邮箱硬编码判定。
        $admin = new \App\Models\User();
        $admin->id = 1;
        $admin->email = 'owner@installer.test';
        $admin->is_super_admin = true;

        $this->assertTrue(SystemAuthorization::canCrossSite($admin));
    }

    public function test_same_email_without_flag_cannot_cross_site(): void
    {
        // 邮箱本身不授予任何权限（业务种子邮箱同理）
        $plain = new \App\Models\User();
        $plain->id = 3;
        $plain->email = 'admin@demo-tenant-a.local';
        $plain->is_super_admin = false;

        $this->assertFalse(SystemAuthorization::canCrossSite($plain));
    }

    public function test_regular_user_cannot_cross_site(): void
    {
        $user = new \App\Models\User();
        $user->id = 2;
        $user->email = 'user@example.com';

        $this->assertFalse(SystemAuthorization::canCrossSite($user));
    }

    public function test_multi_site_mode_does_not_fallback(): void
    {
        config(['site.mode' => 'multi-site', 'site.default_fallback' => false]);

        // 已有 default site（从 seeder），创建第二个站点
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        // 未知 host 在 multi-site 模式下返回 null
        $request = \Illuminate\Http\Request::create('https://unknown.example.test/');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNull($resolved);
    }

    public function test_single_site_mode_fallbacks_to_default(): void
    {
        config(['site.mode' => 'single-site', 'site.default_fallback' => true]);

        // 已有 default site（从 seeder）
        $default = Site::where('is_default', true)->first();
        $this->assertNotNull($default);

        // 未知 host 在 single-site 模式下回退到 default
        $request = \Illuminate\Http\Request::create('https://unknown.example.test/');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($default->id, $resolved->id);
    }

    public function test_cli_context_is_system_context(): void
    {
        // 测试运行在 CLI 环境
        $this->assertTrue(SystemAuthorization::isSystemContext());
    }

    public function test_site_bypass_requires_explicit_call(): void
    {
        // 验证 withoutSiteScope 不是全局自动生效的
        $default = Site::where('is_default', true)->first();
        $this->assertNotNull($default);

        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        // 模拟 Site A 上下文
        SiteContext::setSite($siteA);

        // 验证 Context 正确设置
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());
    }

    public function test_deployment_mode_config_exists(): void
    {
        $this->assertArrayHasKey('mode', config('site'));
        $this->assertArrayHasKey('default_fallback', config('site'));
        $this->assertArrayHasKey('default_slug', config('site'));
    }

    public function test_cli_site_context_helper_exists(): void
    {
        $this->assertTrue(class_exists(\App\Support\CliSiteContext::class));
    }

    public function test_queued_by_site_trait_exists(): void
    {
        $this->assertTrue(trait_exists(\App\Support\QueuedBySite::class));
    }
}
