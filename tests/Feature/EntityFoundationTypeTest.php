<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：新类型落地的零迁移 / 零新表 / 零垃圾契约。
 *
 * 证明 case_study / download_asset 作为纯 type 字符串值落地：
 *   - Entity 模型常量存在；
 *   - entities.type 列无 DB CHECK 约束（新 type 值可直接插入）；
 *   - 不新建任何表；
 *   - Fresh install 后 entities 表无 case_study / download_asset 垃圾行。
 */
class EntityFoundationTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_new_type_constants_exist(): void
    {
        $this->assertSame('case_study', Entity::TYPE_CASE_STUDY);
        $this->assertSame('download_asset', Entity::TYPE_DOWNLOAD_ASSET);
    }

    public function test_entities_type_column_accepts_new_types_without_check_constraint(): void
    {
        // type 是 varchar(32) 无 DB CHECK：直接插入两个新类型值应成功并回读。
        $site = Site::where('is_default', true)->first();

        $case = Entity::create([
            'site_id' => $site->id,
            'type'    => Entity::TYPE_CASE_STUDY,
            'slug'    => 'acme-case',
            'name'    => 'Acme Case Study',
            'status'  => 'published',
        ]);

        $asset = Entity::create([
            'site_id' => $site->id,
            'type'    => Entity::TYPE_DOWNLOAD_ASSET,
            'slug'    => 'datasheet-pdf',
            'name'    => 'Product Datasheet',
            'status'  => 'published',
        ]);

        $this->assertDatabaseHas('entities', [
            'id'   => $case->id,
            'type' => Entity::TYPE_CASE_STUDY,
        ]);
        $this->assertDatabaseHas('entities', [
            'id'   => $asset->id,
            'type' => Entity::TYPE_DOWNLOAD_ASSET,
        ]);
    }

    public function test_no_new_tables_for_new_entity_types(): void
    {
        // 2a 不新建任何表：entities / entity_relations 仍是这两张。
        $this->assertTrue(Schema::hasTable('entities'));
        $this->assertTrue(Schema::hasTable('entity_relations'));
        $this->assertFalse(Schema::hasTable('case_studies'));
        $this->assertFalse(Schema::hasTable('download_assets'));
    }

    public function test_fresh_install_has_no_case_study_or_download_asset_rows(): void
    {
        // RefreshDatabase 即 fresh install 基线：不播种 case_study / download_asset。
        $this->assertSame(0, Entity::whereIn('type', [
            Entity::TYPE_CASE_STUDY,
            Entity::TYPE_DOWNLOAD_ASSET,
        ])->count());
    }

    public function test_entity_matrix_declared_in_registry(): void
    {
        // Gate F：SDK/AI Agent 权威依据——8 类型能力矩阵以 Registry 为唯一事实源。
        $expected = [
            // type => [public, searchable, geo, sitemap, schema]
            'organization'    => [false, false, true, false, 'Organization'],
            'product'         => [true, true, true, true, 'Product'],
            'service'         => [true, true, true, true, 'Service'],
            'person'          => [false, false, true, false, 'Person'],
            'location'        => [false, false, true, false, 'Place'],
            'topic'           => [false, false, true, false, 'WebPage'],
            'case_study'      => [true, true, true, true, 'CaseStudy'],
            'download_asset'  => [false, false, true, false, null],
        ];

        foreach ($expected as $type => [$public, $searchable, $geo, $sitemap, $schema]) {
            $this->assertSame($public, \App\Support\Entities\EntityCapabilityRegistry::isPublic($type), "{$type} public");
            $this->assertSame($searchable, \App\Support\Entities\EntityCapabilityRegistry::isSearchable($type), "{$type} searchable");
            $this->assertSame($geo, \App\Support\Entities\EntityCapabilityRegistry::isGeo($type), "{$type} geo");
            $this->assertSame($sitemap, \App\Support\Entities\EntityCapabilityRegistry::isSitemap($type), "{$type} sitemap");
            $this->assertSame($schema, \App\Support\Entities\EntityCapabilityRegistry::schemaType($type), "{$type} schema");
        }
    }
}
