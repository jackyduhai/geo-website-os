<?php

namespace Tests\Feature\Admin;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Models\User;
use App\Services\Geo\EntityCoverageService;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18S Capability 3：Entity Coverage Check（实体知识资产齐备度只读聚合）。
 *
 * 唯一架构条件：应覆盖项唯一来自 EntityCapabilityRegistry（config/entities.php 的
 * required/recommended 声明）。本测试验证：
 *  (a) Registry 扩展向后兼容（旧字符串写法归一为 recommended，不意外升 required）；
 *  (b) Coverage 服务不拥有业务规则（必备集合 == Registry 派生集合）；
 *  (c) HTTP：admin 覆盖页 200、数据渲染；前台关键页无回归。
 * 另含 Discovery Test Matrix：Blank N/A、单实体齐备/缺失、逐类型、多站隔离、AuthZ、只读。
 */
class EntityCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        EntityCapabilityRegistry::flush();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    private function useSite(Site $site): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    private function makeEntity(Site $site, string $type, string $slug, array $attrs = []): Entity
    {
        return Entity::create(array_merge([
            'site_id' => $site->id,
            'type' => $type,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'summary' => "Summary for {$slug}",
            'description' => "Desc for {$slug}",
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'translation_group' => (string) \Illuminate\Support\Str::uuid(),
            'metadata' => [],
            'sort_order' => 0,
        ], $attrs));
    }

    private function edge(Entity $from, Entity $to, string $type): void
    {
        EntityRelation::create([
            'site_id' => $from->site_id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $type,
        ]);
    }

    // ---- (a) Registry 向后兼容 ----

    public function test_registry_legacy_string_relations_default_to_recommended(): void
    {
        // 旧扁平字符串写法（如 organization 的 person/location）归一为 recommended（required=false）。
        $rels = EntityCapabilityRegistry::relationRequirements('organization');
        $byTarget = collect($rels)->keyBy('type');
        // person/location 为旧字符串写法 → 默认 recommended（required=false）。
        $this->assertFalse($byTarget['person']['required']);
        $this->assertFalse($byTarget['location']['required']);
        // product 在 config 中显式标 required=true。
        $this->assertTrue($byTarget['product']['required']);
    }

    public function test_registry_required_sets_are_traceable(): void
    {
        $this->assertSame(['service'], EntityCapabilityRegistry::requiredRelations('product'));
        $this->assertSame(['product'], EntityCapabilityRegistry::requiredRelations('service'));
        $this->assertSame(['product'], EntityCapabilityRegistry::requiredRelations('organization'));
        $this->assertSame(['product'], EntityCapabilityRegistry::requiredRelations('case_study'));
        $this->assertSame(['product'], EntityCapabilityRegistry::requiredRelations('download_asset'));

        $meta = EntityCapabilityRegistry::requiredMetadataKeys('case_study');
        sort($meta);
        $this->assertSame(['challenge', 'result', 'solution'], $meta);
        $this->assertSame(['media_id'], EntityCapabilityRegistry::requiredMetadataKeys('download_asset'));
        // product 的 metadata 全为建议 → 必备键为空（不意外产生必备字段缺口）。
        $this->assertSame([], EntityCapabilityRegistry::requiredMetadataKeys('product'));
    }

    // ---- (b) Coverage 服务不拥有规则：必备集合 == Registry 派生集合 ----

    public function test_coverage_required_equals_registry_derived(): void
    {
        $fresh = Site::create(['name' => 'Cov', 'slug' => 'cov-site', 'domain' => 'cov.test', 'status' => 'active', 'is_default' => false]);
        $this->useSite($fresh);

        $org = $this->makeEntity($fresh, Entity::TYPE_ORGANIZATION, 'acme');
        $product = $this->makeEntity($fresh, Entity::TYPE_PRODUCT, 'widget');
        $service = $this->makeEntity($fresh, Entity::TYPE_SERVICE, 'scene');

        // 完整闭环：org produces→product；product uses→service；service uses→product。
        $this->edge($org, $product, EntityRelation::TYPE_PRODUCES);
        $this->edge($product, $service, EntityRelation::TYPE_USES);
        $this->edge($service, $product, EntityRelation::TYPE_USES);

        $report = SiteContext::withSite($fresh, fn () => app(EntityCoverageService::class)->report());

        foreach ($report['entities'] as $row) {
            // 每个实体应覆盖关系数 = Registry requiredRelations 数（服务未另造规则）。
            $expectedRels = count(EntityCapabilityRegistry::requiredRelations($row['type']));
            $expectedMeta = count(EntityCapabilityRegistry::requiredMetadataKeys($row['type']));
            $this->assertSame($expectedRels + $expectedMeta, $row['required'],
                "{$row['name']} required 数应等于 Registry 派生集合大小");
            $this->assertSame($row['required'], $row['covered'], "{$row['name']} 已闭环应齐备");
            $this->assertSame([], $row['gaps']);
        }
        $this->assertSame('PASS', $report['overall']);
    }

    // ---- 单实体缺失被检出 + 去编辑链接 ----

    public function test_single_entity_missing_required_relation_is_listed(): void
    {
        $fresh = Site::create(['name' => 'Gap', 'slug' => 'gap-site', 'domain' => 'gap.test', 'status' => 'active', 'is_default' => false]);
        $this->useSite($fresh);

        $product = $this->makeEntity($fresh, Entity::TYPE_PRODUCT, 'lonely-product'); // 缺 uses→service
        $this->makeEntity($fresh, Entity::TYPE_SERVICE, 'some-service');            // 另建一个 service 但不连边

        $report = SiteContext::withSite($fresh, fn () => app(EntityCoverageService::class)->report());
        $row = collect($report['entities'])->firstWhere('slug', 'lonely-product');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['required']);
        $this->assertSame(0, $row['covered']);
        $this->assertNotEmpty($row['gaps']);
        $this->assertSame('relation', $row['gaps'][0]['kind']);
        $this->assertStringContainsString('服务/场景', $row['gaps'][0]['label']);
        $this->assertSame(route('admin.entities.edit', ['entity' => $product->id]), $row['edit_url']);
        $this->assertSame('WARNING', $report['overall']);
    }

    public function test_case_study_missing_required_metadata_listed(): void
    {
        $fresh = Site::create(['name' => 'CS', 'slug' => 'cs-site', 'domain' => 'cs.test', 'status' => 'active', 'is_default' => false]);
        $this->useSite($fresh);

        // case_study：缺 product 关系 + 缺 challenge/solution/result → 两类缺口都列出。
        $this->makeEntity($fresh, Entity::TYPE_CASE_STUDY, 'weak-case');

        $report = SiteContext::withSite($fresh, fn () => app(EntityCoverageService::class)->report());
        $row = collect($report['entities'])->firstWhere('slug', 'weak-case');

        $this->assertNotNull($row);
        // required = 1(relation product) + 3(meta) = 4
        $this->assertSame(4, $row['required']);
        $gapLabels = array_column($row['gaps'], 'label');
        $this->assertNotEmpty(array_filter($gapLabels, fn ($l) => str_contains($l, 'challenge')));
        $this->assertNotEmpty(array_filter($gapLabels, fn ($l) => str_contains($l, 'solution')));
        $this->assertNotEmpty(array_filter($gapLabels, fn ($l) => str_contains($l, 'result')));
    }

    // ---- Blank N/A ----

    public function test_blank_site_is_na(): void
    {
        $fresh = Site::create(['name' => 'Blank', 'slug' => 'cov-blank', 'domain' => 'covblank.test', 'status' => 'active', 'is_default' => false]);
        $this->useSite($fresh);

        $page = $this->actingAs($this->super)->get(route('admin.geo.coverage'))->assertOk();
        $report = SiteContext::withSite($fresh, fn () => app(EntityCoverageService::class)->report());

        $this->assertTrue($report['blank']);
        $this->assertSame('N/A', $report['overall']);
        $page->assertSee('实体知识覆盖');
        $page->assertSee('空站');
    }

    // ---- Demo 真值（随 DB 变化，不 hardcode 数量）----

    public function test_seeded_demo_site_renders(): void
    {
        $this->useSite($this->default);
        $report = SiteContext::withSite($this->default, fn () => app(EntityCoverageService::class)->report());

        $this->assertFalse($report['blank']);
        $this->assertContains($report['overall'], ['PASS', 'WARNING', 'N/A']);
        $this->assertIsInt($report['totals']['entities']);
        $this->actingAs($this->super)->get(route('admin.geo.coverage'))->assertOk()->assertSee('实体覆盖');
    }

    // ---- 多站隔离 ----

    public function test_site_isolation(): void
    {
        $a = Site::create(['name' => 'A', 'slug' => 'cov-a', 'domain' => 'cova.test', 'status' => 'active', 'is_default' => false]);
        $b = Site::create(['name' => 'B', 'slug' => 'cov-b', 'domain' => 'covb.test', 'status' => 'active', 'is_default' => false]);

        $this->useSite($a);
        $this->makeEntity($a, Entity::TYPE_PRODUCT, 'a-product');

        $this->useSite($b);
        $report = SiteContext::withSite($b, fn () => app(EntityCoverageService::class)->report());
        $this->assertTrue($report['blank'], 'site B must not count site A entity');
    }

    // ---- AuthZ ----

    public function test_guest_is_redirected(): void
    {
        $this->get(route('admin.geo.coverage'))->assertRedirect(route('admin.login'));
    }

    // ---- 只读 ----

    public function test_rendering_is_read_only(): void
    {
        $this->useSite($this->default);
        $beforeEntities = Entity::withoutSiteScope()->count();
        $beforeRelations = EntityRelation::count();

        $this->actingAs($this->super)->get(route('admin.geo.coverage'))->assertOk();

        $this->assertSame($beforeEntities, Entity::withoutSiteScope()->count());
        $this->assertSame($beforeRelations, EntityRelation::count());
    }

    // ---- 前台关键页无回归（HTTP）----

    public function test_frontend_pages_still_render_without_regression(): void
    {
        $this->useSite($this->default);
        $this->get('/')->assertOk();
        $this->get('/products/')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/geo.json')->assertOk();
    }
}
