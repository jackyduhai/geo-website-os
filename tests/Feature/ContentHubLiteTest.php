<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Site;
use App\Models\Tag;
use App\Services\Geo\SchemaBuilder;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2c Content Hub Lite 契约：
 * Tag（site-scoped、locale-independent identity）+ Content↔Entity（about/mention）
 * + Schema about/mentions + geo.json 边 + 搜索聚合。单链路，无第二套体系。
 */
class ContentHubLiteTest extends TestCase
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

    private function content(string $slug): Content
    {
        return Content::create([
            'site_id' => $this->site->id, 'type' => 'article',
            'slug' => $slug, 'title' => '储能系统趋势', 'status' => 'published',
            'locale' => 'zh-CN', 'body' => '正文关于储能管理系统', 'summary' => '摘要',
        ]);
    }

    public function test_tag_is_site_scoped(): void
    {
        $tagA = Tag::create(['site_id' => $this->site->id, 'name' => '储能', 'slug' => 'chuneng']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'domain' => 'b.test', 'status' => 'active', 'is_default' => false]);
        $tagB = Tag::create(['site_id' => $siteB->id, 'name' => '储能', 'slug' => 'chuneng']);

        // 同名 slug 在两站各自独立
        $this->assertNotEquals($tagA->id, $tagB->id);
        $this->assertSame(1, Tag::where('site_id', $this->site->id)->count());
    }

    public function test_tag_attach_content_and_delete_keeps_content(): void
    {
        $c = $this->content('tagged-article');
        $tag = Tag::create(['site_id' => $this->site->id, 'name' => '工业', 'slug' => 'gongye']);
        $c->tags()->attach($tag->id);

        $this->assertSame(1, $c->tags()->count());
        // 删除 tag 仅清关系，不删内容
        $tag->delete();
        $this->assertNotNull(Content::find($c->id));
    }

    public function test_content_entity_about_relation_reverse(): void
    {
        $c = $this->content('ce-article');
        $prod = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'ess-system', 'name' => '储能管理系统', 'status' => 'published', 'locale' => 'zh-CN']);
        $ind = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_TOPIC,
            'slug' => 'new-energy', 'name' => '新能源', 'status' => 'published', 'locale' => 'zh-CN']);

        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT]);
        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $ind->id, 'relation_type' => ContentEntity::RELATION_MENTION]);

        $about = $c->relatedEntities(ContentEntity::RELATION_ABOUT);
        $this->assertTrue($about->contains(fn ($e) => $e->slug === 'ess-system'));
    }

    public function test_article_schema_has_about_and_mentions(): void
    {
        $c = $this->content('schema-article');
        $prod = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'ess-p', 'name' => 'ESS', 'status' => 'published', 'locale' => 'zh-CN']);
        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT]);

        $schema = app(SchemaBuilder::class)->article($c);
        $this->assertSame('Article', $schema['@type']);
        $this->assertNotEmpty($schema['about'] ?? []);
        $this->assertSame('Product', $schema['about'][0]['@type']);
    }

    public function test_geo_graph_has_content_to_entity_edge(): void
    {
        $c = $this->content('geo-article');
        $prod = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'ess-geo', 'name' => 'ESS Geo', 'status' => 'published', 'locale' => 'zh-CN']);
        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT]);

        $graph = $this->get('/geo.json')->assertOk()->json();
        $edge = collect($graph['relations'])->firstWhere(
            fn ($r) => str_contains((string) $r['from'], 'content/article/geo-article')
                && str_contains((string) $r['to'], 'entity/product/ess-geo')
        );
        $this->assertNotNull($edge, 'geo.json 须含 content→entity 边');
    }

    public function test_search_index_includes_tag_and_entity_names(): void
    {
        $c = $this->content('search-article');
        $tag = Tag::create(['site_id' => $this->site->id, 'name' => '储能应用', 'slug' => 'cnyy']);
        $c->tags()->attach($tag->id);
        $prod = Entity::create(['site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'ess-search', 'name' => '储能管理系统X', 'status' => 'published', 'locale' => 'zh-CN']);
        ContentEntity::create(['site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT]);

        $doc = app(\App\Support\Search\SearchIndexBuilder::class)->documentForContent($c);
        $this->assertStringContainsString('储能应用', $doc['body']);
        $this->assertStringContainsString('储能管理系统X', $doc['body']);
    }
}
