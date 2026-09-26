<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitySchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_entities_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('entities'));
    }

    public function test_entity_relations_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('entity_relations'));
    }

    public function test_entities_has_required_columns(): void
    {
        $columns = [
            'id', 'site_id', 'type', 'slug', 'name', 'summary',
            'description', 'status', 'metadata', 'sort_order',
            'published_at', 'created_at', 'updated_at'
        ];

        foreach ($columns as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('entities', $col),
                "Column {$col} missing from entities"
            );
        }
    }

    public function test_entity_relations_has_required_columns(): void
    {
        $columns = [
            'id', 'site_id', 'from_entity_id', 'to_entity_id',
            'relation_type', 'metadata', 'sort_order',
            'created_at', 'updated_at'
        ];

        foreach ($columns as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('entity_relations', $col),
                "Column {$col} missing from entity_relations"
            );
        }
    }

    public function test_entity_unique_site_type_slug(): void
    {
        $site = Site::where('is_default', true)->first();

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Test Product',
            'status' => 'draft',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-product',
            'name' => 'Duplicate Product',
            'status' => 'draft',
        ]);
    }

    public function test_same_slug_different_types_allowed(): void
    {
        $site = Site::where('is_default', true)->first();

        Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test',
            'name' => 'Test Product',
            'status' => 'draft',
        ]);

        Entity::create([
            'site_id' => $site->id,
            'type' => 'service',
            'slug' => 'test',
            'name' => 'Test Service',
            'status' => 'draft',
        ]);

        $this->assertTrue(true);
    }

    public function test_same_slug_different_sites_allowed(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        Entity::create([
            'site_id' => $siteA->id,
            'type' => 'product',
            'slug' => 'test',
            'name' => 'Product A',
            'status' => 'draft',
        ]);

        Entity::create([
            'site_id' => $siteB->id,
            'type' => 'product',
            'slug' => 'test',
            'name' => 'Product B',
            'status' => 'draft',
        ]);

        $this->assertTrue(true);
    }

    public function test_entity_relation_unique(): void
    {
        $site = Site::where('is_default', true)->first();

        $from = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'org',
            'name' => 'Org',
            'status' => 'published',
        ]);

        $to = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'prod',
            'name' => 'Prod',
            'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => 'produces',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => 'produces',
        ]);
    }

    public function test_cross_site_relation_rejected(): void
    {
        $siteA = Site::where('is_default', true)->first();
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $orgA = Entity::create([
            'site_id' => $siteA->id,
            'type' => 'organization',
            'slug' => 'org-a',
            'name' => 'Org A',
            'status' => 'published',
        ]);

        $prodB = Entity::create([
            'site_id' => $siteB->id,
            'type' => 'product',
            'slug' => 'prod-b',
            'name' => 'Prod B',
            'status' => 'published',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-site violation');

        EntityRelation::create([
            'site_id' => $siteA->id,
            'from_entity_id' => $orgA->id,
            'to_entity_id' => $prodB->id,
            'relation_type' => 'produces',
        ]);
    }

    public function test_invalid_site_id_rejected_by_fk(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Entity::create([
            'site_id' => 99999,
            'type' => 'product',
            'slug' => 'invalid',
            'name' => 'Invalid',
            'status' => 'draft',
        ]);
    }

    public function test_foreign_key_check_has_no_violations(): void
    {
        $violations = \DB::select('PRAGMA foreign_key_check');
        $this->assertEmpty($violations);
    }

    public function test_entity_has_site_relation(): void
    {
        $site = Site::where('is_default', true)->first();

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'test-rel',
            'name' => 'Test',
            'status' => 'draft',
        ]);

        $this->assertInstanceOf(Site::class, $entity->site);
        $this->assertEquals($site->id, $entity->site->id);
    }

    public function test_entity_relation_has_from_and_to(): void
    {
        $site = Site::where('is_default', true)->first();

        $from = Entity::create([
            'site_id' => $site->id,
            'type' => 'organization',
            'slug' => 'from-rel',
            'name' => 'From',
            'status' => 'published',
        ]);

        $to = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'to-rel',
            'name' => 'To',
            'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => 'produces',
        ]);

        $this->assertInstanceOf(Entity::class, $relation->fromEntity);
        $this->assertInstanceOf(Entity::class, $relation->toEntity);
        $this->assertEquals($from->id, $relation->fromEntity->id);
        $this->assertEquals($to->id, $relation->toEntity->id);
    }

    public function test_entity_metadata_json_cast(): void
    {
        $site = Site::where('is_default', true)->first();

        $entity = Entity::create([
            'site_id' => $site->id,
            'type' => 'product',
            'slug' => 'meta-test',
            'name' => 'Meta Test',
            'status' => 'draft',
            'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($entity->metadata);
        $this->assertEquals('value', $entity->metadata['key']);
    }

    public function test_entity_types_are_frozen(): void
    {
        // 类型白名单与 EntityCapabilityRegistry 双向绑定：新增类型必须同时在此登记
        // 预期、并在 config/entities.php 注册。断言两者一致——不硬编码数量，未来新增
        // 类型只改 Registry（并同步此白名单），不再写死 6/8。
        $allowed = [
            'organization', 'product', 'service', 'person', 'location', 'topic',
            'case_study', 'download_asset',
        ];
        $this->assertEquals($allowed, EntityCapabilityRegistry::types());
        $this->assertSame(count($allowed), count(EntityCapabilityRegistry::types()));
    }

    public function test_no_from_type_in_entity_relations(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('entity_relations', 'from_type')
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('entity_relations', 'to_type')
        );
    }
}
