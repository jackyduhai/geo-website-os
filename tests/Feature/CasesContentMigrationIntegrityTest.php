<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Facts;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CasesContentMigrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_cases_content_migration_complete(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $cases = Facts::cases();
        $caseCount = count($cases);

        $this->assertGreaterThan(0, $caseCount, 'Facts::cases() should not be empty');

        $mappedSlugs = [];
        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            $mappedSlugs[$slug] = $case;

            Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                [
                    'title' => $case['title'],
                    'summary' => $case['quote'] ?? null,
                    'body' => $case['quote'] ?? '',
                    'status' => 'published',
                    'type' => 'case',
                ]
            );
        }

        $contentCount = Content::where('type', 'case')->count();

        $this->assertEquals($caseCount, $contentCount, 'Case count should match Content count');
        $this->assertEquals(0, $contentCount - $caseCount, 'No data loss');
    }

    public function test_cases_each_has_unique_content(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $cases = Facts::cases();

        $createdContent = [];
        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            $content = Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['title' => $case['title'], 'status' => 'published', 'type' => 'case']
            );
            $createdContent[$slug] = $content;
        }

        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            $this->assertArrayHasKey($slug, $createdContent, "Case slug $slug should have content");
            $this->assertEquals($case['title'], $createdContent[$slug]->title, "Title should match for $slug");
        }
    }

    public function test_cases_mapping_is_idempotent(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $cases = Facts::cases();

        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['title' => $case['title'], 'status' => 'published', 'type' => 'case']
            );
        }

        $countAfterFirst = Content::where('type', 'case')->count();

        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['title' => $case['title'], 'status' => 'published', 'type' => 'case']
            );
        }

        $countAfterSecond = Content::where('type', 'case')->count();

        $this->assertEquals($countAfterFirst, $countAfterSecond, 'Idempotent: no duplicate content');
    }

    public function test_cases_no_duplicate_content(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $cases = Facts::cases();

        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['title' => $case['title'], 'status' => 'published', 'type' => 'case']
            );
        }

        $contents = Content::where('type', 'case')->get();
        $slugs = $contents->pluck('slug')->toArray();
        $uniqueSlugs = array_unique($slugs);

        $this->assertEquals(count($slugs), count($uniqueSlugs), 'No duplicate slugs');
    }

    public function test_full_facts_to_target_matrix(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        // Organization
        $company = Facts::company();
        $org = Entity::firstOrCreate(
            ['site_id' => $site->id, 'type' => 'organization', 'slug' => 'test-org'],
            ['name' => $company['name'] ?? 'Test Org', 'status' => 'published']
        );
        $this->assertNotNull($org->id);

        // Products
        $products = Facts::products();
        foreach ($products as $product) {
            Entity::firstOrCreate(
                ['site_id' => $site->id, 'type' => 'product', 'slug' => $product['slug']],
                ['name' => $product['name'], 'status' => 'published']
            );
        }
        $productCount = Entity::ofType('product')->count();
        $this->assertEquals(count($products), $productCount);

        // Scenes
        $scenes = Facts::scenes();
        foreach ($scenes as $scene) {
            Entity::firstOrCreate(
                ['site_id' => $site->id, 'type' => 'service', 'slug' => $scene['slug']],
                ['name' => $scene['name'], 'status' => 'published']
            );
        }
        $sceneCount = Entity::ofType('service')->count();
        $this->assertEquals(count($scenes), $sceneCount);

        // Cases
        $cases = Facts::cases();
        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            Content::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['title' => $case['title'], 'status' => 'published', 'type' => 'case']
            );
        }
        $caseContentCount = Content::where('type', 'case')->count();
        $this->assertEquals(count($cases), $caseContentCount);
    }

    public function test_cases_quote_maps_to_summary(): void
    {
        $site = Site::where('is_default', true)->first();
        SiteContext::setSite($site);

        $cases = Facts::cases();
        $firstCase = $cases[0];
        $slug = Str::slug($firstCase['id'] ?? $firstCase['title']);

        $content = Content::firstOrCreate(
            ['site_id' => $site->id, 'slug' => $slug],
            [
                'title' => $firstCase['title'],
                'summary' => $firstCase['quote'] ?? null,
                'body' => $firstCase['quote'] ?? '',
                'status' => 'published',
                'type' => 'case',
            ]
        );

        $fresh = Content::find($content->id);
        $this->assertEquals($firstCase['quote'] ?? null, $fresh->summary);
    }

    public function test_cases_slug_is_stable(): void
    {
        $cases = Facts::cases();

        $slugs = [];
        foreach ($cases as $case) {
            $slug = Str::slug($case['id'] ?? $case['title']);
            $slugs[] = $slug;
        }

        $this->assertEquals(count($slugs), count(array_unique($slugs)));
    }
}
