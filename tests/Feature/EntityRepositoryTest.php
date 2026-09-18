<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Repositories\EntityRepository;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected EntityRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->repo = new EntityRepository();
    }

    public function test_find_by_type_and_slug(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $found = $this->repo->findByTypeAndSlug('product', 'test-product');
        $this->assertNotNull($found);
        $this->assertEquals($entity->id, $found->id);
    }

    public function test_find_by_type_and_slug_returns_null_for_wrong_type(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $found = $this->repo->findByTypeAndSlug('service', 'test-product');
        $this->assertNull($found);
    }

    public function test_find_by_type_and_slug_respects_site(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'shared-slug',
                'name' => 'Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'shared-slug',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $foundA = $this->repo->findByTypeAndSlug('product', 'shared-slug');
        $this->assertEquals('Product A', $foundA->name);

        SiteContext::setSite($siteB);
        $foundB = $this->repo->findByTypeAndSlug('product', 'shared-slug');
        $this->assertEquals('Product B', $foundB->name);
    }

    public function test_find_published_only_returns_published(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'draft-product',
            'name' => 'Draft Product',
            'status' => 'draft',
        ]);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'published-product',
            'name' => 'Published Product',
            'status' => 'published',
        ]);

        $this->assertNull($this->repo->findPublishedByTypeAndSlug('product', 'draft-product'));
        $this->assertNotNull($this->repo->findPublishedByTypeAndSlug('product', 'published-product'));
    }

    public function test_get_by_type_returns_only_requested_type(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create(['site_id' => $site->id, 'type' => 'product', 'slug' => 'p1', 'name' => 'P1', 'status' => 'published']);
        Entity::create(['site_id' => $site->id, 'type' => 'product', 'slug' => 'p2', 'name' => 'P2', 'status' => 'published']);
        Entity::create(['site_id' => $site->id, 'type' => 'service', 'slug' => 's1', 'name' => 'S1', 'status' => 'published']);

        $products = $this->repo->getByType('product');
        $this->assertCount(2, $products);
        $this->assertTrue($products->every(fn ($e) => $e->type === 'product'));
    }

    public function test_get_products_helper(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create(['site_id' => $site->id, 'type' => 'product', 'slug' => 'p1', 'name' => 'P1', 'status' => 'published']);
        Entity::create(['site_id' => $site->id, 'type' => 'service', 'slug' => 's1', 'name' => 'S1', 'status' => 'published']);

        $this->assertCount(1, $this->repo->getProducts());
    }

    public function test_get_services_helper(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create(['site_id' => $site->id, 'type' => 'product', 'slug' => 'p1', 'name' => 'P1', 'status' => 'published']);
        Entity::create(['site_id' => $site->id, 'type' => 'service', 'slug' => 's1', 'name' => 'S1', 'status' => 'published']);

        $this->assertCount(1, $this->repo->getServices());
    }

    public function test_get_related_entities(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'svc',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'prod',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $this->repo->createRelation($service, $product, 'uses');

        $related = $this->repo->getRelatedEntities($service, 'uses');
        $this->assertCount(1, $related);
        $this->assertEquals('Product', $related->first()->name);
    }

    public function test_get_incoming_relations(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'svc',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'prod',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $this->repo->createRelation($service, $product, 'uses');

        $incoming = $this->repo->getIncomingRelations($product, 'uses');
        $this->assertCount(1, $incoming);
        $this->assertEquals('Service', $incoming->first()->name);
    }

    public function test_get_products_for_service(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'svc',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $product1 = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'p1',
            'name' => 'P1',
            'status' => 'published',
        ]);

        $product2 = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'p2',
            'name' => 'P2',
            'status' => 'published',
        ]);

        $this->repo->createRelation($service, $product1, 'uses');
        $this->repo->createRelation($service, $product2, 'uses');

        $products = $this->repo->getProductsForService($service);
        $this->assertCount(2, $products);
    }

    public function test_get_services_for_product(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'prod',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $service = Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'svc',
            'name' => 'Service',
            'status' => 'published',
        ]);

        $this->repo->createRelation($service, $product, 'uses');

        $services = $this->repo->getServicesForProduct($product);
        $this->assertCount(1, $services);
        $this->assertEquals('Service', $services->first()->name);
    }

    public function test_get_products_for_organization(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $org = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'org',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $product = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'prod',
            'name' => 'Product',
            'status' => 'published',
        ]);

        $this->repo->createRelation($org, $product, 'produces');

        $products = $this->repo->getProductsForOrganization($org);
        $this->assertCount(1, $products);
    }

    public function test_search_finds_by_name(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'crispy-chicken',
            'name' => 'Crispy Chicken Marinade',
            'status' => 'published',
        ]);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'other',
            'name' => 'Other Product',
            'status' => 'published',
        ]);

        $results = $this->repo->search('Crispy');
        $this->assertCount(1, $results);
        $this->assertEquals('crispy-chicken', $results->first()->slug);
    }

    public function test_search_respects_type_filter(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'p1',
            'name' => 'Search Term',
            'status' => 'published',
        ]);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 's1',
            'name' => 'Search Term',
            'status' => 'published',
        ]);

        $results = $this->repo->search('Search Term', 'product');
        $this->assertCount(1, $results);
        $this->assertEquals('product', $results->first()->type);
    }

    public function test_search_only_returns_published(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'draft',
            'name' => 'Searchable Draft',
            'status' => 'draft',
        ]);

        $results = $this->repo->search('Searchable');
        $this->assertCount(0, $results);
    }

    public function test_find_or_fail_throws_exception(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->repo->findByTypeAndSlugOrFail('product', 'nonexistent');
    }

    public function test_create_relation_validates_same_site(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $entityA = null;
        $entityB = null;

        SiteContext::withSite($siteA, function () use ($siteA, &$entityA) {
            $entityA = Entity::create([
                'site_id' => $siteA->id,
                'type' => 'service',
                'slug' => 'svc-a',
                'name' => 'Service A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB, &$entityB) {
            $entityB = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'prod-b',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);

        $this->expectException(\Exception::class);
        $this->repo->createRelation($entityA, $entityB, 'uses');
    }
}
