<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Repositories\EntityRepository;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenericDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected EntityRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->repo = new EntityRepository();
    }

    public function test_demo_manufacturing_can_deploy_from_scratch(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing Singapore',
            'slug' => 'demo-manufacturing',
            'domain' => 'demo.example.com',
            'description' => 'Precision industrial components manufacturer',
            'status' => 'active',
            'is_default' => false,
            'metadata' => [
                'industry' => 'manufacturing',
                'region' => 'southeast-asia',
            ],
        ]);

        $repo = $this->repo;
        SiteContext::withSite($site, function () use ($site, $repo) {
            $org = Entity::create([
                'site_id' => $site->id,
                'type' => 'organization',
                'slug' => 'demo-manufacturing-pte-ltd',
                'name' => 'Demo Manufacturing Pte Ltd',
                'summary' => 'Precision components for automotive and aerospace',
                'description' => 'ISO 9001 certified manufacturer.',
                'status' => 'published',
                'metadata' => [
                    'founded' => 2015,
                    'employees' => 250,
                    'certifications' => ['ISO 9001', 'AS9100'],
                    'area_served' => ['Singapore', 'Malaysia', 'Thailand', 'Indonesia'],
                ],
            ]);

            $gear = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'precision-helical-gears',
                'name' => 'Precision Helical Gears',
                'summary' => 'High precision helical gears',
                'description' => 'High-precision helical gears for industrial gearboxes.',
                'status' => 'published',
                'metadata' => [
                    'material' => '16MnCr5',
                    'tolerance' => 'DIN 5',
                    'moq' => 50,
                ],
            ]);

            $bearing = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'deep-groove-ball-bearings',
                'name' => 'Deep Groove Ball Bearings',
                'summary' => 'P6 precision ball bearings',
                'description' => 'Durable deep groove ball bearings.',
                'status' => 'published',
                'metadata' => [
                    'material' => 'SUJ2',
                    'precision' => 'P6',
                    'moq' => 100,
                ],
            ]);

            $machining = Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => 'custom-cnc-machining',
                'name' => 'Custom CNC Machining',
                'summary' => '5-axis CNC machining service',
                'description' => 'Custom CNC machining services.',
                'status' => 'published',
                'metadata' => [
                    'lead_time' => '7-14 days',
                    'capabilities' => ['milling', 'turning', 'grinding'],
                ],
            ]);

            $factory = Entity::create([
                'site_id' => $site->id,
                'type' => 'location',
                'slug' => 'singapore-factory',
                'name' => 'Singapore Factory',
                'summary' => '20,000 sqm facility',
                'status' => 'published',
                'metadata' => [
                    'address' => '123 Industrial Ave, Singapore',
                ],
            ]);

            $repo->createRelation($org, $gear, 'produces');
            $repo->createRelation($org, $bearing, 'produces');
            $repo->createRelation($org, $machining, 'offers');
            $repo->createRelation($org, $factory, 'located_at');
            $repo->createRelation($machining, $gear, 'uses');
            $repo->createRelation($machining, $bearing, 'uses');
        });

        SiteContext::setSite($site);

        $this->assertEquals(1, Entity::ofType('organization')->count());
        $this->assertEquals(2, Entity::ofType('product')->count());
        $this->assertEquals(1, Entity::ofType('service')->count());
        $this->assertEquals(1, Entity::ofType('location')->count());
        $this->assertEquals(6, EntityRelation::count());
    }

    public function test_generic_deployment_has_no_business_pollution(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing Singapore',
            'slug' => 'demo-manufacturing',
            'domain' => 'demo.example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($site, function () use ($site) {
            Entity::create([
                'site_id' => $site->id,
                'type' => 'organization',
                'slug' => 'demo-manufacturing',
                'name' => 'Demo Manufacturing Pte Ltd',
                'status' => 'published',
            ]);
        });

        $org = Entity::withoutSiteScope()->where('slug', 'demo-manufacturing')->first();

        $this->assertStringNotContainsString('demo-tenant-a', strtolower($org->name));
        $this->assertStringNotContainsString('sample-city', strtolower($org->name));
    }

    public function test_generic_deployment_queries_work(): void
    {
        $site = Site::create([
            'name' => 'Demo Manufacturing',
            'slug' => 'demo-mfg',
            'domain' => 'demo.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $repo = $this->repo;
        SiteContext::withSite($site, function () use ($site, $repo) {
            $org = Entity::create([
                'site_id' => $site->id,
                'type' => 'organization',
                'slug' => 'demo-mfg-sg',
                'name' => 'Demo Manufacturing',
                'status' => 'published',
            ]);

            $product = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'precision-gears',
                'name' => 'Precision Gears',
                'status' => 'published',
            ]);

            $service = Entity::create([
                'site_id' => $site->id,
                'type' => 'service',
                'slug' => 'cnc-machining',
                'name' => 'CNC Machining',
                'status' => 'published',
            ]);

            $repo->createRelation($org, $product, 'produces');
            $repo->createRelation($org, $service, 'offers');
        });

        SiteContext::setSite($site);

        $org = $this->repo->findPublishedByTypeAndSlug('organization', 'demo-mfg-sg');
        $this->assertNotNull($org);

        $products = $this->repo->getProductsForOrganization($org);
        $this->assertCount(1, $products);
        $this->assertEquals('Precision Gears', $products->first()->name);

        $services = $this->repo->getServicesForOrganization($org);
        $this->assertCount(1, $services);
        $this->assertEquals('CNC Machining', $services->first()->name);
    }

    public function test_generic_deployment_search_works(): void
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
                'type' => 'product',
                'slug' => 'industrial-gears',
                'name' => 'Industrial Gears',
                'summary' => 'Heavy duty industrial gears',
                'status' => 'published',
            ]);

            Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'ball-bearings',
                'name' => 'Ball Bearings',
                'summary' => 'High precision ball bearings',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($site);

        $results = $this->repo->search('Gears');
        $this->assertCount(1, $results);
        $this->assertEquals('Industrial Gears', $results->first()->name);
    }

    public function test_different_business_can_use_same_entity_structure(): void
    {
        $siteA = Site::create([
            'name' => 'Packaging Materials Co',
            'slug' => 'packaging-materials',
            'domain' => 'packaging.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $siteB = Site::create([
            'name' => 'Precision Engineering Ltd',
            'slug' => 'precision-engineering',
            'domain' => 'engineering.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'organization',
                'slug' => 'packaging-materials-co',
                'name' => 'Packaging Materials Co',
                'status' => 'published',
            ]);

            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'corrugated-box',
                'name' => 'Corrugated Box',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'organization',
                'slug' => 'precision-engineering-ltd',
                'name' => 'Precision Engineering Ltd',
                'status' => 'published',
            ]);

            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'cnc-parts',
                'name' => 'CNC Machined Parts',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $pkgProducts = $this->repo->getProducts();
        $this->assertCount(1, $pkgProducts);
        $this->assertEquals('Corrugated Box', $pkgProducts->first()->name);

        SiteContext::setSite($siteB);
        $engProducts = $this->repo->getProducts();
        $this->assertCount(1, $engProducts);
        $this->assertEquals('CNC Machined Parts', $engProducts->first()->name);
    }

    public function test_generic_deployment_metadata_is_flexible(): void
    {
        $site = Site::create([
            'name' => 'Demo SaaS',
            'slug' => 'demo-saas',
            'domain' => 'saas.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($site, function () use ($site) {
            Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'analytics-dashboard',
                'name' => 'Analytics Dashboard',
                'status' => 'published',
                'metadata' => [
                    'pricing' => [
                        'basic' => 99,
                        'pro' => 299,
                    ],
                    'features' => ['reports', 'alerts', 'export'],
                    'stack' => ['PHP', 'React', 'PostgreSQL'],
                ],
            ]);
        });

        SiteContext::setSite($site);
        $product = $this->repo->findPublishedByTypeAndSlug('product', 'analytics-dashboard');
        $this->assertNotNull($product);
        $this->assertArrayHasKey('pricing', $product->metadata);
        $this->assertEquals(99, $product->metadata['pricing']['basic']);
    }

    public function test_generic_deployment_relation_types_are_flexible(): void
    {
        $site = Site::create([
            'name' => 'Demo Retail',
            'slug' => 'demo-retail',
            'domain' => 'retail.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $repo = $this->repo;
        SiteContext::withSite($site, function () use ($site, $repo) {
            $store = Entity::create([
                'site_id' => $site->id,
                'type' => 'location',
                'slug' => 'downtown-store',
                'name' => 'Downtown Store',
                'status' => 'published',
            ]);

            $product = Entity::create([
                'site_id' => $site->id,
                'type' => 'product',
                'slug' => 'premium-coffee',
                'name' => 'Premium Coffee',
                'status' => 'published',
            ]);

            $repo->createRelation($store, $product, 'sells');
        });

        SiteContext::setSite($site);
        $this->assertEquals(1, EntityRelation::where('relation_type', 'sells')->count());
    }
}
