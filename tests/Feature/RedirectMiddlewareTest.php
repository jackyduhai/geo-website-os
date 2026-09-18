<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleRedirects;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台「301 跳转」规则必须在前台真正执行（旧链 → 新址），
 * 覆盖 301/302、停用不生效、外链、自跳保护、尾斜杠兼容与查询串透传。
 */
class RedirectMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HandleRedirects::flushRules();
    }

    protected function tearDown(): void
    {
        HandleRedirects::flushRules();
        parent::tearDown();
    }

    public function test_active_301_rule_redirects_old_path(): void
    {
        Redirect::create([
            'from_path' => '/old-page', 'to_path' => '/products/', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/old-page')->assertRedirect(url('/products/'));
    }

    public function test_trailing_slash_variant_also_matches(): void
    {
        Redirect::create([
            'from_path' => '/old-page', 'to_path' => '/factory/', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/old-page/')->assertRedirect(url('/factory/'));
    }

    public function test_302_code_is_respected(): void
    {
        Redirect::create([
            'from_path' => '/temp', 'to_path' => '/solutions/', 'code' => 302, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/temp')->assertStatus(302)->assertRedirect(url('/solutions/'));
    }

    public function test_inactive_rule_is_not_applied(): void
    {
        Redirect::create([
            'from_path' => '/disabled-rule', 'to_path' => '/products/', 'code' => 301, 'is_active' => false,
        ]);
        HandleRedirects::flushRules();

        // 停用规则不跳转；该路径无内容，应落到 404 而不是被规则接管
        $this->get('/disabled-rule')->assertNotFound();
    }

    public function test_external_target_passes_through(): void
    {
        Redirect::create([
            'from_path' => '/go-external', 'to_path' => 'https://example.com/landing', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/go-external')->assertRedirect('https://example.com/landing');
    }

    public function test_self_loop_is_ignored(): void
    {
        Redirect::create([
            'from_path' => '/loop', 'to_path' => '/loop', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/loop')->assertNotFound();
    }

    public function test_query_string_is_forwarded_when_target_has_none(): void
    {
        Redirect::create([
            'from_path' => '/legacy', 'to_path' => '/knowledge/', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        $this->get('/legacy?id=12&t=x')->assertRedirect(url('/knowledge/').'?id=12&t=x');
    }

    public function test_admin_path_is_not_redirected(): void
    {
        Redirect::create([
            'from_path' => '/admin-panel', 'to_path' => '/products/', 'code' => 301, 'is_active' => true,
        ]);
        HandleRedirects::flushRules();

        // /admin 前缀整体放行（后台登录页由 EnsureAdmin 处理），不应被旧链规则接管
        $response = $this->get('/admin');
        $this->assertContains($response->status(), [302, 200]);
        $this->assertStringNotContainsString('/products/', (string) $response->headers->get('Location'));
    }
}
