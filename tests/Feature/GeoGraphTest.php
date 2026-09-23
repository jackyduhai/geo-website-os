<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Fact;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 06：GEO / LLM Discoverability。
 *
 * /geo.json 是统一可机器读取结构，数据源边界冻结：
 *   - 主体与关系：entities + entity_relations（正式显式关系，禁止隐式推断）
 *   - 内容：published contents，标题/摘要经 SeoMetaResolver 统一 Resolution
 *   - 事实：facts 表 is_public 行（含来源与核定时间；结构只引用不创造事实）
 *   - SEO：SeoMeta 覆盖必须反映到输出（SeoMeta → Metadata 连通）
 *   - 多站隔离：Site B 的实体/关系/内容不得进入 Site A 的结构
 */
class GeoGraphTest extends TestCase
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

    public function test_graph_contains_site_facts_entities_relations_contents(): void
    {
        $graph = $this->graph();

        $this->assertSame('geo-os/graph/v1', $graph['$schema']);
        $this->assertSame('Test Site', $graph['site']['name']);
        $this->assertSame('https://example.com/', $graph['site']['url']);
        foreach (['facts', 'entities', 'relations', 'contents'] as $section) {
            $this->assertArrayHasKey($section, $graph);
            $this->assertIsArray($graph[$section]);
        }
    }

    public function test_facts_come_from_formal_fact_store_with_source_and_review(): void
    {
        Fact::create([
            'key'         => 'FACT-TEST-001',
            'label'       => 'Founded',
            'value'       => '2017-03',
            'group'       => 'company',
            'source'      => 'Business License',
            'reviewed_at' => '2026-01-01',
            'review_due'  => '2026-07-01',
            'is_public'   => true,
        ]);
        // 非公开事实不得输出
        Fact::create([
            'key' => 'FACT-TEST-SECRET', 'label' => 'Secret', 'value' => 'x',
            'is_public' => false,
        ]);

        $graph = $this->graph();

        $keys = array_column($graph['facts'], 'key');
        $this->assertContains('FACT-TEST-001', $keys);
        $this->assertNotContains('FACT-TEST-SECRET', $keys);

        $row = $graph['facts'][array_search('FACT-TEST-001', $keys, true)];
        $this->assertSame('Business License', $row['source']);
        $this->assertSame('2026-01-01', $row['reviewed_at']);
    }

    public function test_entities_use_resolver_seo_and_relations_are_explicit_only(): void
    {
        $alice = Entity::create([
            'site_id' => $this->site->id, 'type' => 'person', 'slug' => 'alice',
            'name' => 'Alice', 'summary' => 'Founder profile', 'status' => 'published',
        ]);
        $org = Entity::create([
            'site_id' => $this->site->id, 'type' => 'organization', 'slug' => 'org',
            'name' => 'Org', 'status' => 'published',
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id,
            'from_entity_id' => $org->id, 'to_entity_id' => $alice->id,
            'relation_type' => 'founder', 'sort_order' => 1,
        ]);
        \App\Models\SeoMeta::create([
            'site_id' => $this->site->id, 'entity_id' => $alice->id,
            'title' => 'Alice Seo Title',
        ]);

        $graph = $this->graph();

        $aliceNode = collect($graph['entities'])->firstWhere('slug', 'alice');
        $this->assertSame('Alice Seo Title', $aliceNode['name'], 'SeoMeta 覆盖必须反映到 GEO 输出');
        // person 无前台落地页：仍是图谱 / 关系节点，但不得输出会 404 的 url（Public Render Contract）
        $this->assertArrayNotHasKey('url', $aliceNode);

        $this->assertCount(1, $graph['relations']);
        $this->assertSame('entity/organization/org', $graph['relations'][0]['from']);
        $this->assertSame('entity/person/alice', $graph['relations'][0]['to']);
        $this->assertSame('founder', $graph['relations'][0]['relation_type']);
    }

    public function test_draft_entities_and_relation_orphans_are_excluded(): void
    {
        $published = Entity::create([
            'site_id' => $this->site->id, 'type' => 'product', 'slug' => 'live',
            'name' => 'Live', 'status' => 'published',
        ]);
        $draft = Entity::create([
            'site_id' => $this->site->id, 'type' => 'product', 'slug' => 'ghost',
            'name' => 'Ghost', 'status' => 'draft',
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id,
            'from_entity_id' => $published->id, 'to_entity_id' => $draft->id,
            'relation_type' => 'related', 'sort_order' => 1,
        ]);

        $graph = $this->graph();

        $slugs = array_column($graph['entities'], 'slug');
        $this->assertContains('live', $slugs);
        $this->assertNotContains('ghost', $slugs, 'draft 主体不得进入 GEO 结构');
        // 关系指向 draft 主体 → 两端未全部公开，整条不输出
        $this->assertCount(0, $graph['relations']);
    }

    public function test_site_isolation_for_entities_relations_and_contents(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);

        $entityA = Entity::create([
            'site_id' => $this->site->id, 'type' => 'service', 'slug' => 'svc-a',
            'name' => 'Service A', 'status' => 'published',
        ]);
        $entityB1 = Entity::create([
            'site_id' => $siteB->id, 'type' => 'service', 'slug' => 'svc-b1',
            'name' => 'Service B1', 'status' => 'published',
        ]);
        $entityB2 = Entity::create([
            'site_id' => $siteB->id, 'type' => 'service', 'slug' => 'svc-b2',
            'name' => 'Service B2', 'status' => 'published',
        ]);
        // 跨站关系在模型层即被禁止（架构守卫）；这里验证 Site B 内部关系不出现在 A 的图中
        EntityRelation::create([
            'site_id' => $siteB->id,
            'from_entity_id' => $entityB1->id, 'to_entity_id' => $entityB2->id,
            'relation_type' => 'related', 'sort_order' => 1,
        ]);
        \App\Models\Content::create([
            'site_id' => $siteB->id, 'type' => 'page', 'slug' => 'page-b',
            'title' => 'Page B', 'status' => 'published', 'published_at' => now(),
        ]);

        $graph = $this->graph();

        $slugs = array_column($graph['entities'], 'slug');
        $this->assertContains('svc-a', $slugs);
        $this->assertNotContains('svc-b1', $slugs);
        $this->assertNotContains('svc-b2', $slugs);
        $this->assertCount(0, $graph['relations'], 'Site B 的关系不得进入 Site A 的结构');
        $contentSlugs = array_column($graph['contents'], 'slug');
        $this->assertNotContains('page-b', $contentSlugs);
    }

    public function test_indexable_content_flows_into_graph_and_noindex_content_is_excluded(): void
    {
        // 公开单页：进入 geo.json，标题/摘要走统一 Resolution，canonical 为真实落地路径
        $visible = \App\Models\Content::create([
            'site_id' => $this->site->id, 'type' => 'page', 'slug' => 'about-page',
            'title' => 'About Title', 'summary' => 'About summary',
            'status' => 'published', 'published_at' => now(),
        ]);
        // noindex 唯一来源 SeoMeta：noindex 内容不得进入公开图谱（Public Render Contract）
        $hidden = \App\Models\Content::create([
            'site_id' => $this->site->id, 'type' => 'page', 'slug' => 'hidden-page',
            'title' => 'Hidden Title', 'summary' => 'Hidden summary',
            'status' => 'published', 'published_at' => now(),
        ]);
        \App\Models\SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $hidden->id, 'noindex' => true,
        ]);

        $contents = $this->graph()['contents'];
        $slugs = array_column($contents, 'slug');

        $this->assertContains('about-page', $slugs);
        $this->assertNotContains('hidden-page', $slugs, 'noindex 内容不得进入 /geo.json');

        $node = collect($contents)->firstWhere('slug', 'about-page');
        $this->assertSame('About Title', $node['title'], '内容标题走统一 Resolution 链');
        $this->assertSame('About summary', $node['description']);
        $this->assertFalse($node['noindex']);
        // 无栏目单页真实落地路径为 /{slug}（catch-all 渲染），不再是旧的 /page/{slug}
        $this->assertSame('https://example.com/about-page', $node['canonical']);
        $this->assertSame('https://example.com/about-page', $node['url']);
    }

    public function test_graph_emits_unescaped_unicode_for_ai_friendly_output(): void
    {
        Entity::create([
            'site_id' => $this->site->id, 'type' => 'organization', 'slug' => 'example-org',
            'name' => '示例制造有限公司', 'status' => 'published',
        ]);

        $raw = $this->get('/geo.json')->assertOk()->getContent();

        $this->assertStringContainsString('示例制造有限公司', $raw, 'geo.json 中文应直出（JSON_UNESCAPED_UNICODE），便于 AI 直接读取');
        $this->assertStringNotContainsString('\u793a', $raw, '中文不应被 Unicode 转义');
    }
}
