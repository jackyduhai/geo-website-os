<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：/geo.json 图谱契约。
 *
 * AI 读到的是 Company | CaseStudy | Product | Industry 图（而非 Article|keywords）：
 *   - case_study 作为图谱节点收录（geo=true），2a 无 url（路由 2b 才建）；
 *   - download_asset 作为 Product knowledge extension 收录（geo=true），
 *     但 public=false → 节点无 url，不形成独立公开页；
 *   - 跨类型关系边：case_study--related_to-->product、case_study--related_to-->organization、
 *     product--offers-->download_asset；
 *   - geo=false / 未注册类型被 Registry 过滤，不进图谱。
 */
class EntityGeoGraphContractTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['name' => 'Test Site', 'domain' => 'example.com']);
        SiteContext::setSite($this->site);
    }

    private function graph(): array
    {
        return $this->get('/geo.json')->assertOk()->json();
    }

    private function makeEntity(string $type, string $slug, string $name, array $metadata = []): Entity
    {
        return Entity::create([
            'site_id'  => $this->site->id,
            'type'     => $type,
            'slug'     => $slug,
            'name'     => $name,
            'status'   => 'published',
            'metadata' => $metadata,
        ]);
    }

    public function test_case_study_and_download_asset_nodes_are_in_graph(): void
    {
        $this->makeEntity(Entity::TYPE_CASE_STUDY, 'acme-case', 'Acme Case', ['industry' => 'automotive']);
        $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'datasheet-x1', 'X1 Datasheet', ['media_id' => 42]);

        $graph = $this->graph();

        $types = array_column($graph['entities'], 'type');
        $this->assertContains(Entity::TYPE_CASE_STUDY, $types, 'case_study（geo=true）必须进入 /geo.json');
        $this->assertContains(Entity::TYPE_DOWNLOAD_ASSET, $types, 'download_asset（geo=true）必须进入 /geo.json');
    }

    public function test_download_asset_node_has_no_public_url(): void
    {
        $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'datasheet-x2', 'X2 Datasheet', ['media_id' => 7]);

        $graph = $this->graph();

        $node = collect($graph['entities'])->firstWhere('type', Entity::TYPE_DOWNLOAD_ASSET);
        $this->assertNotNull($node);
        // public=false → PublicUrl 返回 null → 节点不得输出 url（不形成独立公开页）
        $this->assertArrayNotHasKey('url', $node, 'download_asset 无公开页，节点不得带 url');
    }

    public function test_case_study_node_has_public_url_in_2b(): void
    {
        $this->makeEntity(Entity::TYPE_CASE_STUDY, 'beta-case', 'Beta Case');

        $graph = $this->graph();

        $node = collect($graph['entities'])->firstWhere('type', Entity::TYPE_CASE_STUDY);
        $this->assertNotNull($node);
        // 18R-2b：/cases 路由已建，case_study public=true → 节点带 url（指向真实可访问详情页）
        $this->assertArrayHasKey('url', $node, '2b case_study 应有 /cases/{slug} url');
        $this->assertStringContainsString('/cases/beta-case', $node['url']);
    }

    public function test_cross_type_relation_edges_in_graph(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'x1-coater', 'X1 Coater');
        $case = $this->makeEntity(Entity::TYPE_CASE_STUDY, 'gamma-case', 'Gamma Case', ['industry' => 'medical']);
        $org = $this->makeEntity(Entity::TYPE_ORGANIZATION, 'gamma-customer', 'Gamma Customer');
        $asset = $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'x1-datasheet', 'X1 Datasheet', ['media_id' => 1]);

        // CaseStudy --related_to--> Product（方向：案例引用产品）
        EntityRelation::create([
            'site_id'        => $this->site->id,
            'from_entity_id' => $case->id,
            'to_entity_id'   => $product->id,
            'relation_type'  => EntityRelation::TYPE_RELATED_TO,
        ]);
        // CaseStudy --related_to--> Organization（客户公司，metadata.role=customer）
        EntityRelation::create([
            'site_id'        => $this->site->id,
            'from_entity_id' => $case->id,
            'to_entity_id'   => $org->id,
            'relation_type'  => EntityRelation::TYPE_RELATED_TO,
            'metadata'       => ['role' => 'customer'],
        ]);
        // Product --offers--> DownloadAsset（方向：产品提供资料，不是反向）
        EntityRelation::create([
            'site_id'        => $this->site->id,
            'from_entity_id' => $product->id,
            'to_entity_id'   => $asset->id,
            'relation_type'  => EntityRelation::TYPE_OFFERS,
        ]);

        $graph = $this->graph();

        $edges = collect($graph['relations']);

        // case_study → product
        $this->assertNotNull(
            $edges->firstWhere(fn ($r) => $r['from'] === 'entity/case_study/gamma-case'
                && $r['to'] === 'entity/product/x1-coater'
                && $r['relation_type'] === 'related_to'),
            'CaseStudy--related_to-->Product 边必须出现在图谱'
        );

        // case_study → organization（客户）
        $this->assertNotNull(
            $edges->firstWhere(fn ($r) => $r['from'] === 'entity/case_study/gamma-case'
                && $r['to'] === 'entity/organization/gamma-customer'),
            'CaseStudy--related_to-->Organization（客户）边必须出现在图谱'
        );

        // product → download_asset（方向正确：product offers asset，不是 asset references product）
        $this->assertNotNull(
            $edges->firstWhere(fn ($r) => $r['from'] === 'entity/product/x1-coater'
                && $r['to'] === 'entity/download_asset/x1-datasheet'
                && $r['relation_type'] === 'offers'),
            'Product--offers-->DownloadAsset 边必须出现在图谱，方向为 product→asset'
        );
    }

    public function test_unregistered_geo_false_type_is_excluded_from_graph(): void
    {
        // Gate B：未注册类型 fail-closed，isGeo() 默认 false → 不进图谱。
        $this->makeEntity('bogus_unregistered_type', 'ghost-node', 'Ghost Node');

        $graph = $this->graph();

        $slugs = array_column($graph['entities'], 'slug');
        $this->assertNotContains('ghost-node', $slugs, '未注册 / geo=false 类型不得进入 /geo.json');
    }
}
