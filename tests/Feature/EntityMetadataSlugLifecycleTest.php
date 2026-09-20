<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityMetadataSlugLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_same_slug_same_type_same_site_rejected(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'type' => 'product',
            'slug' => 'protective-coating',
            'name' => 'Product 1',
            'status' => 'published',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Entity::create([
            'type' => 'product',
            'slug' => 'protective-coating',
            'name' => 'Product 2',
            'status' => 'draft',
        ]);
    }

    public function test_same_slug_different_type_allowed(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity1 = Entity::create([
            'type' => 'product',
            'slug' => 'coating',
            'name' => 'Product Coating',
            'status' => 'published',
        ]);

        $entity2 = Entity::create([
            'type' => 'service',
            'slug' => 'coating',
            'name' => 'Service Coating',
            'status' => 'published',
        ]);

        $this->assertEquals($entity1->id, Entity::where('type', 'product')->where('slug', 'coating')->first()->id);
        $this->assertEquals($entity2->id, Entity::where('type', 'service')->where('slug', 'coating')->first()->id);
    }

    public function test_same_slug_different_site_allowed(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-slug',
            'domain' => 'b-slug.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'shared-slug',
                'name' => 'Site A Product',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'shared-slug',
                'name' => 'Site B Product',
                'status' => 'published',
            ]);
        });

        $this->assertEquals(2, Entity::withoutSiteScope()->where('slug', 'shared-slug')->count());
    }

    public function test_slug_can_be_updated(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'old-slug',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $entity->update(['slug' => 'new-slug']);
        $this->assertEquals('new-slug', $entity->fresh()->slug);
    }

    public function test_slug_url_safe_characters(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'protective-coating-v2',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $this->assertEquals('protective-coating-v2', $entity->slug);
    }

    public function test_metadata_json_object(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-object',
            'name' => 'Product',
            'status' => 'published',
            'metadata' => [
                'features' => ['durable', 'corrosion-resistant'],
                'applications' => ['metal equipment'],
                'custom' => [
                    'key' => 'value',
                    'nested' => true,
                ],
            ],
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertIsArray($fresh->metadata);
        $this->assertArrayHasKey('features', $fresh->metadata);
        $this->assertArrayHasKey('custom', $fresh->metadata);
    }

    public function test_metadata_null_allowed(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-null',
            'name' => 'Product',
            'status' => 'published',
            'metadata' => null,
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertNull($fresh->metadata);
    }

    public function test_metadata_empty_object(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-empty',
            'name' => 'Product',
            'status' => 'published',
            'metadata' => [],
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertIsArray($fresh->metadata);
        $this->assertEmpty($fresh->metadata);
    }

    public function test_metadata_does_not_override_core_fields(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-core',
            'name' => 'Original Name',
            'status' => 'published',
            'metadata' => [
                'name' => 'Hacked Name',
                'slug' => 'hacked-slug',
                'type' => 'organization',
            ],
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertEquals('Original Name', $fresh->name);
        $this->assertEquals('meta-core', $fresh->slug);
        $this->assertEquals('product', $fresh->type);
    }

    public function test_metadata_update_merge(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'meta-merge',
            'name' => 'Product',
            'status' => 'published',
            'metadata' => [
                'feature1' => 'value1',
                'feature2' => 'value2',
            ],
        ]);

        $entity->update([
            'metadata' => [
                'feature1' => 'updated',
                'feature3' => 'value3',
            ],
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertEquals('updated', $fresh->metadata['feature1']);
        $this->assertEquals('value3', $fresh->metadata['feature3']);
    }

    public function test_lifecycle_draft_to_published(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'lifecycle-draft',
            'name' => 'Product',
            'status' => 'draft',
        ]);

        $this->assertEquals('draft', $entity->status);

        $entity->update(['status' => 'published']);
        $this->assertEquals('published', $entity->fresh()->status);
    }

    public function test_lifecycle_published_to_archived(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'lifecycle-archived',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $entity->update(['status' => 'archived']);
        $this->assertEquals('archived', $entity->fresh()->status);
    }

    public function test_lifecycle_archived_to_published(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'lifecycle-republish',
            'name' => 'Product',
            'status' => 'archived',
        ]);

        $entity->update(['status' => 'published']);
        $this->assertEquals('published', $entity->fresh()->status);
    }

    public function test_lifecycle_draft_to_archived(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'lifecycle-draft-archived',
            'name' => 'Product',
            'status' => 'draft',
        ]);

        $entity->update(['status' => 'archived']);
        $this->assertEquals('archived', $entity->fresh()->status);
    }

    public function test_published_scope_filters_status(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'type' => 'product',
            'slug' => 'pub-1',
            'name' => 'Published 1',
            'status' => 'published',
        ]);

        Entity::create([
            'type' => 'product',
            'slug' => 'draft-1',
            'name' => 'Draft 1',
            'status' => 'draft',
        ]);

        Entity::create([
            'type' => 'product',
            'slug' => 'arch-1',
            'name' => 'Archived 1',
            'status' => 'archived',
        ]);

        $published = Entity::published()->get();
        $this->assertEquals(1, $published->count());
        $this->assertEquals('pub-1', $published->first()->slug);
    }

    public function test_published_scope_is_site_isolated(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-pub',
            'domain' => 'b-pub.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'a-pub',
                'name' => 'A Published',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'b-pub',
                'name' => 'B Published',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $published = Entity::published()->get();
        $this->assertEquals(1, $published->count());
        $this->assertEquals('a-pub', $published->first()->slug);
    }

    public function test_find_by_slug_respects_site(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-find',
            'domain' => 'b-find.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'find-me',
                'name' => 'A Find Me',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'find-me',
                'name' => 'B Find Me',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $found = Entity::where('type', 'product')->where('slug', 'find-me')->first();
        $this->assertEquals('A Find Me', $found->name);

        SiteContext::setSite($siteB);
        $found = Entity::where('type', 'product')->where('slug', 'find-me')->first();
        $this->assertEquals('B Find Me', $found->name);
    }

    public function test_type_scope_filters(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'type' => 'product',
            'slug' => 'type-prod',
            'name' => 'Product',
            'status' => 'published',
        ]);

        Entity::create([
            'type' => 'service',
            'slug' => 'type-svc',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $products = Entity::ofType('product')->get();
        $this->assertEquals(1, $products->count());
        $this->assertEquals('product', $products->first()->type);
    }

    public function test_summary_field(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'summary-test',
            'name' => 'Product',
            'summary' => 'Short summary',
            'status' => 'published',
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertEquals('Short summary', $fresh->summary);
    }

    public function test_description_field(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'desc-test',
            'name' => 'Product',
            'description' => '<p>Full description</p>',
            'status' => 'published',
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertEquals('<p>Full description</p>', $fresh->description);
    }

    public function test_published_at_datetime_cast(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'pub-at-test',
            'name' => 'Product',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->published_at);
    }

    public function test_sort_order_integer_cast(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'type' => 'product',
            'slug' => 'sort-test',
            'name' => 'Product',
            'status' => 'published',
            'sort_order' => 5,
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertIsInt($fresh->sort_order);
        $this->assertEquals(5, $fresh->sort_order);
    }

    public function test_no_business_hardcode_in_entity_model(): void
    {
        $content = file_get_contents(app_path('Models/Entity.php'));
        $this->assertStringNotContainsString('demo-tenant-a', strtolower($content));
    }
}
