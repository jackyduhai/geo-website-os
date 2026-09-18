<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.4-G 补证：SiteContext 生命周期与安全边界测试
 */
class SiteContextTest extends TestCase
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
    public function default_site_is_used_when_not_set(): void
    {
        // 创建 default site（migrate:fresh --seed 会自动创建）
        $this->assertNotNull(SiteContext::currentSite());
        $this->assertEquals(1, SiteContext::currentSiteId());
    }

    /** @test */
    public function set_site_changes_context(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::setSite($siteA);
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());
        $this->assertTrue(SiteContext::hasSite());

        SiteContext::setSite($siteB);
        $this->assertEquals($siteB->id, SiteContext::currentSiteId());
    }

    /** @test */
    public function clear_resets_to_default(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);

        SiteContext::setSite($siteA);
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());

        SiteContext::clear();
        $this->assertEquals(1, SiteContext::currentSiteId());
    }

    /** @test */
    public function with_site_restores_previous_context_after_callback(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::setSite($siteA);

        SiteContext::withSite($siteB, function () {
            $this->assertEquals(Site::where('slug', 'site-b')->first()->id, SiteContext::currentSiteId());
        });

        // 回调结束后恢复到 A
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());
    }

    /** @test */
    public function with_site_restores_previous_context_on_exception(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::setSite($siteA);

        try {
            SiteContext::withSite($siteB, function () {
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            // 异常被重新抛出
        }

        // 即使异常也恢复到 A
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());
    }

    /** @test */
    public function nested_with_site_restores_correct_context(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);
        $siteC = Site::create(['name' => 'C', 'slug' => 'site-c', 'status' => 'active']);

        SiteContext::setSite($siteA);

        SiteContext::withSite($siteB, function () use ($siteB, $siteC) {
            $this->assertEquals($siteB->id, SiteContext::currentSiteId());

            SiteContext::withSite($siteC, function () {
                $this->assertEquals(Site::where('slug', 'site-c')->first()->id, SiteContext::currentSiteId());
            });

            $this->assertEquals(Site::where('slug', 'site-b')->first()->id, SiteContext::currentSiteId());
        });

        // 最外层恢复到 A
        $this->assertEquals($siteA->id, SiteContext::currentSiteId());
    }

    /** @test */
    public function has_site_returns_false_when_not_set(): void
    {
        SiteContext::clear();
        $this->assertFalse(SiteContext::hasSite());

        // currentSite() 会自动绑定 default
        $this->assertNotNull(SiteContext::currentSite());
    }
}
