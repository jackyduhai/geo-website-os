<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Facts;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FactsMigrationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_cases_data_structure_is_valid(): void
    {
        $cases = Facts::cases();
        $this->assertIsArray($cases);
        $this->assertGreaterThan(0, count($cases));

        foreach ($cases as $case) {
            $this->assertArrayHasKey('id', $case);
            $this->assertArrayHasKey('title', $case);
            $this->assertArrayHasKey('quote', $case);
            $this->assertArrayHasKey('combo', $case);
        }
    }

    public function test_cases_can_map_to_content_slugs(): void
    {
        $cases = Facts::cases();
        $slugs = [];

        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            $slugs[] = $slug;
        }

        $this->assertCount(count($cases), $slugs);
        $this->assertEquals(count($slugs), count(array_unique($slugs)));
    }

    public function test_all_facts_have_mapping_targets(): void
    {
        $this->assertNotEmpty(Facts::company());
        $this->assertNotEmpty(Facts::products());
        $this->assertNotEmpty(Facts::scenes());
        $this->assertNotEmpty(Facts::cases());
        $this->assertNotEmpty(Facts::salesRegions());
        $this->assertNotEmpty(Facts::brandLanguage());
        $this->assertNotEmpty(Facts::workshops());
        $this->assertNotEmpty(Facts::certifications());
    }

    public function test_sales_regions_can_be_stored_in_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $salesRegions = Facts::salesRegions();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'test-org-metadata',
            'name' => 'Test Org',
            'status' => 'published',
            'metadata' => ['area_served' => $salesRegions],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertEquals($salesRegions, $fresh->metadata['area_served']);
    }

    public function test_brand_language_can_be_stored_in_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $brandLanguage = Facts::brandLanguage();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'test-brand-metadata',
            'name' => 'Test Org',
            'status' => 'published',
            'metadata' => ['brand' => $brandLanguage],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertEquals($brandLanguage, $fresh->metadata['brand']);
    }

    public function test_workshops_can_be_stored_in_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $workshops = Facts::workshops();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'test-workshops-metadata',
            'name' => 'Test Org',
            'status' => 'published',
            'metadata' => ['workshops' => $workshops],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertEquals($workshops, $fresh->metadata['workshops']);
    }

    public function test_certifications_can_be_stored_in_metadata(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $certifications = Facts::certifications();

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'test-cert-metadata',
            'name' => 'Test Org',
            'status' => 'published',
            'metadata' => ['certifications' => $certifications],
        ]);

        $fresh = Entity::find($org->id);
        $this->assertEquals($certifications, $fresh->metadata['certifications']);
    }

    public function test_scene_combo_relations_can_be_created(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $product1 = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product-1',
            'name' => 'Test Product 1',
            'status' => 'published',
        ]);

        $product2 = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product-2',
            'name' => 'Test Product 2',
            'status' => 'published',
        ]);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'test-service-1',
            'name' => 'Test Service 1',
            'status' => 'published',
        ]);

        $relation1 = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $product1->id,
            'relation_type' => 'uses',
        ]);

        $relation2 = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $product2->id,
            'relation_type' => 'uses',
        ]);

        $this->assertNotNull($relation1->id);
        $this->assertNotNull($relation2->id);
        $this->assertEquals(2, EntityRelation::where('relation_type', 'uses')->count());
    }

    public function test_mapping_is_idempotent(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::firstOrCreate(
            ['site_id' => $site->id, 'type' => 'organization', 'slug' => 'idempotent-test'],
            ['name' => 'Test', 'status' => 'published']
        );

        Entity::firstOrCreate(
            ['site_id' => $site->id, 'type' => 'organization', 'slug' => 'idempotent-test'],
            ['name' => 'Test', 'status' => 'published']
        );

        $this->assertEquals(1, Entity::ofType('organization')->where('slug', 'idempotent-test')->count());
    }

    public function test_no_data_loss_in_product_mapping(): void
    {
        $products = Facts::products();
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        foreach ($products as $product) {
            $entity = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => $product['slug'],
                'name' => $product['name'],
                'status' => 'published',
            ]);

            $fresh = Entity::find($entity->id);
            $this->assertEquals($product['slug'], $fresh->slug);
            $this->assertEquals($product['name'], $fresh->name);
        }
    }

    public function test_no_data_loss_in_scene_mapping(): void
    {
        $scenes = Facts::scenes();
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        foreach ($scenes as $scene) {
            $entity = Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => $scene['slug'],
                'name' => $scene['name'],
                'status' => 'published',
            ]);

            $fresh = Entity::find($entity->id);
            $this->assertEquals($scene['slug'], $fresh->slug);
            $this->assertEquals($scene['name'], $fresh->name);
        }
    }

    public function test_generic_dataset_no_business_pollution(): void
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
                'slug' => 'demo-mfg-sg',
                'name' => 'Demo Manufacturing Singapore',
                'status' => 'published',
            ]);
        });

        $org = Entity::withoutSiteScope()->where('slug', 'demo-mfg-sg')->first();
        $this->assertStringNotContainsString('demo-tenant-a', strtolower($org->name));
    }
}
