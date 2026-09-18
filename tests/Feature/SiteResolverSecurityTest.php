<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteContext;
use App\Support\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 5.4-H 补证：SiteResolver 安全边界测试
 */
class SiteResolverSecurityTest extends TestCase
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
    public function host_a_resolves_to_site_a(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
        ]);

        $request = Request::create('https://a.example.test', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($siteA->id, $resolved->id);
    }

    /** @test */
    public function host_b_resolves_to_site_b(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.example.test',
            'status' => 'active',
        ]);

        $request = Request::create('https://b.example.test', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($siteB->id, $resolved->id);
    }

    /** @test */
    public function host_a_with_site_slug_b_does_not_switch_to_site_b(): void
    {
        // 安全测试：普通 HTTP 请求中 ?site_slug=B 不能覆盖 Host=A
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.example.test',
            'status' => 'active',
        ]);

        // 普通请求：Host=A + ?site_slug=B → 必须仍然是 A
        $request = Request::create('https://a.example.test?site_slug=site-b', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($siteA->id, $resolved->id, '普通请求中 site_slug 不能覆盖 Host！');
    }

    /** @test */
    public function admin_path_can_use_site_slug(): void
    {
        // 只有 admin 路径下才允许使用 site_slug
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.test',
            'status' => 'active',
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.example.test',
            'status' => 'active',
        ]);

        // admin 请求：Host=A + /admin?site_slug=B → 可以切换到 B
        $request = Request::create('https://a.example.test/admin/dashboard?site_slug=site-b', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($siteB->id, $resolved->id, 'admin 路径下应该可以使用 site_slug');
    }

    /** @test */
    public function unknown_host_with_fallback_true_returns_default(): void
    {
        config(['site.default_fallback' => true]);

        $request = Request::create('https://unknown.example.test', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNotNull($resolved);
        $this->assertEquals('default', $resolved->slug);
    }

    /** @test */
    public function unknown_host_with_fallback_false_returns_null(): void
    {
        config(['site.default_fallback' => false]);

        $request = Request::create('https://unknown.example.test', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNull($resolved, '多站点生产模式下未知 Host 应该返回 null');
    }

    /** @test */
    public function inactive_site_domain_does_not_resolve(): void
    {
        $inactive = Site::create([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'domain' => 'inactive.example.test',
            'status' => 'inactive',
        ]);

        config(['site.default_fallback' => false]);

        $request = Request::create('https://inactive.example.test', 'GET');
        $resolved = SiteResolver::resolveFromRequest($request);

        $this->assertNull($resolved, 'inactive site 不应该被解析');
    }

    /** @test */
    public function only_one_default_site_allowed_by_database(): void
    {
        // 第一个 default 已经由 migration seed 创建
        $this->expectException(\Illuminate\Database\QueryException::class);

        Site::create([
            'name' => 'Second Default',
            'slug' => 'second-default',
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    /** @test */
    public function http_request_clears_site_context(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertFalse(SiteContext::hasSite(), '请求结束后 Context 应该被清理');
    }

    /** @test */
    public function http_request_exception_clears_site_context(): void
    {
        // 访问不存在的页面触发异常
        $this->get('/nonexistent-page-12345');

        $this->assertFalse(SiteContext::hasSite(), '异常请求结束后 Context 应该被清理');
    }
}
