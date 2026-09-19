<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Services\Seo\SeoMetaResolver;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetaResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    // ==========================================
    // Title Resolution Chain Tests
    // ==========================================

    public function test_title_seo_meta_highest_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'seo_title' => 'Content SEO Title',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'SeoMeta Title',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('SeoMeta Title', $result->title);
    }

    public function test_title_content_seo_title_second_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'seo_title' => 'Content SEO Title',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Content SEO Title', $result->title);
    }

    public function test_title_content_title_third_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Content Title', $result->title);
    }

    public function test_title_site_name_fourth_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => '',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Site Name', $result->title);
    }

    public function test_title_system_fallback(): void
    {
        $site = Site::create([
            'name' => '',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => '',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Website', $result->title);
    }

    // ==========================================
    // Description Resolution Chain Tests
    // ==========================================

    public function test_description_seo_meta_highest_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'description' => 'Site Description',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'seo_desc' => 'Content SEO Desc',
            'summary' => 'Content Summary',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'description' => 'SeoMeta Description',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('SeoMeta Description', $result->description);
    }

    public function test_description_content_seo_desc_second_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'description' => 'Site Description',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'seo_desc' => 'Content SEO Desc',
            'summary' => 'Content Summary',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Content SEO Desc', $result->description);
    }

    public function test_description_content_summary_third_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'description' => 'Site Description',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'summary' => 'Content Summary',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Content Summary', $result->description);
    }

    public function test_description_site_description_fourth_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'description' => 'Site Description',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'summary' => '',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Site Description', $result->description);
    }

    public function test_description_system_fallback(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'description' => '',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Content Title',
            'summary' => '',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('', $result->description);
    }

    // ==========================================
    // Entity Resolution Chain Tests
    // ==========================================

    public function test_entity_title_seo_meta_highest_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Entity Name',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'SeoMeta Title',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('SeoMeta Title', $result->title);
    }

    public function test_entity_title_entity_name_second_priority(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Entity Name',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('Entity Name', $result->title);
    }

    public function test_entity_description_summary_then_description(): void
    {
        $site = Site::create([
            'name' => 'Site Name',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Entity Name',
            'summary' => 'Entity Summary',
            'description' => 'Entity Description',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('Entity Summary', $result->description);
    }

    // ==========================================
    // Canonical Tests
    // ==========================================

    public function test_home_canonical_ends_with_slash(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveSite($site);

        $this->assertEquals('https://example.com/', $result->canonical);
    }

    public function test_content_canonical_no_trailing_slash(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'article',
            'title' => 'Test Page',
            'slug' => 'test-slug',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('https://example.com/article/test-slug', $result->canonical);
    }

    public function test_entity_canonical_no_trailing_slash(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('https://example.com/product/test-product', $result->canonical);
    }

    public function test_canonical_always_https(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertStringStartsWith('https://', $result->canonical);
    }

    public function test_canonical_no_query_string(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertStringNotContainsString('?', $result->canonical);
    }

    public function test_custom_canonical_overrides_generated(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'canonical' => 'https://custom.example.com/page',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('https://custom.example.com/page', $result->canonical);
    }

    // ==========================================
    // OG Image Tests
    // ==========================================

    public function test_og_image_from_content_og_image_id(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'logo' => '/site-logo.png',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $media = Media::create([
            'site_id' => $site->id,
            'disk' => 'public',
            'path' => '/uploads/og-image.jpg',
            'original_name' => 'og-image.jpg',
            'mime' => 'image/jpeg',
            'size' => 100000,
        ]);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'og_image_id' => $media->id,
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('/uploads/og-image.jpg', $result->ogImage);
    }

    public function test_og_image_fallback_to_cover_id(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'logo' => '/site-logo.png',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $media = Media::create([
            'site_id' => $site->id,
            'disk' => 'public',
            'path' => '/uploads/cover.jpg',
            'original_name' => 'cover.jpg',
            'mime' => 'image/jpeg',
            'size' => 100000,
        ]);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'cover_id' => $media->id,
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('/uploads/cover.jpg', $result->ogImage);
    }

    public function test_og_image_fallback_to_site_logo(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'logo' => '/site-logo.png',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('/site-logo.png', $result->ogImage);
    }

    // ==========================================
    // noindex Tests
    // ==========================================

    public function test_noindex_flag_from_content(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'noindex' => true,
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertTrue($result->noindex);
    }

    public function test_noindex_flag_from_seo_meta(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'noindex' => true,
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertTrue($result->noindex);
    }

    // ==========================================
    // Site Isolation Tests
    // ==========================================

    public function test_site_isolation_seo_meta(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'domain' => 'a.example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($siteA);

        $contentA = Content::create([
            'site_id' => $siteA->id,
            'type' => 'page',
            'title' => 'Content A',
            'slug' => 'content-a',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $siteA->id,
            'content_id' => $contentA->id,
            'title' => 'SEO A Title',
        ]);

        $resolver = app(SeoMetaResolver::class);

        SiteContext::setSite($siteA);
        $resultA = $resolver->resolveContent($contentA);
        $this->assertEquals('SEO A Title', $resultA->title);
        $this->assertStringContainsString('a.example.com', $resultA->canonical);

        // Switch to Site B
        SiteContext::setSite($siteB);
        $this->assertStringContainsString('b.example.com', $resolver->resolveSite($siteB)->canonical);
    }

    // ==========================================
    // SeoResult toArray
    // ==========================================

    public function test_seo_result_to_array(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'example.com',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveSite($site);

        $array = $result->toArray();

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('canonical', $array);
        $this->assertArrayHasKey('og_title', $array);
        $this->assertArrayHasKey('og_type', $array);
        $this->assertArrayHasKey('og_image', $array);
    }
}
