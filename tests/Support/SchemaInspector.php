<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * 跨数据库的结构内省工具（测试专用）。
 *
 * 统一封装 SQLite 的 PRAGMA / MySQL 与 PostgreSQL 的 information_schema，
 * 让「列、可空性、外键、索引、外键违约」等结构断言在三种数据库上都能运行，
 * 避免在非 SQLite 上直接执行 `PRAGMA ...` 而语法报错。
 *
 * 返回对象尽量与 PRAGMA 的字段名对齐（name / notnull / from / table / to /
 * on_delete / on_update），以便测试最小改动。
 */
class SchemaInspector
{
    /**
     * 当前连接的驱动名。
     */
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * 表的列信息（对齐 PRAGMA table_info）。
     *
     * @return array<int, object{name:string,type:string,notnull:int,dflt_value:?string}>
     */
    public static function columns(string $table): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return DB::select("PRAGMA table_info($table)");
        }

        $db = DB::getDatabaseName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type,
                        IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$db, $table]
            );
        } else { // pgsql
            $rows = DB::select(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type,
                        IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [self::pgSchema(), $table]
            );
        }

        return array_map(static fn ($r) => (object) [
            'name'       => (string) $r->name,
            'type'       => (string) $r->type,
            'notnull'    => $r->nullable === 'NO' ? 1 : 0,
            'dflt_value' => $r->dflt !== null ? (string) $r->dflt : null,
        ], $rows);
    }

    /**
     * 表的外键（对齐 PRAGMA foreign_key_list）。
     *
     * @return array<int, object{from:string,table:string,to:string,on_delete:string,on_update:string}>
     */
    public static function foreignKeys(string $table): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return DB::select("PRAGMA foreign_key_list($table)");
        }

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT kcu.COLUMN_NAME AS `from`, kcu.REFERENCED_TABLE_NAME AS ref_table,
                        kcu.REFERENCED_COLUMN_NAME AS ref_col,
                        rc.DELETE_RULE AS del, rc.UPDATE_RULE AS upd
                 FROM information_schema.KEY_COLUMN_USAGE kcu
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                   ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                 WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                   AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
                [DB::getDatabaseName(), $table]
            );
        } else { // pgsql
            // PostgreSQL 的 information_schema.KEY_COLUMN_USAGE 没有 REFERENCED_TABLE_NAME
            // （该列是 MySQL 专有），直接查 pg_catalog.pg_constraint（contype='f'）。
            // confdeltype / confupdtype 是动作为单字符代码：
            // a=NO ACTION r=RESTRICT c=CASCADE n=SET NULL d=SET DEFAULT。
            // 本库外键均为单列（site_id / content_id / category_id 等），取 conkey[1]。
            $rows = DB::select(
                "SELECT a.attname AS from,
                        clf.relname AS ref_table,
                        af.attname AS ref_col,
                        con.confdeltype AS del_code,
                        con.confupdtype AS upd_code
                 FROM pg_constraint con
                 JOIN pg_namespace n ON n.oid = con.connamespace
                 JOIN pg_class cl ON cl.oid = con.conrelid
                 JOIN pg_class clf ON clf.oid = con.confrelid
                 JOIN pg_attribute a ON a.attrelid = con.conrelid
                    AND a.attnum = con.conkey[1]
                 JOIN pg_attribute af ON af.attrelid = con.confrelid
                    AND af.attnum = con.confkey[1]
                 WHERE n.nspname = ? AND cl.relname = ? AND con.contype = 'f'
                 ORDER BY con.conname",
                [self::pgSchema(), $table]
            );
        }

        return array_map(static fn ($r) => (object) [
            'from'      => (string) ($r->from ?? $r->{'from'}),
            'table'     => (string) $r->ref_table,
            'to'        => (string) $r->ref_col,
            'on_delete' => isset($r->del_code)
                ? self::pgActionCode($r->del_code)
                : self::normalizeRule($r->del ?? ''),
            'on_update' => isset($r->upd_code)
                ? self::pgActionCode($r->upd_code)
                : self::normalizeRule($r->upd ?? ''),
        ], $rows);
    }

    /**
     * 表的索引（对齐 PRAGMA index_list，仅提供 name）。
     *
     * @return array<int, object{name:string}>
     */
    public static function indexes(string $table): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return DB::select("PRAGMA index_list($table)");
        }

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT DISTINCT INDEX_NAME AS name
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 ORDER BY INDEX_NAME',
                [DB::getDatabaseName(), $table]
            );
        } else { // pgsql
            $rows = DB::select(
                'SELECT indexname AS name FROM pg_indexes
                 WHERE schemaname = ? AND tablename = ?
                 ORDER BY indexname',
                [self::pgSchema(), $table]
            );
        }

        return array_map(static fn ($r) => (object) ['name' => (string) $r->name], $rows);
    }

    /**
     * 索引包含的列（对齐 PRAGMA index_info）。
     *
     * @return array<int, object{name:string}>
     */
    public static function indexColumns(string $table, string $index): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return DB::select('PRAGMA index_info('.self::thisQuote($index).')');
        }

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
                 ORDER BY SEQ_IN_INDEX',
                [DB::getDatabaseName(), $table, $index]
            );
        } else { // pgsql
            $rows = DB::select(
                "SELECT a.attname AS name
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_index i ON i.indexrelid = c.oid
                 JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                 WHERE n.nspname = ? AND c.relname = ?",
                [self::pgSchema(), $index]
            );
        }

        return array_map(static fn ($r) => (object) ['name' => (string) $r->name], $rows);
    }

    /**
     * 外键违约记录（对齐 PRAGMA foreign_key_check）。
     *
     * MySQL / PostgreSQL 因外键约束始终生效，正常情况下不会存在违约行，
     * 故返回空数组；SQLite 读取 PRAGMA。
     *
     * @return array<int, mixed>
     */
    public static function foreignKeyViolations(): array
    {
        if (self::driver() === 'sqlite') {
            return DB::select('PRAGMA foreign_key_check');
        }

        return [];
    }

    /**
     * 外键是否启用（对齐 PRAGMA foreign_keys）。
     */
    public static function foreignKeysEnabled(): bool
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1;
        }

        if ($driver === 'mysql') {
            return (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS v')->v === 1;
        }

        // PostgreSQL：replica 角色会禁用触发器（含外键）
        $role = DB::selectOne('SHOW session_replication_role')->session_replication_role;

        return $role === 'origin';
    }

    /**
     * 表是否存在。
     */
    public static function hasTable(string $table): bool
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            return DB::selectOne(
                "SELECT 1 AS ok FROM sqlite_master WHERE type='table' AND name = ?",
                [$table]
            ) !== null;
        }

        if ($driver === 'mysql') {
            return DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [DB::getDatabaseName(), $table]
            ) !== null;
        }

        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [self::pgSchema(), $table]
        ) !== null;
    }

    /**
     * 表的唯一索引（含 PRIMARY 之外的唯一约束），每个返回其列名数组。
     *
     * @return array<int, array<int, string>>
     */
    public static function uniqueIndexColumnSets(string $table): array
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            $sets = [];
            foreach (DB::select("PRAGMA index_list($table)") as $idx) {
                if ((int) $idx->unique !== 1) {
                    continue;
                }
                $cols = array_map(
                    static fn ($c) => (string) $c->name,
                    DB::select('PRAGMA index_info("'.str_replace('"', '""', $idx->name).'")')
                );
                $sets[] = $cols;
            }

            return $sets;
        }

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT INDEX_NAME AS name, COLUMN_NAME AS col, SEQ_IN_INDEX AS seq
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0
                 ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [DB::getDatabaseName(), $table]
            );
        } else { // pgsql
            $rows = DB::select(
                "SELECT c.relname AS name, a.attname AS col, array_position(i.indkey, a.attnum) AS seq
                 FROM pg_class t
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 JOIN pg_index i ON i.indrelid = t.oid
                 JOIN pg_class c ON c.oid = i.indexrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey)
                 WHERE n.nspname = ? AND t.relname = ? AND i.indisunique = true
                 ORDER BY c.relname, seq",
                [self::pgSchema(), $table]
            );
        }

        $groups = [];
        foreach ($rows as $r) {
            $groups[(string) $r->name][] = (string) $r->col;
        }

        return array_values($groups);
    }

    /**
     * 当前 PostgreSQL schema（默认 public）。
     */
    private static function pgSchema(): string
    {
        return 'public';
    }

    /**
     * PostgreSQL pg_constraint 外键动作单字符代码 → PRAGMA 风格大写短词。
     */
    private static function pgActionCode(string $code): string
    {
        return match ($code) {
            'a' => 'NO ACTION',
            'r' => 'RESTRICT',
            'c' => 'CASCADE',
            'n' => 'SET NULL',
            'd' => 'SET DEFAULT',
            default => strtoupper($code),
        };
    }

    /**
     * information_schema 引用动作为大写（NO ACTION / CASCADE / SET NULL / RESTRICT），
     * 统一为 PRAGMA 风格的大写短词。
     */
    private static function normalizeRule(?string $rule): string
    {
        $rule = strtoupper(trim((string) $rule));

        return match ($rule) {
            'NO ACTION' => 'NO ACTION',
            default     => $rule,
        };
    }

    /**
     * SQLite 标识符安全引用（仅用于 PRAGMA 参数）。
     */
    private static function thisQuote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
