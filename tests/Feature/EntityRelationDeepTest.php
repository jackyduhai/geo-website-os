<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityRelationDeepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_relation_semantics_from_to_type(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'type' => 'service',
            'slug' => 'fried-chicken-service',
            'name' => 'Fried Chicken Service',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'type' => 'product',
            'slug' => 'crispy-breading',
            'name' => 'Crispy Breading',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $product->id,
            'relation_type' => 'uses',
        ]);

        $this->assertEquals($service->id, $relation->from_entity_id);
        $this->assertEquals($product->id, $relation->to_entity_id);
        $this->assertEquals('uses', $relation->relation_type);
    }

    public function test_relation_lifecycle_create_read_update_delete(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-lifecycle',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-lifecycle',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);
        $this->assertNotNull($relation->id);

        $found = EntityRelation::find($relation->id);
        $this->assertEquals('produces', $found->relation_type);

        $found->update(['relation_type' => 'offers']);
        $this->assertEquals('offers', $found->fresh()->relation_type);

        $found->delete();
        $this->assertNull(EntityRelation::find($relation->id));
    }

    public function test_duplicate_relation_rejected(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-dup',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-dup',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);
    }

    public function test_reverse_relation_allowed(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-rev',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-rev',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);

        $reverse = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $prod->id,
            'to_entity_id' => $org->id,
            'relation_type' => 'related_to',
        ]);

        $this->assertNotNull($reverse->id);
    }

    public function test_self_reference_allowed(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $topic = Entity::create([
            'type' => 'topic',
            'slug' => 'parent-topic',
            'name' => 'Parent Topic',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $topic->id,
            'to_entity_id' => $topic->id,
            'relation_type' => 'related_to',
        ]);

        $this->assertEquals($topic->id, $relation->from_entity_id);
        $this->assertEquals($topic->id, $relation->to_entity_id);
    }

    public function test_entity_delete_cascades_relations(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-cascade',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-cascade',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);

        $relationId = $relation->id;

        $org->delete();

        $this->assertNull(EntityRelation::find($relationId));
    }

    public function test_outgoing_relations_query(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'type' => 'service',
            'slug' => 'service-out',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $prod1 = Entity::create([
            'type' => 'product',
            'slug' => 'prod-out-1',
            'name' => 'Prod 1',
            'status' => 'published',
        ]);

        $prod2 = Entity::create([
            'type' => 'product',
            'slug' => 'prod-out-2',
            'name' => 'Prod 2',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $prod1->id,
            'relation_type' => 'uses',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $prod2->id,
            'relation_type' => 'uses',
        ]);

        $outgoing = $service->relationsFrom;
        $this->assertEquals(2, $outgoing->count());
    }

    public function test_incoming_relations_query(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org1 = Entity::create([
            'type' => 'organization',
            'slug' => 'org-in-1',
            'name' => 'Org 1',
            'status' => 'published',
        ]);

        $org2 = Entity::create([
            'type' => 'organization',
            'slug' => 'org-in-2',
            'name' => 'Org 2',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-in',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org1->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org2->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'offers',
        ]);

        $incoming = $prod->relationsTo;
        $this->assertEquals(2, $incoming->count());
    }

    public function test_related_entities_eager_loading(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'type' => 'service',
            'slug' => 'service-eager',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-eager',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'uses',
        ]);

        $loaded = Entity::with(['relationsFrom.toEntity'])->find($service->id);

        $this->assertTrue($loaded->relationsFrom->first()->relationLoaded('toEntity'));
        $this->assertEquals('prod-eager', $loaded->relationsFrom->first()->toEntity->slug);
    }

    public function test_relation_site_isolation(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-rel',
            'domain' => 'b-rel.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteB, function () use ($siteB) {
            $org = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'organization',
                'slug' => 'b-org-rel',
                'name' => 'B Org',
                'status' => 'published',
            ]);

            $prod = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-prod-rel',
                'name' => 'B Prod',
                'status' => 'published',
            ]);

            EntityRelation::create([
                'site_id' => $siteB->id,
                'from_entity_id' => $org->id,
                'to_entity_id' => $prod->id,
                'relation_type' => 'produces',
            ]);
        });

        SiteContext::setSite($siteA);
        $relations = EntityRelation::all();
        $this->assertEmpty($relations);
    }

    public function test_relation_metadata_json(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-meta',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod = Entity::create([
            'type' => 'product',
            'slug' => 'prod-meta',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
            'metadata' => [
                'strength' => 'primary',
                'since' => '2024',
            ],
        ]);

        $fresh = EntityRelation::find($relation->id);
        $this->assertIsArray($fresh->metadata);
        $this->assertEquals('primary', $fresh->metadata['strength']);
    }

    public function test_relation_sort_order(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'type' => 'organization',
            'slug' => 'org-sort',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $prod1 = Entity::create([
            'type' => 'product',
            'slug' => 'prod-sort-1',
            'name' => 'Prod 1',
            'status' => 'published',
        ]);

        $prod2 = Entity::create([
            'type' => 'product',
            'slug' => 'prod-sort-2',
            'name' => 'Prod 2',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod1->id,
            'relation_type' => 'produces',
            'sort_order' => 2,
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod2->id,
            'relation_type' => 'produces',
            'sort_order' => 1,
        ]);

        $sorted = EntityRelation::where('from_entity_id', $org->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertEquals(1, $sorted->first()->sort_order);
        $this->assertEquals('prod-sort-2', $sorted->first()->toEntity->slug);
    }

    public function test_relation_types_constants_exist(): void
    {
        $this->assertEquals('produces', EntityRelation::TYPE_PRODUCES);
        $this->assertEquals('offers', EntityRelation::TYPE_OFFERS);
        $this->assertEquals('uses', EntityRelation::TYPE_USES);
        $this->assertEquals('located_in', EntityRelation::TYPE_LOCATED_IN);
        $this->assertEquals('related_to', EntityRelation::TYPE_RELATED_TO);
    }

    public function test_cross_site_relation_rejected(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-cross',
            'domain' => 'b-cross.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $orgA = SiteContext::withSite($siteA, function () use ($siteA) {
            return Entity::create([
                'site_id' => $siteA->id,
                'type' => 'organization',
                'slug' => 'org-cross-a',
                'name' => 'Org A',
                'status' => 'published',
            ]);
        });

        $prodB = SiteContext::withSite($siteB, function () use ($siteB) {
            return Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'prod-cross-b',
                'name' => 'Prod B',
                'status' => 'published',
            ]);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-site violation');

        SiteContext::withSite($siteA, function () use ($orgA, $prodB, $siteA) {
            EntityRelation::create([
                'site_id' => $siteA->id,
                'from_entity_id' => $orgA->id,
                'to_entity_id' => $prodB->id,
                'relation_type' => 'produces',
            ]);
        });
    }
}
