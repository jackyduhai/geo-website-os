<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitySiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_entity_creating_auto_binds_current_site(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'test-auto-bind',
            'name' => 'Test',
            'status' => 'draft',
        ]);

        $this->assertEquals($site->id, $entity->site_id);
    }

    public function test_site_a_cannot_see_site_b_entity(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-product',
                'name' => 'B Product',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $entities = Entity::all();

        $this->assertEmpty($entities);
    }

    public function test_site_a_cannot_update_site_b_entity(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $bEntity = SiteContext::withSite($siteB, function () use ($siteB) {
            return Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-update',
                'name' => 'B Original',
                'status' => 'draft',
            ]);
        });

        SiteContext::setSite($siteA);
        $found = Entity::find($bEntity->id);
        $this->assertNull($found);
    }

    public function test_site_a_cannot_delete_site_b_entity(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $bEntity = SiteContext::withSite($siteB, function () use ($siteB) {
            return Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-delete',
                'name' => 'B Delete',
                'status' => 'draft',
            ]);
        });

        SiteContext::setSite($siteA);
        $deleted = Entity::destroy($bEntity->id);
        $this->assertEquals(0, $deleted);

        $stillExists = SiteContext::withSite($siteB, function () use ($bEntity) {
            return Entity::find($bEntity->id) !== null;
        });

        $this->assertTrue($stillExists);
    }

    public function test_explicit_site_id_query_is_filtered(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-explicit',
                'name' => 'B Explicit',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $found = Entity::where('site_id', $siteB->id)->get();
        $this->assertEmpty($found);
    }

    public function test_entity_relation_is_site_isolated(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteB, function () use ($siteB) {
            $org = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'organization',
                'slug' => 'b-org',
                'name' => 'B Org',
                'status' => 'published',
            ]);

            $prod = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-prod',
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

    public function test_entity_relationship_query_no_cross_site_leak(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            $orgA = Entity::create([
                'site_id' => $siteA->id,
                'type' => 'organization',
                'slug' => 'a-org',
                'name' => 'A Org',
                'status' => 'published',
            ]);

            $prodA = Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'a-prod',
                'name' => 'A Prod',
                'status' => 'published',
            ]);

            EntityRelation::create([
                'site_id' => $siteA->id,
                'from_entity_id' => $orgA->id,
                'to_entity_id' => $prodA->id,
                'relation_type' => 'produces',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            $orgB = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'organization',
                'slug' => 'b-org-rel',
                'name' => 'B Org',
                'status' => 'published',
            ]);

            $prodB = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-prod-rel',
                'name' => 'B Prod',
                'status' => 'published',
            ]);

            EntityRelation::create([
                'site_id' => $siteB->id,
                'from_entity_id' => $orgB->id,
                'to_entity_id' => $prodB->id,
                'relation_type' => 'produces',
            ]);
        });

        SiteContext::setSite($siteA);
        $entities = Entity::with('relationsFrom')->get();

        foreach ($entities as $entity) {
            $this->assertEquals($siteA->id, $entity->site_id);
            foreach ($entity->relationsFrom as $relation) {
                $this->assertEquals($siteA->id, $relation->site_id);
            }
        }
    }

    public function test_entity_scope_published_works(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'type' => 'product',
            'slug' => 'published-test',
            'name' => 'Published',
            'status' => 'published',
        ]);

        Entity::create([
            'type' => 'product',
            'slug' => 'draft-test',
            'name' => 'Draft',
            'status' => 'draft',
        ]);

        $published = Entity::published()->get();
        $this->assertEquals(1, $published->count());
    }

    public function test_entity_scope_of_type_works(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'type' => 'product',
            'slug' => 'product-type',
            'name' => 'Product',
            'status' => 'published',
        ]);

        Entity::create([
            'type' => 'service',
            'slug' => 'service-type',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $products = Entity::ofType('product')->get();
        $this->assertEquals(1, $products->count());
        $this->assertEquals('product', $products->first()->type);
    }

    public function test_entity_relation_cross_site_rejected_at_runtime(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $orgA = SiteContext::withSite($siteA, function () use ($siteA) {
            return Entity::create([
                'site_id' => $siteA->id,
                'type' => 'organization',
                'slug' => 'org-cross',
                'name' => 'Org A',
                'status' => 'published',
            ]);
        });

        $prodB = SiteContext::withSite($siteB, function () use ($siteB) {
            return Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'prod-cross',
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

    public function test_entity_metadata_json_roundtrip(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-roundtrip',
            'name' => 'Meta',
            'status' => 'draft',
            'metadata' => [
                'features' => ['tender', 'crispy'],
                'applications' => ['fried chicken', 'popcorn chicken'],
            ],
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertIsArray($fresh->metadata);
        $this->assertContains('tender', $fresh->metadata['features']);
    }
}
