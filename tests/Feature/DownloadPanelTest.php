<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2b Gate：DownloadAsset 最小闭环（非弱 DAM）。
 *  - Product --offers--> DownloadAsset → Media；download_panel 仅在产品详情渲染；
 *  - 无独立 /downloads 页、不进 sitemap/search、不产独立 JSON-LD；
 *  - media_id 缺失 graceful skip（不 500、不暴露物理路径）。
 */
class DownloadPanelTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
    }

    /** 取一个 core 产品（seed 数据中 core=true 的产品）。 */
    private function coreProduct(): Entity
    {
        return Entity::where('type', Entity::TYPE_PRODUCT)
            ->where('locale', 'zh-CN')
            ->where('status', 'published')
            ->where('metadata->core', true)
            ->firstOrFail();
    }

    public function test_download_panel_renders_on_product_detail(): void
    {
        $product = $this->coreProduct();
        $asset = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'x1-datasheet', 'name' => 'X1 技术数据表', 'status' => 'published',
            'locale' => 'zh-CN',
            'metadata' => ['type' => 'datasheet', 'language' => 'zh-CN', 'version' => '2.1'],
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $product->id,
            'to_entity_id' => $asset->id, 'relation_type' => EntityRelation::TYPE_OFFERS,
        ]);

        $res = $this->get('/products/' . $product->slug)->assertOk();
        $res->assertSee('X1 技术数据表');
        $res->assertSee('datasheet', false);
    }

    public function test_no_standalone_downloads_route(): void
    {
        $this->get('/downloads')->assertNotFound();
        $this->get('/downloads/x1-datasheet')->assertNotFound();
    }

    public function test_download_asset_not_in_sitemap(): void
    {
        $product = $this->coreProduct();
        $asset = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'sitemap-asset', 'name' => 'Sitemap Asset', 'status' => 'published',
            'locale' => 'zh-CN', 'metadata' => ['type' => 'manual'],
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $product->id,
            'to_entity_id' => $asset->id, 'relation_type' => EntityRelation::TYPE_OFFERS,
        ]);
        $xml = $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('/downloads/sitemap-asset', $xml);
        $this->assertStringNotContainsString('download_asset/sitemap-asset', $xml);
    }

    public function test_asset_without_media_id_does_not_crash_product_detail(): void
    {
        $product = $this->coreProduct();
        // media_id 缺失 → download_panel 应 graceful skip 链接，不 500
        $asset = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'orphan-asset', 'name' => 'Orphan Asset', 'status' => 'published',
            'locale' => 'zh-CN', 'metadata' => ['type' => 'manual'],
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $product->id,
            'to_entity_id' => $asset->id, 'relation_type' => EntityRelation::TYPE_OFFERS,
        ]);

        $res = $this->get('/products/' . $product->slug);
        $res->assertOk();
        $res->assertSee('Orphan Asset');
    }
}
