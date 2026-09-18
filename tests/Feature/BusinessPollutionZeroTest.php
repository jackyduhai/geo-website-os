<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Repositories\EntityRepository;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessPollutionZeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_entity_model_has_no_business_hardcode(): void
    {
        $path = app_path('Models/Entity.php');
        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "Entity.php contains business keyword: $keyword"
            );
        }
    }

    public function test_entity_relation_model_has_no_business_hardcode(): void
    {
        $path = app_path('Models/EntityRelation.php');
        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "EntityRelation.php contains business keyword: $keyword"
            );
        }
    }

    public function test_site_model_has_no_business_hardcode(): void
    {
        $path = app_path('Models/Site.php');
        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "Site.php contains business keyword: $keyword"
            );
        }
    }

    public function test_entity_repository_has_no_business_hardcode(): void
    {
        $path = app_path('Repositories/EntityRepository.php');
        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "EntityRepository.php contains business keyword: $keyword"
            );
        }
    }

    public function test_site_context_has_no_business_hardcode(): void
    {
        $path = app_path('Support/SiteContext.php');
        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "SiteContext.php contains business keyword: $keyword"
            );
        }
    }

    public function test_site_scope_has_no_business_hardcode(): void
    {
        $path = app_path('Models/Scopes/SiteScope.php');
        if (!file_exists($path)) {
            $this->markTestSkipped('SiteScope.php not found');
        }

        $content = file_get_contents($path);

        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "SiteScope.php contains business keyword: $keyword"
            );
        }
    }

    public function test_site_cache_key_has_no_business_hardcode(): void
    {
        $path = app_path('Support/SiteCacheKey.php');
        if (!file_exists($path)) {
            $this->markTestSkipped('SiteCacheKey.php not found');
        }

        $content = file_get_contents($path);

        $keywords = ['sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        foreach ($keywords as $keyword) {
            $this->assertFalse(
                stripos($content, $keyword) !== false,
                "SiteCacheKey.php contains business keyword: $keyword"
            );
        }
    }

    public function test_entity_migrations_have_no_business_hardcode(): void
    {
        $keywords = ['demo-tenant-a', 'sample-city', 'sample-province', 'Sample Snack', 'Sample Marinade', 'Demo Tenant A'];

        $migrationFiles = glob(database_path('migrations/*entities*.php'));
        $migrationFiles = array_merge($migrationFiles, glob(database_path('migrations/*sites*.php')));

        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = basename($file);

            foreach ($keywords as $keyword) {
                $this->assertFalse(
                    stripos($content, $keyword) !== false,
                    "$relativePath contains business keyword: $keyword"
                );
            }
        }
    }

    public function test_generic_entity_data_has_no_business_pollution(): void
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
                'slug' => 'demo-manufacturing',
                'name' => 'Demo Manufacturing Singapore',
                'status' => 'published',
            ]);
        });

        $org = Entity::withoutSiteScope()->where('slug', 'demo-manufacturing')->first();

        $this->assertStringNotContainsString('demo-tenant-a', strtolower($org->name));
        $this->assertStringNotContainsString('sample-city', strtolower($org->name));
    }

    public function test_entity_type_constants_are_generic(): void
    {
        $reflection = new \ReflectionClass(Entity::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('TYPE_ORGANIZATION', $constants);
        $this->assertArrayHasKey('TYPE_PRODUCT', $constants);
        $this->assertArrayHasKey('TYPE_SERVICE', $constants);
        $this->assertArrayHasKey('TYPE_PERSON', $constants);
        $this->assertArrayHasKey('TYPE_LOCATION', $constants);
        $this->assertArrayHasKey('TYPE_TOPIC', $constants);
    }

    public function test_relation_type_constants_are_generic(): void
    {
        $reflection = new \ReflectionClass(EntityRelation::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('TYPE_PRODUCES', $constants);
        $this->assertArrayHasKey('TYPE_OFFERS', $constants);
        $this->assertArrayHasKey('TYPE_USES', $constants);
    }

    public function test_new_entity_tables_are_clean(): void
    {
        $this->assertTrue(\Schema::hasTable('entities'));
        $this->assertTrue(\Schema::hasTable('entity_relations'));

        $columns = \Schema::getColumnListing('entities');
        $this->assertContains('site_id', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('slug', $columns);
        $this->assertContains('name', $columns);

        $relationColumns = \Schema::getColumnListing('entity_relations');
        $this->assertContains('site_id', $relationColumns);
        $this->assertContains('from_entity_id', $relationColumns);
        $this->assertContains('to_entity_id', $relationColumns);
        $this->assertContains('relation_type', $relationColumns);
    }
}
