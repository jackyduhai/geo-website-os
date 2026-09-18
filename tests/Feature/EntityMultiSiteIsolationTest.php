<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Repositories\EntityRepository;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityMultiSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected EntityRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->repo = new EntityRepository();
    }

    public function test_site_a_cannot_see_site_b_entities(): void
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
                'slug' => 'product-a',
                'name' => 'Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'product-b',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $products = $this->repo->getProducts();
        $this->assertCount(1, $products);
        $this->assertEquals('Product A', $products->first()->name);

        SiteContext::setSite($siteB);
        $products = $this->repo->getProducts();
        $this->assertCount(1, $products);
        $this->assertEquals('Product B', $products->first()->name);
    }

    public function test_different_sites_can_have_same_slug(): void
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

        $this->assertEquals(2, Entity::withoutSiteScope()->where('slug', 'shared-slug')->count());
    }

    public function test_find_by_slug_returns_current_site_entity(): void
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
                'slug' => 'shared',
                'name' => 'Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'shared',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $foundA = $this->repo->findByTypeAndSlug('product', 'shared');
        $this->assertEquals('Product A', $foundA->name);

        SiteContext::setSite($siteB);
        $foundB = $this->repo->findByTypeAndSlug('product', 'shared');
        $this->assertEquals('Product B', $foundB->name);
    }

    public function test_cross_site_relation_is_rejected(): void
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
                'slug' => 'service-a',
                'name' => 'Service A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB, &$entityB) {
            $entityB = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'product-b',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);

        $this->expectException(\Exception::class);
        $this->repo->createRelation($entityA, $entityB, 'uses');
    }

    public function test_search_does_not_cross_sites(): void
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
                'slug' => 'crispy-a',
                'name' => 'Crispy Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'crispy-b',
                'name' => 'Crispy Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $results = $this->repo->search('Crispy');
        $this->assertCount(1, $results);
        $this->assertEquals('Crispy Product A', $results->first()->name);
    }

    public function test_delete_entity_only_affects_current_site(): void
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

        $entityBId = null;

        SiteContext::withSite($siteA, function () use ($siteA) {
            Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'product-a',
                'name' => 'Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB, &$entityBId) {
            $entityB = Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'product-b',
                'name' => 'Product B',
                'status' => 'published',
            ]);
            $entityBId = $entityB->id;
        });

        SiteContext::setSite($siteA);
        Entity::where('slug', 'product-a')->delete();

        SiteContext::setSite($siteB);
        $this->assertNotNull(Entity::find($entityBId));
    }

    public function test_update_entity_only_affects_current_site(): void
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
                'slug' => 'shared',
                'name' => 'Product A Original',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'shared',
                'name' => 'Product B Original',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        Entity::where('slug', 'shared')->update(['name' => 'Product A Updated']);

        SiteContext::setSite($siteB);
        $productB = Entity::where('slug', 'shared')->first();
        $this->assertEquals('Product B Original', $productB->name);
    }

    public function test_relations_are_site_isolated(): void
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

        $repo = $this->repo;
        SiteContext::withSite($siteA, function () use ($siteA, $repo) {
            $service = Entity::create([
                'site_id' => $siteA->id,
                'type' => 'service',
                'slug' => 'svc-a',
                'name' => 'Service A',
                'status' => 'published',
            ]);

            $product = Entity::create([
                'site_id' => $siteA->id,
                'type' => 'product',
                'slug' => 'prod-a',
                'name' => 'Product A',
                'status' => 'published',
            ]);

            $repo->createRelation($service, $product, 'uses');
        });

        SiteContext::setSite($siteB);
        $this->assertEquals(0, EntityRelation::count());
    }

    public function test_with_site_scope_restores_context_after_exception(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $defaultSite = Site::where('is_default', true)->first();
        SiteContext::setSite($defaultSite);

        try {
            SiteContext::withSite($siteA, function () {
                throw new \Exception('Test exception');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals($defaultSite->id, SiteContext::currentSite()->id);
    }

    public function test_nested_with_site_restores_correct_context(): void
    {
        $defaultSite = Site::where('is_default', true)->first();
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

        SiteContext::setSite($defaultSite);

        SiteContext::withSite($siteA, function () use ($siteA, $siteB) {
            $this->assertEquals($siteA->id, SiteContext::currentSite()->id);

            SiteContext::withSite($siteB, function () use ($siteB) {
                $this->assertEquals($siteB->id, SiteContext::currentSite()->id);
            });

            $this->assertEquals($siteA->id, SiteContext::currentSite()->id);
        });

        $this->assertEquals($defaultSite->id, SiteContext::currentSite()->id);
    }

    public function test_explicit_site_id_query_is_filtered_by_scope(): void
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
                'slug' => 'prod-a',
                'name' => 'Product A',
                'status' => 'published',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Entity::create([
                'site_id' => $siteB->id,
                'type' => 'product',
                'slug' => 'prod-b',
                'name' => 'Product B',
                'status' => 'published',
            ]);
        });

        SiteContext::setSite($siteA);
        $found = Entity::where('site_id', $siteB->id)->get();
        $this->assertCount(0, $found);
    }
}
