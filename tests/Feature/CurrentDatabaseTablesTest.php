<?php

namespace Tests\Feature;

use App\Support\Database\CurrentDatabaseTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 跨库回归：当前库表清单的收敛口径。
 *
 * 背景（见交付报告 §3.8）：Laravel 的 `Schema::getTableListing()` 在
 * SQLite / PostgreSQL 上只返回当前库（schema）的表，在 **MySQL** 上
 * `MySqlGrammar::compileTables(null)` **不加 schema 过滤**，返回的是
 * **同一实例内所有数据库**的表，元素形如 `geo_os_nv_d.contents`，
 * 同名表按库重复出现。
 *
 * 旧代码靠 `Str::afterLast($listed, '.')` 取裸名兜底，功能没错，但：
 * 1. MySQL 上「逐表扫描」要重复扫 N 倍；
 * 2. 带库名前缀的字符串是**跨库误删的引信** —— 一旦被直接喂给
 *    `DB::table(...)`，MySQL 会当作合法的跨库限定名去动别的数据库。
 *
 * 本测试把口径钉死：清单里的每个名字都是**裸表名**、**唯一**、
 * 且**只解析到当前连接的库**。
 */
class CurrentDatabaseTablesTest extends TestCase
{
    use RefreshDatabase;

    // §3.8 · 清单里不得出现 schema 限定名（MySQL 上旧实现会给出 "db.table"）
    public function test_table_names_contain_no_schema_qualifier(): void
    {
        foreach (CurrentDatabaseTables::names() as $name) {
            $this->assertStringNotContainsString(
                '.',
                $name,
                "表名不得带 schema 限定名：{$name}（跨库限定名是误删别的数据库的引信）"
            );
        }
    }

    // §3.8 · 清单必须去重（MySQL 上同名表按库重复出现）
    public function test_table_names_are_unique(): void
    {
        $names = CurrentDatabaseTables::names();

        $this->assertSame(
            count($names),
            count(array_unique($names)),
            '表清单存在重复项'
        );
        $this->assertSame($names, array_values(array_unique($names)));
    }

    // §3.8 · 清单不得遗漏任何一张真实存在的表
    public function test_table_names_cover_every_table(): void
    {
        $expected = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $expected[$name] = true;
            }
        }

        $this->assertSame(
            array_keys($expected),
            CurrentDatabaseTables::names(),
            '表清单与驱动返回的裸表名集合不一致'
        );
    }

    // §3.8 · siteScoped() 只收「当前库里确实有 site_id 列」的表
    public function test_site_scoped_tables_all_have_site_id_column(): void
    {
        $scoped = CurrentDatabaseTables::siteScoped();

        $this->assertNotEmpty($scoped, '站点私有表清单不应为空');

        foreach ($scoped as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'site_id'),
                "表 {$table} 被判为站点私有表，却没有 site_id 列"
            );
            // 表名必须能在当前连接上直接查询（不含跨库限定名）
            $this->assertIsInt(
                DB::table($table)->count(),
                "表 {$table} 无法在当前连接上查询"
            );
        }
    }

    // §3.8 · 三库都必须能识别出这批核心站点私有表（口径一致）
    public function test_core_site_scoped_tables_are_recognized(): void
    {
        $scoped = CurrentDatabaseTables::siteScoped();

        foreach (['contents', 'facts', 'entities', 'media', 'settings'] as $table) {
            $this->assertContains(
                $table,
                $scoped,
                "核心站点私有表 {$table} 未被识别为站点私有表"
            );
        }
    }

    // §3.8 · sites 表没有 site_id 列（每行一个站点，id 即站点 id），
    // 因此不应出现在「站点私有表」清单里
    public function test_sites_table_is_not_in_site_scoped_list(): void
    {
        $this->assertFalse(
            Schema::hasColumn('sites', 'site_id'),
            'sites 表不应有 site_id 列'
        );
        $this->assertNotContains(
            'sites',
            CurrentDatabaseTables::siteScoped(),
            'sites 表没有 site_id 列，不应出现在站点私有表清单里'
        );
    }
}
