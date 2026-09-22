<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18A / #114：Catalog 关系读模型权威源回归。
 *
 * 锁定架构结论：前台产品「适用场景 / 相关产品」、场景「组合产品 / 相邻场景 /
 * 关键参数产品」一律由权威边表 EntityRelation 单向派生（EntityRelation → Catalog
 * Read Model → Frontend），与 /geo.json 同源；Entity.metadata 里历史遗留的
 * scenes / related / combo / adjacent / key_param_product slug 数组不再作为前台
 * 关系来源。uses 是有向边，不做对称推断。
 */
class CatalogRelationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();

        $this->siteA = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->siteA->update(['name' => 'Site A', 'domain' => 'example.com']);
        SiteContext::setSite($this->siteA);
    }

    private function entity(string $type, string $slug, string $name, array $metadata = [], ?int $siteId = null, string $status = 'published'): Entity
    {
        return Entity::create([
            'site_id' => $siteId ?? $this->siteA->id,
            'type' => $type,
            'slug' => $slug,
            'name' => $name,
            'status' => $status,
            'metadata' => $metadata,
        ]);
    }

    private function relate(Entity $from, Entity $to, string $type, int $sort = 0, ?array $metadata = null): EntityRelation
    {
        return EntityRelation::create([
            'site_id' => $from->site_id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $type,
            'sort_order' => $sort,
            'metadata' => $metadata,
        ]);
    }

    private function seedCatalog(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        Catalog::flush();
    }

    /* ------------------------------------------------------------------ *
     * 1) Example 种子：边派生结果与事实库一致（换数据源不丢 demo 内容）
     * ------------------------------------------------------------------ */

    public function test_demo_seed_derives_product_scenes_and_related_from_edges(): void
    {
        $this->seedCatalog();

        $product = Catalog::product('epoxy-primer-100');
        $this->assertNotNull($product);
        $this->assertContains('equipment-manufacturing', $product['scenes']);
        $this->assertContains('construction-infrastructure', $product['scenes']);
        $this->assertContains('polyurethane-topcoat-200', $product['related']);
        $this->assertContains('structural-adhesive-a10', $product['related']);
        // 产品线是组织 metadata 配置分组（非实体关系），仍正常保留
        $this->assertSame('coatings', $product['line']);

        $this->assertCount(2, Catalog::scenesOfProduct($product));
        $this->assertGreaterThanOrEqual(3, Catalog::relatedProducts($product, 5));
    }

    public function test_demo_seed_derives_scene_combo_adjacent_and_key_param(): void
    {
        $this->seedCatalog();

        $scene = Catalog::scene('equipment-manufacturing');
        $this->assertNotNull($scene);
        $this->assertSame([
            'epoxy-primer-100',
            'polyurethane-topcoat-200',
            'structural-adhesive-a10',
            'leveling-agent-l01',
        ], $scene['combo']);
        $this->assertContains('construction-infrastructure', $scene['adjacent']);
        $this->assertContains('automotive-parts', $scene['adjacent']);
        $this->assertSame('epoxy-primer-100', $scene['key_param_product']);
        $this->assertNotEmpty($scene['key_param_display']);

        $this->assertCount(4, Catalog::sceneCombo($scene));
        $this->assertCount(2, Catalog::adjacentScenes($scene));

        // 其余两个场景的关键参数产品同样由边 metadata 派生
        $this->assertSame('silicone-sealant-s20', Catalog::scene('construction-infrastructure')['key_param_product']);
        $this->assertSame('structural-adhesive-a10', Catalog::scene('automotive-parts')['key_param_product']);
    }

    /* ------------------------------------------------------------------ *
     * 2) 权威源：metadata slug 数组在没有边时不得生效
     * ------------------------------------------------------------------ */

    public function test_product_scenes_ignore_metadata_slugs_without_edge(): void
    {
        $this->entity('organization', 'org-a', 'Org A');
        // metadata 里写了一个场景 slug，但不建任何 uses 边
        $this->entity('product', 'prod-ghost', 'Prod Ghost', [
            'core' => true,
            'scenes' => ['ghost-service'],
            'related' => ['ghost-product'],
        ]);
        Catalog::flush();

        $product = Catalog::product('prod-ghost');
        $this->assertSame([], $product['scenes']);
        $this->assertSame([], $product['related']);
    }

    public function test_scene_combo_ignore_metadata_slugs_without_edge(): void
    {
        $this->entity('organization', 'org-a2', 'Org A2');
        $this->entity('service', 'svc-ghost', 'Svc Ghost', [
            'combo' => ['ghost-product'],
            'adjacent' => ['ghost-scene'],
            'key_param_product' => 'ghost-product',
        ]);
        Catalog::flush();

        $scene = Catalog::scene('svc-ghost');
        $this->assertSame([], $scene['combo']);
        $this->assertSame([], $scene['adjacent']);
        $this->assertNull($scene['key_param_product']);
    }

    /* ------------------------------------------------------------------ *
     * 3) 手工建边 → 读模型；删边 → 消失；uses 有向、不对称反推
     * ------------------------------------------------------------------ */

    public function test_manual_uses_edges_derive_both_directions_independently(): void
    {
        $org = $this->entity('organization', 'org-dir', 'Org Dir');
        $product = $this->entity('product', 'prod-dir', 'Prod Dir', ['core' => true]);
        $service = $this->entity('service', 'svc-dir', 'Svc Dir');

        // 仅建 product -uses-> service（产品适用场景）
        $this->relate($product, $service, 'uses');
        Catalog::flush();

        $this->assertSame(['svc-dir'], Catalog::product('prod-dir')['scenes']);
        $this->assertCount(1, Catalog::scenesOfProduct(Catalog::product('prod-dir')));

        // uses 是有向边：不得自动反推出 service 的组合产品
        $this->assertSame([], Catalog::scene('svc-dir')['combo']);
        $this->assertCount(0, Catalog::sceneCombo(Catalog::scene('svc-dir')));

        // 反向边必须显式存在才生效
        $this->relate($service, $product, 'uses');
        Catalog::flush();
        $this->assertSame(['prod-dir'], Catalog::scene('svc-dir')['combo']);
    }

    public function test_related_and_adjacent_edges_derive_by_type_pair(): void
    {
        $org = $this->entity('organization', 'org-rel', 'Org Rel');
        $p1 = $this->entity('product', 'p-one', 'P One', ['core' => true]);
        $p2 = $this->entity('product', 'p-two', 'P Two', ['core' => true]);
        $s1 = $this->entity('service', 's-one', 'S One');
        $s2 = $this->entity('service', 's-two', 'S Two');

        $this->relate($p1, $p2, 'related_to');
        $this->relate($s1, $s2, 'related_to');
        // 类型不匹配的 related_to（product -> service）不得进入任何关系区块
        $this->relate($p1, $s1, 'related_to');
        Catalog::flush();

        $this->assertSame(['p-two'], Catalog::product('p-one')['related']);
        $this->assertSame(['s-two'], Catalog::scene('s-one')['adjacent']);
    }

    public function test_deleting_edge_removes_relation_from_catalog(): void
    {
        $org = $this->entity('organization', 'org-del', 'Org Del');
        $product = $this->entity('product', 'prod-del', 'Prod Del', ['core' => true]);
        $service = $this->entity('service', 'svc-del', 'Svc Del');

        $edge = $this->relate($product, $service, 'uses');
        Catalog::flush();
        $this->assertSame(['svc-del'], Catalog::product('prod-del')['scenes']);

        $edge->delete();
        Catalog::flush();
        $this->assertSame([], Catalog::product('prod-del')['scenes']);
    }

    public function test_draft_endpoint_is_excluded_from_relations(): void
    {
        $org = $this->entity('organization', 'org-draft', 'Org Draft');
        $product = $this->entity('product', 'prod-draft', 'Prod Draft', ['core' => true]);
        $service = $this->entity('service', 'svc-draft', 'Svc Draft');
        $this->relate($product, $service, 'uses');
        Catalog::flush();
        $this->assertSame(['svc-draft'], Catalog::product('prod-draft')['scenes']);

        $service->update(['status' => 'draft']);
        Catalog::flush();
        $this->assertSame([], Catalog::product('prod-draft')['scenes']);

        $service->update(['status' => 'published']);
        Catalog::flush();
        $this->assertSame(['svc-draft'], Catalog::product('prod-draft')['scenes']);
    }

    public function test_key_param_product_comes_from_uses_edge_metadata(): void
    {
        $org = $this->entity('organization', 'org-kp', 'Org KP');
        $service = $this->entity('service', 'svc-kp', 'Svc KP');
        $other = $this->entity('product', 'prod-other', 'Prod Other', ['core' => true]);
        $key = $this->entity('product', 'prod-key', 'Prod Key', ['core' => true]);

        $this->relate($service, $other, 'uses', 0);
        $this->relate($service, $key, 'uses', 1, ['role' => 'key_param', 'display' => 'KP-DISPLAY']);
        Catalog::flush();

        $scene = Catalog::scene('svc-kp');
        $this->assertSame(['prod-other', 'prod-key'], $scene['combo']);
        $this->assertSame('prod-key', $scene['key_param_product']);
        $this->assertSame('KP-DISPLAY', $scene['key_param_display']);
    }

    /* ------------------------------------------------------------------ *
     * 4) 多站隔离
     * ------------------------------------------------------------------ */

    public function test_relations_do_not_leak_across_sites(): void
    {
        // A 站：service -uses-> product（场景组合）
        $orgA = $this->entity('organization', 'org-a-iso', 'Org A Iso');
        $prodA = $this->entity('product', 'prod-a-iso', 'Prod A AAA', ['core' => true]);
        $svcA = $this->entity('service', 'svc-a-iso', 'Svc A AAA');
        $this->relate($svcA, $prodA, 'uses');

        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b-18a', 'domain' => 'b18a.example.com',
            'status' => 'active', 'is_default' => false,
        ]);

        SiteContext::withSite($siteB, function () use ($siteB) {
            $orgB = $this->entity('organization', 'org-b-iso', 'Org B', [], $siteB->id);
            $prodB = $this->entity('product', 'prod-b-iso', 'Prod B BBB', ['core' => true], $siteB->id);
            $svcB = $this->entity('service', 'svc-b-iso', 'Svc B BBB', [], $siteB->id);
            // B 站不建任何关系边
        });

        Catalog::flush();
        SiteContext::setSite($siteB);
        Catalog::flush();
        $this->assertSame([], Catalog::scene('svc-b-iso')['combo']);
        $this->assertSame([], Catalog::product('prod-b-iso')['scenes']);

        // B 站场景页不得出现 A 站组合产品链接
        $this->get('https://b18a.example.com/solutions/svc-b-iso/')
            ->assertOk()
            ->assertDontSee('/products/prod-a-iso', false);

        SiteContext::setSite($this->siteA);
        Catalog::flush();
        $this->assertSame(['prod-a-iso'], Catalog::scene('svc-a-iso')['combo']);
        $this->get('https://example.com/solutions/svc-a-iso/')
            ->assertOk()
            ->assertSee('/products/prod-a-iso', false);
    }

    /* ------------------------------------------------------------------ *
     * 5) 前台与 /geo.json 同源；后台手工新边端到端可达
     * ------------------------------------------------------------------ */

    public function test_frontend_and_geo_graph_share_seed_relation_edges(): void
    {
        $this->seedCatalog();

        // 产品详情：适用场景区块
        $this->get('https://example.com/products/epoxy-primer-100')
            ->assertOk()
            ->assertSee('装备制造', false)
            ->assertSee('建筑工程', false);

        // 场景详情：组合产品（卡片标题用 short_name）+ 关键参数文案
        $scene = Catalog::scene('equipment-manufacturing');
        $comboFirstName = Catalog::sceneCombo($scene)[0]['short_name'];
        $this->get('https://example.com/solutions/equipment-manufacturing/')
            ->assertOk()
            ->assertSee($comboFirstName, false)
            ->assertSee($scene['key_param_display'], false);

        // geo.json：两个方向的 uses 边都应作为正式关系输出
        $geo = $this->get('https://example.com/geo.json')->assertOk()->json();
        $hasProductToService = collect($geo['relations'])->contains(fn ($r) => $r['from'] === 'entity/product/epoxy-primer-100'
            && $r['to'] === 'entity/service/equipment-manufacturing'
            && $r['relation_type'] === 'uses');
        $hasServiceToProduct = collect($geo['relations'])->contains(fn ($r) => $r['from'] === 'entity/service/equipment-manufacturing'
            && $r['to'] === 'entity/product/epoxy-primer-100'
            && $r['relation_type'] === 'uses');
        $this->assertTrue($hasProductToService, 'geo.json missing product -uses-> service edge');
        $this->assertTrue($hasServiceToProduct, 'geo.json missing service -uses-> product edge');
    }

    public function test_new_manual_relation_edge_reaches_frontend_and_geo_graph(): void
    {
        $this->seedCatalog();

        // 管理员在后台新建一个场景实体，并把核心产品关联到该场景（仅建边，不动 metadata）
        $newScene = $this->entity('service', 'custom-scene-x', '定制场景 X-Ray');
        $epoxy = Entity::where('slug', 'epoxy-primer-100')->firstOrFail();
        $this->relate($epoxy, $newScene, 'uses');
        Catalog::flush();

        // 前台产品详情「适用场景」立即出现新场景
        $this->get('https://example.com/products/epoxy-primer-100')
            ->assertOk()
            ->assertSee('定制场景 X-Ray', false);

        // geo.json 同步出现该边
        $geo = $this->get('https://example.com/geo.json')->assertOk()->json();
        $hasEdge = collect($geo['relations'])->contains(fn ($r) => $r['from'] === 'entity/product/epoxy-primer-100'
            && $r['to'] === 'entity/service/custom-scene-x'
            && $r['relation_type'] === 'uses');
        $this->assertTrue($hasEdge, 'manual edge did not reach geo.json');
    }
}
