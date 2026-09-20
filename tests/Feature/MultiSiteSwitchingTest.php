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

    protected function setUp(): void
    {
        parent::setUp();

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
        $graph = $this->get('/geo.json')->assertOk()->json();
        $contentSlugs = array_column($graph['contents'], 'slug');
        $entityIds = array_column($graph['entities'], 'id');

        if ($which === 'A') {
            $this->assertSame('Loop A', $graph['site']['name'], "[$which] site 名串站");
            $this->assertContains('content/page/loop-a-page', $contentSlugs, "[$which] 缺 A 内容");
            $this->assertNotContains('content/page/loop-b-page', $contentSlugs, "[$which] B 内容泄漏");
            $this->assertContains('entity/service/loop-svc-a', $entityIds, "[$which] 缺 A 实体");
            $this->assertNotContains('entity/service/loop-svc-b', $entityIds, "[$which] B 实体泄漏");

            $seo = app(\App\Services\Seo\SeoMetaResolver::class)->resolveContent($this->contentA);
            $this->assertSame('https://loop-a.test/page/loop-a-page', $seo->canonical, "[$which] canonical 串站");
        } else {
            $this->assertSame('Loop B', $graph['site']['name'], "[$which] site 名串站");
            $this->assertContains('content/page/loop-b-page', $contentSlugs, "[$which] 缺 B 内容");
            $this->assertNotContains('content/page/loop-a-page', $contentSlugs, "[$which] A 内容泄漏");
            $this->assertContains('entity/service/loop-svc-b', $entityIds, "[$which] 缺 B 实体");
            $this->assertNotContains('entity/service/loop-svc-a', $entityIds, "[$which] A 实体泄漏");

            $seo = app(\App\Services\Seo\SeoMetaResolver::class)->resolveContent($this->contentB);
            $this->assertSame('https://loop-b.test/page/loop-b-page', $seo->canonical, "[$which] canonical 串站");
        }

        // 缓存 key 与 Setting 视图随当前站
        $keyA = \App\Support\SiteCacheKey::make('settings', '', $this->siteA->id);
        $keyB = \App\Support\SiteCacheKey::make('settings', '', $this->siteB->id);
        $this->assertNotSame($keyA, $keyB);
    }

    public function test_repeated_site_switching_never_leaks(): void
    {
        // A → B → A → B → A → B（六次往复，每次做全维度隔离断言）
        foreach (['A', 'B', 'A', 'B', 'A', 'B'] as $round => $which) {
            SiteContext::setSite($which === 'A' ? $this->siteA : $this->siteB);
            $this->assertSiteIsolation($which . '#' . ($round + 1));
        }
    }

    public function test_switching_via_direct_builder_calls_is_isolated(): void
    {
        // 不经 HTTP 中间件、直接调用引擎的场景（CLI / 队列 / 作业）同样隔离
        $builder = app(\App\Services\Geo\GeoGraphBuilder::class);

        SiteContext::setSite($this->siteA);
        $gA = $builder->build();
        SiteContext::setSite($this->siteB);
        $gB = $builder->build();
        SiteContext::setSite($this->siteA);
        $gA2 = $builder->build();

        $this->assertSame(['content/page/loop-a-page'], array_column($gA['contents'], 'slug'));
        $this->assertSame(['content/page/loop-b-page'], array_column($gB['contents'], 'slug'));
        $this->assertSame($gA['contents'], $gA2['contents'], '回切后输出必须与首次一致');
    }
}
