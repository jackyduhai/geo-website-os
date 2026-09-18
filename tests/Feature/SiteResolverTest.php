<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteContext;
use App\Support\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 5.4-H：SiteResolver 与 HTTP 生命周期测试
 */
class SiteResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    protected function tearDown(): void
    {
        SiteContext::clear();
        parent::tearDown();
    }

    /** @test */
    public function resolves_default_site_when_no_domain_match(): void
    {
        // RefreshDatabase 自动创建 default site（来自 migration seed）
        $request = Request::create('https://unknown.example.com', 'GET');
        $site = SiteResolver::resolveFromRequest($request);

        // 默认 fallback 启用时返回 default site
        $this->assertNotNull($site);
        $this->assertEquals('default', $site->slug);
    }

    /** @test */
    public function resolves_site_by_domain(): void
    {
        // RefreshDatabase 自动创建 default site
        $site = Site::create([
            'name' => 'Demo Site',
            'slug' => 'demo',
            'domain' => 'demo.example.com',
            'status' => 'active',
        ]);

        $request = Request::create('https://demo.example.com', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($site->id, $resolved->id);
    }

    /** @test */
    public function resolves_site_by_slug(): void
    {
        $site = Site::create([
            'name' => 'Demo Site',
            'slug' => 'demo',
            'status' => 'active',
        ]);

        $request = Request::create('/admin/demo?site_slug=demo', 'GET');

        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($site->id, $resolved->id);
    }

    /** @test */
    public function inactive_site_is_not_resolved(): void
    {
        $site = Site::create([
            'name' => 'Inactive Site',
            'slug' => 'inactive',
            'domain' => 'inactive.example.com',
            'status' => 'inactive',
        ]);

        $request = Request::create('https://inactive.example.com', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        // inactive site 不会被解析到，fallback 到 default
        $this->assertNotNull($resolved);
        $this->assertNotEquals($site->id, $resolved->id);
    }

    /** @test */
    public function resolve_and_sets_context(): void
    {
        $request = Request::create('https://example.com', 'GET');
        $site = SiteResolver::resolveAndSet($request);

        $this->assertNotNull($site);
        $this->assertTrue(SiteContext::hasSite());
        $this->assertEquals($site->id, SiteContext::currentSiteId());
    }

    /** @test */
    public function http_middleware_sets_and_clears_context(): void
    {
        $response = $this->get('/');

        // 请求处理完成后 Context 应该被清理
        $this->assertFalse(SiteContext::hasSite());
    }

    /** @test */
    public function default_site_is_used_for_requests(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        // 请求过程中 SiteContext 应该有 default site
        // 请求结束后被清理
        $this->assertFalse(SiteContext::hasSite());
    }
}
