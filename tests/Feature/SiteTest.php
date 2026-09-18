<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Site 模型基础测试
 *
 * 覆盖：
 * - 默认站点存在
 * - 创建 Site
 * - slug 唯一约束
 * - metadata JSON cast
 * - status 状态
 * - SQLite FK 已开启
 * - Site 模型不依赖业务数据
 */
class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_site_exists_after_migration(): void
    {
        $default = Site::default();

        $this->assertNotNull($default);
        $this->assertEquals(1, $default->id);
        $this->assertEquals('default', $default->slug);
        $this->assertEquals('active', $default->status);
        $this->assertTrue($default->isDefault());
        $this->assertTrue($default->isActive());
    }

    public function test_can_create_site(): void
    {
        $site = Site::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'domain' => 'test.example.com',
            'description' => 'A test site',
            'status' => 'active',
            'metadata' => ['theme' => 'default'],
        ]);

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'slug' => 'test-company',
            'domain' => 'test.example.com',
        ]);
    }

    public function test_slug_must_be_unique(): void
    {
        Site::create(['name' => 'First', 'slug' => 'unique-slug']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Site::create(['name' => 'Second', 'slug' => 'unique-slug']);
    }

    public function test_metadata_json_cast_works(): void
    {
        $site = Site::create([
            'name' => 'Meta Test',
            'slug' => 'meta-test',
            'metadata' => ['key1' => 'value1', 'nested' => ['a' => 1]],
        ]);

        $fresh = Site::find($site->id);
        $this->assertIsArray($fresh->metadata);
        $this->assertEquals('value1', $fresh->metadata['key1']);
        $this->assertEquals(1, $fresh->metadata['nested']['a']);
    }

    public function test_metadata_null_handled(): void
    {
        $site = Site::create([
            'name' => 'Null Meta',
            'slug' => 'null-meta',
            'metadata' => null,
        ]);

        $fresh = Site::find($site->id);
        $this->assertNull($fresh->metadata);
    }

    public function test_status_enum_values(): void
    {
        $active = Site::create(['name' => 'Active', 'slug' => 'active-site', 'status' => Site::STATUS_ACTIVE]);
        $inactive = Site::create(['name' => 'Inactive', 'slug' => 'inactive-site', 'status' => Site::STATUS_INACTIVE]);
        $maintenance = Site::create(['name' => 'Maint', 'slug' => 'maint-site', 'status' => Site::STATUS_MAINTENANCE]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($inactive->isActive());
        $this->assertFalse($maintenance->isActive());
    }

    public function test_sqlite_foreign_keys_enabled(): void
    {
        $fkEnabled = DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        $this->assertEquals(1, $fkEnabled, 'SQLite foreign_key_constraints must be enabled in config/database.php');
    }

    public function test_site_model_has_no_business_dependency(): void
    {
        // Site 模型不应该 use 或调用业务类
        $reflection = new \ReflectionClass(Site::class);
        $source = file_get_contents($reflection->getFileName());

        // 检查实际 use 语句和静态调用，不检查注释
        $this->assertStringNotContainsString('use App\\Support\\Facts', $source);
        $this->assertStringNotContainsString('use App\\Models\\Content', $source);
        $this->assertStringNotContainsString('Facts::', $source);
        $this->assertStringNotContainsString('demo-tenant-a', strtolower($source));
        $this->assertStringNotContainsString('Demo Tenant A', $source);
        $this->assertStringNotContainsString('Sample City', $source);
        $this->assertStringNotContainsString('Sample Snack', $source);
        $this->assertStringNotContainsString('Sample Marinade', $source);
    }

    public function test_default_id_helper(): void
    {
        $this->assertEquals(1, Site::defaultId());
    }

    public function test_domain_nullable_allows_multiple_nulls(): void
    {
        // SQLite UNIQUE 列允许多个 NULL，这是预期行为
        $site1 = Site::create(['name' => 'No Domain 1', 'slug' => 'no-domain-1', 'domain' => null]);
        $site2 = Site::create(['name' => 'No Domain 2', 'slug' => 'no-domain-2', 'domain' => null]);

        $this->assertNull($site1->domain);
        $this->assertNull($site2->domain);
    }
}
