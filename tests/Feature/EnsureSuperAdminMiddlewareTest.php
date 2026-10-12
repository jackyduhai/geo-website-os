<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * EnsureSuperAdmin 中间件直接契约测试（Phase 2C.1，关闭 SEC-SUPER-01）。
 *
 * 经真实路由时 EnsureAdmin 先于本中间件执行，故 guest 重定向分支在路由链上不可达；
 * 这里直接调用中间件，覆盖其 defense-in-depth 的 guest 重定向与非超管 403 分支。
 */
class EnsureSuperAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function pass(): \Closure
    {
        return fn () => response('PASS-THROUGH');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => null);

        $response = (new EnsureSuperAdmin())->handle($request, $this->pass());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('admin.login'), $response->getTargetUrl());
        $this->assertSame('请先登录后台', $response->getSession()->get('error'));
    }

    public function test_non_super_admin_gets_403(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        Auth::login($user);
        $request = Request::create('/admin', 'GET');

        // 直接调用中间件时 abort(403) 抛 HttpException（HTTP 内核测试中会转为 403 响应）
        try {
            (new EnsureSuperAdmin())->handle($request, $this->pass());
            $this->fail('非超级管理员应被拒绝');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('仅超级管理员', $e->getMessage());
        }
    }

    public function test_super_admin_passes_through(): void
    {
        $super = User::factory()->create(['is_super_admin' => true]);
        Auth::login($super);
        $request = Request::create('/admin', 'GET');

        $response = (new EnsureSuperAdmin())->handle($request, $this->pass());

        $this->assertSame('PASS-THROUGH', $response->getContent());
    }
}
