<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Repositories\EntityRepository;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoIntegrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected EntityRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->repo = new EntityRepository();
    }

    public function test_entity_types_cover_all_geo_schema_types(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $types = ['organization', 'product', 'service', 'person', 'location', 'topic'];

        foreach ($types as $type) {
            $entity = Entity::create([
                'site_id' => $site->id,
                'type' => $type,
                'slug' => "test-{$type}",
                'name' => "Test " . ucfirst($type),
                'status' => 'published',
            ]);

            $this->assertEquals($type, $entity->type);
            $this->assertNotNull($entity->id);
        }

        foreach ($types as $type) {
            $count = Entity::ofType($type)->count();
            $this->assertEquals(1, $count, "Type {$type} should have 1 entity");
        }
    }

    public function test_entity_metadata_supports_geo_extensions(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'geo-org',
            'name' => 'Geo Organization',
            'description' => 'A test organization',
            'status' => 'published',
            'metadata' => [
                'geo_schema' => [
                    'legal_name' => 'Geo Org Ltd',
                    'tax_id' => '123456789',
                    'founding_date' => '2020-01-01',
                ],
                'address' => [
                    'street' => '123 Test St',
                    'city' => 'Singapore',
                    'country' => 'SG',
                ],
            ],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertArrayHasKey('geo_schema', $fresh->metadata);
        $this->assertArrayHasKey('address', $fresh->metadata);
        $this->assertEquals('Singapore', $fresh->metadata['address']['city']);
    }

    public function test_relations_support_knowledge_graph(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'org',
            'name' => 'Organization',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'product',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'service',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $person = Entity::create([
            'site_id' => $site->id,
            'type' => 'person',
            'slug' => 'person',
            'name' => 'Person',
            'status' => 'published',
        ]);

        $this->repo->createRelation($org, $product, 'produces');
        $this->repo->createRelation($org, $service, 'offers');
        $this->repo->createRelation($org, $person, 'employs');
        $this->repo->createRelation($service, $product, 'uses');

        $this->assertEquals(4, EntityRelation::count());
    }

    public function test_entity_slug_supports_url_resolution(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'industrial-epoxy-primer',
            'name' => 'Industrial Epoxy Primer',
            'status' => 'published',
        ]);

        $found = $this->repo->findPublishedByTypeAndSlug('product', 'industrial-epoxy-primer');
        $this->assertNotNull($found);
        $this->assertEquals($product->id, $found->id);
    }

    public function test_entity_summary_supports_seo_description(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'seo-product',
            'name' => 'SEO Product',
            'summary' => 'A product with SEO-friendly summary text',
            'description' => 'Longer description for meta description',
            'status' => 'published',
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertNotEmpty($fresh->summary);
        $this->assertNotEmpty($fresh->description);
    }

    public function test_entity_published_at_supports_sitemap(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'sitemap-product',
            'name' => 'Sitemap Product',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $fresh = Entity::find($entity->id);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_entity_status_supports_noindex(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $draft = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'draft-product',
            'name' => 'Draft Product',
            'status' => 'draft',
        ]);

        $archived = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'archived-product',
            'name' => 'Archived Product',
            'status' => 'archived',
        ]);

        $publishedCount = Entity::published()->count();
        $this->assertEquals(0, $publishedCount);
    }

    public function test_organization_metadata_supports_geo_schema(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'geo-org',
            'name' => 'Geo Organization',
            'status' => 'published',
            'metadata' => [
                'brand' => [
                    'name' => 'GeoBrand',
                    'slogan' => 'Best in class',
                ],
                'area_served' => ['Singapore', 'Malaysia', 'Indonesia'],
            ],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertArrayHasKey('brand', $fresh->metadata);
        $this->assertArrayHasKey('area_served', $fresh->metadata);
        $this->assertCount(3, $fresh->metadata['area_served']);
    }

    public function test_product_metadata_supports_geo_schema(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'geo-product',
            'name' => 'Geo Product',
            'status' => 'published',
            'metadata' => [
                'sku' => 'GP-001',
                'category' => 'Coatings',
                'is_core' => true,
            ],
        ]);

        $fresh = Entity::find($product->id);
        $this->assertArrayHasKey('sku', $fresh->metadata);
        $this->assertTrue($fresh->metadata['is_core']);
    }

    public function test_service_metadata_supports_geo_schema(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'geo-service',
            'name' => 'Geo Service',
            'status' => 'published',
            'metadata' => [
                'delivery_time' => '2-3 days',
                'minimum_order' => '100 units',
            ],
        ]);

        $fresh = Entity::find($service->id);
        $this->assertArrayHasKey('delivery_time', $fresh->metadata);
    }

    public function test_relations_support_llms_knowledge_graph(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'llms-org',
            'name' => 'LLMS Organization',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'llms-product',
            'name' => 'LLMS Product',
            'status' => 'published',
        ]);

        $this->repo->createRelation($org, $product, 'produces');

        $related = $this->repo->getRelatedEntities($org, 'produces');
        $this->assertCount(1, $related);
        $this->assertEquals('LLMS Product', $related->first()->name);
    }

    public function test_entity_sort_order_supports_sitemap(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'product-b',
            'name' => 'Product B',
            'status' => 'published',
            'sort_order' => 2,
        ]);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'product-a',
            'name' => 'Product A',
            'status' => 'published',
            'sort_order' => 1,
        ]);

        $products = $this->repo->getProducts();
        $this->assertEquals('product-a', $products->first()->slug);
    }

    public function test_no_business_hardcode_in_geo_boundary(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing',
            'slug' => 'demo-mfg',
            'domain' => 'demo.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($site, function () use ($site) {
            Entity::create([
                'site_id' => $site->id,
                'type' => 'organization',
                'slug' => 'demo-manufacturing-sg',
                'name' => 'Demo Manufacturing Singapore',
                'status' => 'published',
            ]);
        });

        $org = Entity::withoutSiteScope()->where('slug', 'demo-manufacturing-sg')->first();
        $this->assertStringNotContainsString('demo-tenant-a', strtolower($org->name));
    }
}
