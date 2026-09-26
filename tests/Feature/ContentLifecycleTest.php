<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2c：Content 生命周期与关系同步矩阵。
 * draft/published/unpublished/deleted → 页面/Search/GEO/关系同步，防残留。
 */
class ContentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
    }

    private function makeArticle(string $status): Content
    {
        $c = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'slug' => 'life-art',
            'title' => 'Life Article', 'status' => $status, 'locale' => 'zh-CN',
            'body' => '正文', 'published_at' => $status === 'published' ? now() : null,
        ]);
        $prod = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'life-prod', 'name' => 'Life Prod', 'status' => 'published', 'locale' => 'zh-CN']);
        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT]);
        return $c;
    }

    private function geoEdgeExists(): bool
    {
        $g = $this->get('/geo.json')->json();
        return collect($g['relations'] ?? [])->contains(
            fn ($r) => str_contains((string) $r['from'], 'content/article/life-art')
        );
    }

    public function test_draft_not_public_not_in_geo(): void
    {
        $this->makeArticle('draft');
        $this->assertFalse($this->geoEdgeExists(), 'draft 内容不得进 geo.json');
    }

    public function test_published_in_geo_then_archived_removed(): void
    {
        $c = $this->makeArticle('published');
        $this->assertTrue($this->geoEdgeExists(), 'published 须进 geo.json');

        $c->update(['status' => 'draft']);
        $this->assertFalse($this->geoEdgeExists(), '下线后 geo.json 不得残留 content 边');
    }

    public function test_deleted_removes_geo_edge(): void
    {
        $c = $this->makeArticle('published');
        $this->assertTrue($this->geoEdgeExists());
        $c->delete();
        $this->assertFalse($this->geoEdgeExists(), '删除后 geo.json 不得残留');
    }
}
