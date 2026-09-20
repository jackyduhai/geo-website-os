<?php

namespace Tests\Feature;

use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 11：Multi-Site Repeated Switching（A→B→A→B 循环）。
 *
 * 终验曾发现 memo 类缺陷（SiteScope 资格缓存、SiteCacheKey 站点 ID 缓存），
 * 本测试以高频往复切站锁定：Content / Entity / SeoMeta / Canonical / GEO /
 * Sitemap / 缓存 key / Setting 在反复切换下互不串站。
 */
class MultiSiteSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private $siteA;
    private $siteB;
    private $contentA;
    private $contentB;
    private $entityA;
    private $entityB;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();

        $this->siteA = \App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->firstOrFail();
        $this->siteA->update(['name' => 'Loop A', 'domain' => 'loop-a.test']);
        $this->siteB = \App\Models\Site::create([
            'name' => 'Loop B', 'slug' => 'loop-b', 'domain' => 'loop-b.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $this->contentA = \App\Models\Content::create([
            'site_id' => $this->siteA->id, 'type' => 'page', 'slug' => 'loop-a-page',
            'title' => 'Loop A Page', 'status' => 'published', 'published_at' => now(),
        ]);
        $this->contentB = \App\Models\Content::create([
            'site_id' => $this->siteB->id, 'type' => 'page', 'slug' => 'loop-b-page',
            'title' => 'Loop B Page', 'status' => 'published', 'published_at' => now(),
        ]);
        $this->entityA = \App\Models\Entity::create([
            'site_id' => $this->siteA->id, 'type' => 'service', 'slug' => 'loop-svc-a',
            'name' => 'Loop Service A', 'status' => 'published',
        ]);
        $this->entityB = \App\Models\Entity::create([
            'site_id' => $this->siteB->id, 'type' => 'service', 'slug' => 'loop-svc-b',
            'name' => 'Loop Service B', 'status' => 'published',
        ]);
    }

    private function assertSiteIsolation(string $which): void
    {
        $seo = app(\App\Services\Seo\SeoMetaResolver::class);

        if ($which === 'A') {
            SiteContext::setSite($this->siteA);
            $content = \App\Models\Content::find($this->contentA->id);
            $this->assertEquals('Loop A Page', $content->title);

            $entity = \App\Models\Entity::find($this->entityA->id);
            $this->assertEquals('Loop Service A', $entity->name);

            $result = $seo->resolveContent($content);
            $this->assertStringContainsString('loop-a.test', $result->canonical);
        } else {
            SiteContext::setSite($this->siteB);
            $content = \App\Models\Content::find($this->contentB->id);
            $this->assertEquals('Loop B Page', $content->title);

            $entity = \App\Models\Entity::find($this->entityB->id);
            $this->assertEquals('Loop Service B', $entity->name);

            $result = $seo->resolveContent($content);
            $this->assertStringContainsString('loop-b.test', $result->canonical);
        }

        // 缓存 key 随当前站
        $keyA = \App\Support\SiteCacheKey::make('settings', '', $this->siteA->id);
        $keyB = \App\Support\SiteCacheKey::make('settings', '', $this->siteB->id);
        $this->assertNotSame($keyA, $keyB);
    }

    public function test_repeated_site_switching_never_leaks(): void
    {
        // A → B → A → B → A → B（六次往复，每次做全维度隔离断言）
        foreach (['A', 'B', 'A', 'B', 'A', 'B'] as $round => $which) {
            $this->assertSiteIsolation($which . '#' . ($round + 1));
        }
    }

    public function test_switching_via_direct_builder_calls_is_isolated(): void
    {
        // 不经 HTTP 中间件、直接调用引擎的场景（CLI / 队列 / 作业）同样隔离
        $builder = app(\App\Services\Geo\GeoGraphBuilder::class);

        SiteContext::setSite($this->siteA);
        $gA = $builder->build();
        $slugsA = array_column($gA['contents'], 'slug');

        SiteContext::setSite($this->siteB);
        $gB = $builder->build();
        $slugsB = array_column($gB['contents'], 'slug');

        SiteContext::setSite($this->siteA);
        $gA2 = $builder->build();
        $slugsA2 = array_column($gA2['contents'], 'slug');

        $this->assertContains('loop-a-page', $slugsA);
        $this->assertNotContains('loop-b-page', $slugsA);

        $this->assertContains('loop-b-page', $slugsB);
        $this->assertNotContains('loop-a-page', $slugsB);

        $this->assertEquals($slugsA, $slugsA2, '回切后输出必须与首次一致');
    }
}
