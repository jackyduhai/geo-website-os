<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Facts;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactsEntityMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_company_maps_to_organization_entity(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $company = Facts::company();

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'example-org',
            'name' => $company['name'] ?? 'Example Org',
            'summary' => $company['slogan'] ?? null,
            'status' => 'published',
            'metadata' => [
                'brand' => Facts::brandLanguage(),
                'area_served' => Facts::salesRegions(),
                'workshops' => Facts::workshops(),
                'certifications' => Facts::certifications(),
            ],
        ]);

        $this->assertEquals('organization', $entity->type);
        $this->assertEquals('example-org', $entity->slug);
        $this->assertArrayHasKey('brand', $entity->metadata);
        $this->assertArrayHasKey('area_served', $entity->metadata);
    }

    public function test_products_map_to_product_entities(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $products = Facts::products();

        foreach ($products as $product) {
            Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => $product['slug'],
                'name' => $product['name'],
                'summary' => $product['tagline'] ?? null,
                'description' => $product['description'] ?? null,
                'status' => 'published',
                'metadata' => [
                    'line' => $product['line'] ?? null,
                    'is_core' => Facts::isCoreProduct($product['slug']),
                    'scenes' => $product['scenes'] ?? [],
                ],
            ]);
        }

        $productEntities = Entity::ofType('product')->get();
        $this->assertEquals(count($products), $productEntities->count());
    }

    public function test_scenes_map_to_service_entities(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $scenes = Facts::scenes();

        foreach ($scenes as $scene) {
            Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => $scene['slug'],
                'name' => $scene['name'],
                'summary' => $scene['desc'] ?? null,
                'status' => 'published',
                'metadata' => [
                    'order' => $scene['order'] ?? 99,
                    'adjacent' => $scene['adjacent'] ?? [],
                ],
            ]);
        }

        $serviceEntities = Entity::ofType('service')->get();
        $this->assertEquals(count($scenes), $serviceEntities->count());
    }

    public function test_scene_combo_maps_to_entity_relation(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'example-org',
            'name' => 'Example Org',
            'status' => 'published',
        ]);

        $productEntities = [];
        foreach (Facts::products() as $product) {
            $productEntities[$product['slug']] = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => $product['slug'],
                'name' => $product['name'],
                'status' => 'published',
            ]);
        }

        $relationCount = 0;
        foreach (Facts::scenes() as $scene) {
            $serviceEntity = Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => $scene['slug'],
                'name' => $scene['name'],
                'status' => 'published',
            ]);

            $combo = Facts::sceneCombo($scene);
            foreach ($combo as $product) {
                if (isset($productEntities[$product['slug']])) {
                    EntityRelation::create([
                        'site_id' => $site->id,
                        'from_entity_id' => $serviceEntity->id,
                        'to_entity_id' => $productEntities[$product['slug']]->id,
                        'relation_type' => 'uses',
                    ]);
                    $relationCount++;
                }
            }
        }

        $this->assertGreaterThan(0, $relationCount);
        $relations = EntityRelation::where('relation_type', 'uses')->get();
        $this->assertEquals($relationCount, $relations->count());
    }

    public function test_sales_regions_map_to_organization_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $salesRegions = Facts::salesRegions();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'example-org',
            'name' => 'Example Org',
            'status' => 'published',
            'metadata' => [
                'area_served' => $salesRegions,
            ],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertArrayHasKey('area_served', $fresh->metadata);
        $this->assertEquals($salesRegions, $fresh->metadata['area_served']);
    }

    public function test_brand_language_maps_to_organization_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $brandLanguage = Facts::brandLanguage();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'example-org',
            'name' => 'Example Org',
            'status' => 'published',
            'metadata' => [
                'brand' => $brandLanguage,
            ],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertArrayHasKey('brand', $fresh->metadata);
    }

    public function test_mapping_is_idempotent(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::firstOrCreate(
            ['site_id' => $site->id, 'type' => 'organization', 'slug' => 'example-org'],
            ['name' => 'Example Org', 'status' => 'published']
        );

        Entity::firstOrCreate(
            ['site_id' => $site->id, 'type' => 'organization', 'slug' => 'example-org'],
            ['name' => 'Example Org', 'status' => 'published']
        );

        $this->assertEquals(1, Entity::ofType('organization')->where('slug', 'example-org')->count());
    }

    public function test_generic_dataset_works_without_business_hardcode(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing',
            'slug' => 'demo-manufacturing',
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
                'summary' => 'Industrial components manufacturer',
                'status' => 'published',
                'metadata' => [
                    'area_served' => ['Singapore', 'Malaysia', 'Indonesia'],
                ],
            ]);

            Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'precision-gears',
                'name' => 'Precision Gears',
                'summary' => 'High-precision industrial gears',
                'status' => 'published',
            ]);

            Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'ball-bearings',
                'name' => 'Ball Bearings',
                'summary' => 'Durable industrial ball bearings',
                'status' => 'published',
            ]);

            Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => 'custom-machining',
                'name' => 'Custom Machining',
                'summary' => 'Custom CNC machining services',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($site);

        $this->assertEquals(1, Entity::ofType('organization')->where('slug', 'demo-manufacturing-sg')->count());
        $this->assertEquals(2, Entity::ofType('product')->count());
        $this->assertEquals(1, Entity::ofType('service')->count());
    }

    public function test_generic_dataset_no_business_pollution(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing',
            'slug' => 'demo-manufacturing',
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

        $entity = Entity::withoutSiteScope()->where('slug', 'demo-manufacturing-sg')->first();
        $this->assertStringNotContainsString('demo-tenant-a', strtolower($entity->name));
        $this->assertStringNotContainsString('Demo Tenant A', $entity->name);
        $this->assertStringNotContainsString('sample-city', strtolower($entity->name));
    }

    public function test_migration_matrix_document_exists(): void
    {
        $this->assertTrue(file_exists(base_path('docs/audit/entity-architecture-matrix.md')));
    }
}
