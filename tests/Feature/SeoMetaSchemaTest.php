<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetaSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_seo_metas_table_exists(): void
    {
        $this->assertTrue(\Schema::hasTable('seo_metas'));
    }

    public function test_seo_metas_has_required_columns(): void
    {
        $columns = \Schema::getColumnListing('seo_metas');

        $this->assertContains('id', $columns);
        $this->assertContains('site_id', $columns);
        $this->assertContains('content_id', $columns);
        $this->assertContains('entity_id', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('description', $columns);
        $this->assertContains('keywords', $columns);
        $this->assertContains('canonical', $columns);
        $this->assertContains('og_title', $columns);
        $this->assertContains('og_description', $columns);
        $this->assertContains('og_image_path', $columns);
        $this->assertContains('og_type', $columns);
        $this->assertContains('twitter_card', $columns);
        $this->assertContains('noindex', $columns);
        $this->assertContains('nofollow', $columns);
        $this->assertContains('robots', $columns);
        $this->assertContains('schema_type', $columns);
        $this->assertContains('metadata', $columns);
    }

    public function test_site_level_seo_meta_can_be_created(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'Test Site SEO',
            'description' => 'Test description',
        ]);

        $this->assertNotNull($seoMeta->id);
        $this->assertTrue($seoMeta->isSiteLevel());
        $this->assertFalse($seoMeta->isContentLevel());
        $this->assertFalse($seoMeta->isEntityLevel());
    }

    public function test_content_level_seo_meta_can_be_created(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $content = \App\Models\Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'Test Page SEO',
        ]);

        $this->assertNotNull($seoMeta->id);
        $this->assertTrue($seoMeta->isContentLevel());
        $this->assertFalse($seoMeta->isSiteLevel());
        $this->assertFalse($seoMeta->isEntityLevel());
    }

    public function test_entity_level_seo_meta_can_be_created(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'Test Product SEO',
        ]);

        $this->assertNotNull($seoMeta->id);
        $this->assertTrue($seoMeta->isEntityLevel());
        $this->assertFalse($seoMeta->isSiteLevel());
        $this->assertFalse($seoMeta->isContentLevel());
    }

    public function test_cannot_create_with_both_content_and_entity(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $content = \App\Models\Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $this->expectException(\Exception::class);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'entity_id' => $entity->id,
            'title' => 'Invalid',
        ]);
    }

    public function test_only_one_site_level_seo_meta_per_site(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'First SEO',
        ]);

        $this->expectException(\Exception::class);

        SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'Second SEO',
        ]);
    }

    public function test_only_one_content_level_seo_meta_per_content(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $content = \App\Models\Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'First SEO',
        ]);

        $this->expectException(\Exception::class);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'Second SEO',
        ]);
    }

    public function test_only_one_entity_level_seo_meta_per_entity(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'First SEO',
        ]);

        $this->expectException(\Exception::class);

        SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'Second SEO',
        ]);
    }

    public function test_seo_meta_site_isolation(): void
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
            SeoMeta::create([
                'site_id' => $siteA->id,
                'title' => 'Site A SEO',
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            SeoMeta::create([
                'site_id' => $siteB->id,
                'title' => 'Site B SEO',
            ]);
        });

        SiteContext::setSite($siteA);
        $this->assertEquals(1, SeoMeta::count());
        $this->assertEquals('Site A SEO', SeoMeta::first()->title);

        SiteContext::setSite($siteB);
        $this->assertEquals(1, SeoMeta::count());
        $this->assertEquals('Site B SEO', SeoMeta::first()->title);
    }

    public function test_seo_meta_json_fields_cast(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'Test',
            'keywords' => ['php', 'laravel'],
            'robots' => ['index', 'follow'],
            'metadata' => ['custom' => 'value'],
        ]);

        $this->assertEquals(['php', 'laravel'], $seoMeta->keywords);
        $this->assertEquals(['index', 'follow'], $seoMeta->robots);
        $this->assertEquals(['custom' => 'value'], $seoMeta->metadata);
    }

    public function test_seo_meta_fk_to_site(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'Test',
        ]);

        $this->assertEquals($site->id, $seoMeta->site->id);
    }

    public function test_seo_meta_fk_to_content(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $content = \App\Models\Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'Test',
        ]);

        $contentRel = $seoMeta->content()->withoutSiteScope()->first();
        $this->assertEquals($content->id, $contentRel->id);
    }

    public function test_seo_meta_fk_to_entity(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $seoMeta = SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'Test',
        ]);

        $entityRel = $seoMeta->entity()->withoutSiteScope()->first();
        $this->assertEquals($entity->id, $entityRel->id);
    }
}
