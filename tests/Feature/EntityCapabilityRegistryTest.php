<?php

namespace Tests\Feature;

use App\Support\Entities\EntityCapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：EntityCapabilityRegistry 单元契约。
 *
 * Registry 是实体类型能力的唯一事实源（config/entities.php）。本测试锁定：
 *   - 8 种类型全部注册；
 *   - case_study / download_asset 两个新类型的 capability 字段精确值；
 *   - 各访问器方法语义；
 *   - 未知类型 fail-closed（Gate B：第三方扩展安全前提）。
 */
class EntityCapabilityRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        EntityCapabilityRegistry::flush();
    }

    public function test_registry_loads_eight_types(): void
    {
        $types = EntityCapabilityRegistry::types();

        $this->assertCount(8, $types);
        foreach ([
            'organization', 'product', 'service', 'person', 'location', 'topic',
            'case_study', 'download_asset',
        ] as $expected) {
            $this->assertContains($expected, $types, "缺少实体类型 {$expected}");
        }
    }

    public function test_labels_map_for_admin_tabs(): void
    {
        $labels = EntityCapabilityRegistry::labels();

        $this->assertArrayHasKey('case_study', $labels);
        $this->assertSame('客户案例', $labels['case_study']);
        $this->assertSame('下载资料', $labels['download_asset']);
        $this->assertSame('产品', $labels['product']);
    }

    public function test_case_study_capabilities(): void
    {
        $this->assertTrue(EntityCapabilityRegistry::has('case_study'));
        $this->assertSame('CaseStudy', EntityCapabilityRegistry::schemaType('case_study'));
        $this->assertTrue(EntityCapabilityRegistry::isPublic('case_study'));
        $this->assertTrue(EntityCapabilityRegistry::isSearchable('case_study'));
        $this->assertTrue(EntityCapabilityRegistry::isGeo('case_study'));
        $this->assertTrue(EntityCapabilityRegistry::isSitemap('case_study'));

        $meta = EntityCapabilityRegistry::metadataKeys('case_study');
        foreach (['industry', 'scenario', 'challenge', 'solution', 'result'] as $k) {
            $this->assertContains($k, $meta);
        }

        $relations = EntityCapabilityRegistry::relations('case_study');
        $this->assertContains('product', $relations);
        $this->assertContains('organization', $relations);
        $this->assertContains('scenario', $relations);
    }

    public function test_download_asset_capabilities_geo_true_but_public_false(): void
    {
        $this->assertTrue(EntityCapabilityRegistry::has('download_asset'));

        // schema=null → 不产出 JSON-LD
        $this->assertNull(EntityCapabilityRegistry::schemaType('download_asset'));
        // geo=true → 进入 Product knowledge graph / AI context（不是公开页）
        $this->assertTrue(EntityCapabilityRegistry::isGeo('download_asset'));
        // public/searchable/sitemap=false → 不生成 /downloads/{slug}、不进搜索、不进 sitemap
        $this->assertFalse(EntityCapabilityRegistry::isPublic('download_asset'));
        $this->assertFalse(EntityCapabilityRegistry::isSearchable('download_asset'));
        $this->assertFalse(EntityCapabilityRegistry::isSitemap('download_asset'));

        $meta = EntityCapabilityRegistry::metadataKeys('download_asset');
        $this->assertEqualsCanonicalizing(
            ['media_id', 'type', 'language', 'version'],
            $meta
        );

        $relations = EntityCapabilityRegistry::relations('download_asset');
        $this->assertContains('product', $relations);
        $this->assertContains('media', $relations);
    }

    public function test_existing_six_types_regression(): void
    {
        $matrix = [
            'organization' => ['Organization', false, false, true, false],
            'product'      => ['Product', true, true, true, true],
            'service'      => ['Service', true, true, true, true],
            'person'       => ['Person', false, false, true, false],
            'location'     => ['Place', false, false, true, false],
            'topic'        => ['WebPage', false, false, true, false],
        ];

        foreach ($matrix as $type => [$schema, $public, $searchable, $geo, $sitemap]) {
            $this->assertSame($schema, EntityCapabilityRegistry::schemaType($type), "{$type} schema");
            $this->assertSame($public, EntityCapabilityRegistry::isPublic($type), "{$type} public");
            $this->assertSame($searchable, EntityCapabilityRegistry::isSearchable($type), "{$type} searchable");
            $this->assertSame($geo, EntityCapabilityRegistry::isGeo($type), "{$type} geo");
            $this->assertSame($sitemap, EntityCapabilityRegistry::isSitemap($type), "{$type} sitemap");
        }
    }

    /**
     * Gate B：未知/未注册类型必须 fail closed——能力查询默认全部 false/null/空数组，
     * 绝不能默认 public/search/schema=true（第三方扩展安全前提）。
     */
    public function test_unknown_type_fails_closed(): void
    {
        $unknown = 'totally_unknown_entity_type';

        $this->assertFalse(EntityCapabilityRegistry::has($unknown));
        $this->assertNull(EntityCapabilityRegistry::get($unknown));
        $this->assertNull(EntityCapabilityRegistry::schemaType($unknown));
        $this->assertFalse(EntityCapabilityRegistry::isPublic($unknown));
        $this->assertFalse(EntityCapabilityRegistry::isSearchable($unknown));
        $this->assertFalse(EntityCapabilityRegistry::isGeo($unknown));
        $this->assertFalse(EntityCapabilityRegistry::isSitemap($unknown));
        $this->assertSame([], EntityCapabilityRegistry::metadataKeys($unknown));
        $this->assertSame([], EntityCapabilityRegistry::relations($unknown));
        // label 回退返回原文，但不影响能力裁决
        $this->assertSame($unknown, EntityCapabilityRegistry::label($unknown));
    }

    public function test_flush_reloads_config(): void
    {
        $this->assertCount(8, EntityCapabilityRegistry::types());
        EntityCapabilityRegistry::flush();
        $this->assertCount(8, EntityCapabilityRegistry::types());
    }
}
