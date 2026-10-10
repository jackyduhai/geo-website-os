<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Entity;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-9　人类面 / 机器面分离断言（G-9 / G-11 surface integrity）。
 * ------------------------------------------------------------------
 * 钉死两个隐含等式不成立：
 *   HTTP 200（人类面可访问） ≠ 进入 PKS / 机器发现面
 *   进入 PKS / 机器发现面      ≠ 人类面一定有可访问路由
 *
 * 三类对照：
 *   ① noindex 已发布内容：人类面 200 可访问，但不进 /geo.json（机器面排除）。
 *   ② indexable 已发布内容：人类面 200 且进 /geo.json。
 *   ③ person 语义节点：进 /geo.json（图谱节点），但无人类独立路由（无 url）。
 */
class SurfaceSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['name' => 'Test Site', 'domain' => 'example.com']);
        SiteContext::setSite($this->site);
    }

    private function graph(): array
    {
        return $this->get('/geo.json')->assertOk()->json();
    }

    public function test_noindex_published_content_is_200_for_humans_but_absent_from_machine_graph(): void
    {
        $hidden = Content::create([
            'site_id' => $this->site->id, 'type' => 'page', 'slug' => 'hidden-page',
            'title' => 'Hidden Title', 'summary' => 'Hidden summary',
            'status' => 'published', 'published_at' => now(),
        ]);
        SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $hidden->id, 'noindex' => true,
        ]);

        // 人类面：noindex 不等于撤页，页面仍 200 可访问。
        $this->get('/hidden-page')->assertOk();

        // 机器面：不进 /geo.json。
        $slugs = array_column($this->graph()['contents'], 'slug');
        $this->assertNotContains('hidden-page', $slugs, 'noindex 内容不得进入机器发现面');
    }

    public function test_indexable_published_content_is_200_for_humans_and_present_in_machine_graph(): void
    {
        Content::create([
            'site_id' => $this->site->id, 'type' => 'page', 'slug' => 'about-page',
            'title' => 'About Title', 'summary' => 'About summary',
            'status' => 'published', 'published_at' => now(),
        ]);

        $this->get('/about-page')->assertOk();

        $slugs = array_column($this->graph()['contents'], 'slug');
        $this->assertContains('about-page', $slugs);
    }

    public function test_person_semantic_node_is_in_machine_graph_but_has_no_human_route(): void
    {
        Entity::create([
            'site_id' => $this->site->id, 'type' => 'person', 'slug' => 'lead-engineer',
            'name' => 'Lead Engineer', 'status' => 'published',
        ]);

        $node = collect($this->graph()['entities'])
            ->firstWhere('slug', 'lead-engineer');

        $this->assertNotNull($node, 'person 作为语义节点必须进入 GEO 图谱');
        $this->assertSame('person', $node['type']);
        // 无独立落地页：节点不得对外给出会 404 的 url。
        $this->assertArrayNotHasKey('url', $node, 'person 语义节点无人类独立路由');
        // 人类面 catch-all 不会把该实体解析成页面。
        $this->get('/lead-engineer')->assertNotFound();
    }
}
