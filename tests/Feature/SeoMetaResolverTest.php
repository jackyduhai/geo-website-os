<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Entity;
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

    public function test_resolve_site_level_seo(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'description' => 'Site description',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        SeoMeta::create([
            'site_id' => $site->id,
            'title' => 'Site SEO Title',
            'description' => 'Site SEO Description',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveSite($site);

        $this->assertEquals('Site SEO Title', $result->title);
        $this->assertEquals('Site SEO Description', $result->description);
        $this->assertStringContainsString('test.test', $result->canonical);
    }

    public function test_resolve_content_level_seo(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page',
            'slug' => 'test-page',
            'summary' => 'Page summary',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'title' => 'Content SEO Title',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Content SEO Title', $result->title);
    }

    public function test_resolve_content_fallback_to_content_fields(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $content = Content::create([
            'site_id' => $site->id,
            'type' => 'page',
            'title' => 'Test Page Title',
            'slug' => 'test-page',
            'summary' => 'Page summary text',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveContent($content);

        $this->assertEquals('Test Page Title', $result->title);
        $this->assertEquals('Page summary text', $result->description);
    }

    public function test_resolve_entity_level_seo(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'summary' => 'Product summary',
            'status' => 'published',
        ]);

        SeoMeta::create([
            'site_id' => $site->id,
            'entity_id' => $entity->id,
            'title' => 'Entity SEO Title',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('Entity SEO Title', $result->title);
    }

    public function test_resolve_entity_fallback_to_entity_fields(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product Name',
            'summary' => 'Product summary',
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('Test Product Name', $result->title);
        $this->assertEquals('Product summary', $result->description);
    }

    public function test_canonical_generated_for_content(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
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

        $this->assertStringContainsString('https://test.test', $result->canonical);
        $this->assertStringContainsString('test-page', $result->canonical);
    }

    public function test_canonical_generated_for_entity(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
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

        $this->assertStringContainsString('https://test.test', $result->canonical);
        $this->assertStringContainsString('test-product', $result->canonical);
    }

    public function test_custom_canonical_overrides_generated(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
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

    public function test_og_image_inheritance(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'logo' => '/site-logo.png',
            'status' => 'active',
            'is_default' => false,
        ]);

        SiteContext::setSite($site);

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'metadata' => ['og_image' => '/entity-og.png'],
            'status' => 'published',
        ]);

        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolveEntity($entity);

        $this->assertEquals('/entity-og.png', $result->ogImage);
    }

    public function test_og_image_fallback_to_site_logo(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
            'logo' => '/site-logo.png',
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

        $this->assertEquals('/site-logo.png', $result->ogImage);
    }

    public function test_noindex_flag(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
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

    public function test_seo_result_to_array(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'domain' => 'test.test',
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
    }
}
