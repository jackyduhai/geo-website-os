<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Setting;
use App\Models\Menu;
use App\Models\Redirect;
use App\Support\PageCache;
use App\Support\SiteCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteAwareCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cache_keys_include_site_id(): void
    {
        $this->assertStringStartsWith('site:1:', SiteCacheKey::settings());
        $this->assertStringStartsWith('site:1:', SiteCacheKey::navTree());
        $this->assertStringStartsWith('site:1:', SiteCacheKey::redirectsActive());
        $this->assertStringStartsWith('site:1:', SiteCacheKey::pagecacheVersion());
    }

    /** @test */
    public function demo-tenant-a_settings_cache_key_is_gone(): void
    {
        // The old hardcoded key should not exist as a constant anymore
        $reflection = new \ReflectionClass(Setting::class);
        $this->assertFalse($reflection->hasConstant('CACHE_KEY'), 'Setting::CACHE_KEY should not exist');
    }

    /** @test */
    public function different_sites_have_different_cache_keys(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'cache-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'cache-b', 'status' => 'active']);

        // Both sites should generate different keys for same namespace
        $keyA = SiteCacheKey::make('settings', '', $siteA->id);
        $keyB = SiteCacheKey::make('settings', '', $siteB->id);
        $this->assertNotEquals($keyA, $keyB);
    }

    /** @test */
    public function settings_cache_is_site_scoped(): void
    {
        Site::create(['name' => 'B', 'slug' => 'setting-b', 'status' => 'active']);

        // Default site setting
        Setting::create(['site_id' => 1, 'key' => 'site_a_setting', 'value' => 'A_value', 'group' => 'test', 'type' => 'text']);

        // Clear cache and load
        Setting::flush();
        $all = Setting::allCached();
        $this->assertArrayHasKey('site_a_setting', $all);
        $this->assertEquals('A_value', $all['site_a_setting']);

        // The cache key should contain site:1
        $this->assertStringContainsString('site:1', SiteCacheKey::settings());
    }

    /** @test */
    public function pagecache_version_is_site_scoped(): void
    {
        $this->assertStringContainsString('site:1', SiteCacheKey::pagecacheVersion());
        $this->assertIsInt(PageCache::version());
        $this->assertGreaterThanOrEqual(1, PageCache::version());
    }

    /** @test */
    public function old_legacy_keys_are_documented(): void
    {
        $legacy = SiteCacheKey::legacyKeys();
        $this->assertContains('demo-tenant-a.settings', $legacy);
        $this->assertContains('nav.tree', $legacy);
        $this->assertContains('redirects.active', $legacy);
        $this->assertContains('pagecache:version', $legacy);
    }

    /** @test */
    public function redirect_cache_key_is_site_scoped(): void
    {
        $this->assertStringContainsString('site:1', SiteCacheKey::redirectsActive());
    }

    /** @test */
    public function menu_cache_keys_are_site_scoped(): void
    {
        $this->assertStringContainsString('site:1', SiteCacheKey::navTree());
        $this->assertStringContainsString('site:1', SiteCacheKey::mainMenu());
        $this->assertStringContainsString('site:1', SiteCacheKey::footerMenu());
    }
}
