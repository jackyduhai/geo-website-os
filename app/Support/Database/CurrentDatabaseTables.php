<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Schema;

/**
 * 当前数据库的表清单（裸表名，去重）。
 *
 * 为什么不能直接用 Schema::getTableListing()：
 * - SQLite：`pragma_table_list` → 当前库（main / temp）内的表，形如 `settings`；
 * - PostgreSQL：`pg_class` + `compileSchemaWhereClause(null)` → 当前 schema 的表，
 *   形如 `settings`；
 * - MySQL：Laravel 的 `MySqlGrammar::compileTables(null)` **不加 schema 过滤**，
 *   返回的是**同一实例内所有数据库**的表，形如 `geo_os_nv_d.contents`、
 *   `geo_os_drill.contents`，同名表重复出现多次。
 *
 * 后果有两层：
 * 1. 「逐表扫描」（站点删除保护、预览站回收）在 MySQL 上要重复扫 N 倍；
 * 2. 元素里的库名前缀是**跨库误删的引信** —— 只要有人直接把这个字符串喂给
 *    `DB::table(...)`，MySQL 会把它当作合法的跨库限定名，去操作**别的数据库**。
 *
 * 这里统一收敛为「当前库的裸表名 + 去重」：调用方拿到的名字可以直接喂给
 * DB::table()，三个数据库上的语义完全一致，且不可能指向别的库。
 *
 * 去重规则说明：MySQL 上若当前库缺少某张表而别的库有，去重后仍会保留该表名，
 * 但 Schema::hasColumn() 只查当前连接的库，返回 false，调用方据此跳过 → 安全。
 */
final class CurrentDatabaseTables
{
    /**
     * 当前库的裸表名（去重、按字典序排序）。
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (Schema::getTables() as $table) {
            // getTables() 每项都带独立的 'name'（裸表名）字段，
            // 不依赖 schema_qualified_name 的字符串拆分。
            $name = $table['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * 当前库里「带 site_id 列」的裸表名（去重、已排序）。
     *
     * 站点私有表的通用判定口径：站点删除保护与预览站回收共用此口径，
     * 将来新增业务表无需改动这两处。
     *
     * @return list<string>
     */
    public static function siteScoped(): array
    {
        return array_values(array_filter(
            self::names(),
            static fn (string $name): bool => Schema::hasColumn($name, 'site_id')
        ));
    }
}
