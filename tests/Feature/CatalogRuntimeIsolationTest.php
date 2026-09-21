<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalizeSlash;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 14 / D.2：Catalog 站点隔离运行时回归。
 *
 * 旧 Facts 是全局配置，空站也能渲染产品 / 场景 / 工厂 / 合作 / 关于 / 联系；
 * Catalog 是当前站点 Entity 的投影读模型。本测试锁定：
 *   - 空目录站（无 organization Entity）所有目录型前台页面 404，仅首页 / 知识中心可达；
 *   - 空目录站主导航 / 页脚 / 首页不出现任何他站目录链接或目录名称；
 *   - 完整目录站正常渲染自己的产品 / 场景 / 详情；
 *   - products.show 同一路由承载系列（目录型 / 带斜杠）与核心产品详情（详情型 / 无斜杠），
 *     斜杠方向由当前站 Catalog 实体类型决定，查无实体返回 null（交控制器 404）。
 */
class CatalogRuntimeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteA = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->siteA->update(['name' => 'Site A', 'domain' => 'example.com']);
        SiteContext::setSite($this->siteA);
    }

    private function makeEmptySiteB(): Site
    {
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    public function test_empty_site_catalog_pages_are_404_but_home_and_knowledge_are_reachable(): void
    {
        $this->makeEmptySiteB();

        foreach (['/products/', '/solutions/', '/factory/', '/cooperation/', '/about/profile/', '/contact/'] as $path) {
            $this->get('https://b.example.com' . $path)->assertNotFound();
        }

        // 空目录站首页与知识中心（Content 域）仍然可达
        $this->get('https://b.example.com/')->assertOk();
        $this->get('https://b.example.com/knowledge/')->assertOk();
    }

    public function test_empty_site_product_line_scene_detail_paths_are_404(): void
    {
        // 先在 A 站播种目录，拿到本站真实 slug，再证明 B 站无法借这些 slug 命中
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $lineSlug = Catalog::productLines()[0]['slug'];
        $coreSlug = Catalog::coreProductSlugs()[0];
        $sceneSlug = Catalog::scenes()[0]['slug'];
        $this->makeEmptySiteB();

        $this->get('https://b.example.com/products/' . $lineSlug . '/')->assertNotFound();
        $this->get('https://b.example.com/products/' . $coreSlug)->assertNotFound();
        $this->get('https://b.example.com/solutions/' . $sceneSlug . '/')->assertNotFound();
    }

    public function test_empty_site_home_has_no_foreign_catalog_links_or_names(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $leaves = [];
        foreach (Catalog::productLines() as $line) {
            $leaves[] = '/products/' . $line['slug'];
            $leaves[] = $line['name'];
        }
        foreach (Catalog::coreProductSlugs() as $slug) {
            $leaves[] = '/products/' . $slug;
        }
        foreach (Catalog::scenes() as $scene) {
            $leaves[] = '/solutions/' . $scene['slug'];
            $leaves[] = $scene['name'];
        }
        $this->makeEmptySiteB();

        $html = $this->get('https://b.example.com/')->assertOk()->getContent();

        foreach ($leaves as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Empty site home leaked catalog item: $needle");
        }
        // 目录型顶级入口在空站导航 / 页脚中也不应出现
        foreach (['/products/', '/solutions/', '/factory/', '/cooperation/', '/about/profile/', '/contact/'] as $path) {
            $this->assertStringNotContainsString('b.example.com' . $path, $html, "Empty site home leaked catalog entry: $path");
        }
    }

    public function test_populated_site_renders_its_own_catalog_pages(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        $line = Catalog::productLines()[0];
        $scene = Catalog::scenes()[0];
        $coreSlug = Catalog::coreProductSlugs()[0];

        $this->get('https://example.com/products/')
            ->assertOk()
            ->assertSee($line['name'], false);
        $this->get('https://example.com/solutions/')
            ->assertOk()
            ->assertSee($scene['name'], false);
        // 核心产品详情（详情型，无尾斜杠）在本站可达
        $this->get('https://example.com/products/' . $coreSlug)->assertOk();
    }

    public function test_product_slash_direction_follows_current_site_catalog_entity_type(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        // 产品系列 = 目录型（带尾斜杠）
        $this->assertTrue(CanonicalizeSlash::resolveProductWantsSlash(Catalog::productLines()[0]['slug']));
        // 核心产品详情 = 详情型（无尾斜杠）
        $this->assertFalse(CanonicalizeSlash::resolveProductWantsSlash(Catalog::coreProductSlugs()[0]));
        // 当前站不存在的 slug：不跳转，交控制器 404
        $this->assertNull(CanonicalizeSlash::resolveProductWantsSlash('does-not-exist'));
    }

    public function test_empty_site_slash_resolution_is_null_so_controller_404s(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $coreSlug = Catalog::coreProductSlugs()[0];
        $this->makeEmptySiteB();

        // 切到空站 B 的上下文后，A 站存在的产品 slug 在 B 站解析为 null（不做 301，直接 404）
        SiteContext::setSite(Site::where('slug', 'site-b')->firstOrFail());
        Catalog::flush();

        $this->assertNull(CanonicalizeSlash::resolveProductWantsSlash($coreSlug));
    }
}
