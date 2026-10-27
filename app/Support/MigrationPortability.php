<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 迁移可移植性辅助：封装跨数据库（MySQL / PostgreSQL / SQLite）的会话级操作。
 *
 * 迁移层此前直接使用 SQLite 的 PRAGMA 语句（foreign_keys / foreign_key_check），
 * 在 MySQL / PostgreSQL 上会语法失败。本类把这些会话操作按驱动分发：
 *
 * - 外键检查开关：
 *     sqlite : PRAGMA foreign_keys = OFF/ON
 *     mysql  : SET FOREIGN_KEY_CHECKS = 0/1
 *     pgsql  : 无会话级开关（no-op）。SET session_replication_role 需要超级用户
 *              （PGC_SUSET，普通应用账户报 42501），且并不能解除「被引用的表
 *              不能被 drop」（2BP01）——该依赖由引用方 FK 约束本身强制。
 *              PostgreSQL 上的正确做法是 dropReferencingForeignKeys() /
 *              restoreReferencingForeignKeys()，table rebuild 迁移已在使用。
 * - 外键违规核查：sqlite 用 PRAGMA foreign_key_check；其余引擎在重建后由
 *     外键约束本身保证，且数据复制保留原 ID，故返回空（无法用单一内置语句等价）。
 */
class MigrationPortability
{
    /** 关闭当前连接的外键检查（仅用于 table rebuild 会话）。 */
    public static function disableForeignKeyChecks(): void
    {
        foreach (static::fkToggleStatements(false) as $statement) {
            DB::statement($statement);
        }
    }

    /** 恢复当前连接的外键检查。 */
    public static function enableForeignKeyChecks(): void
    {
        foreach (static::fkToggleStatements(true) as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * 外键违规核查。
     *
     * @return array 非空数组表示存在违规（元素为引擎返回的违规行）
     */
    public static function foreignKeyViolations(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return DB::select('PRAGMA foreign_key_check');
        }

        // MySQL / PostgreSQL 没有等价的单语句全库外键核查；table rebuild 保留
        // 原始主键与引用，且新 site_id 指向已存在的默认站点，不产生孤儿。
        return [];
    }

    /**
     * 按当前驱动引用标识符（列名 / 表名）。
     *
     * MySQL 用反引号，PostgreSQL / SQLite 用双引号。迁移中遇到 `limit` / `key` /
     * `group` / `groups` 等保留字时必须用此方法，不能写死 MySQL 反引号（在
     * PostgreSQL 上会 42601 语法错误）。
     */
    public static function quote(string $identifier): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * 删除所有引用目标表的外键（仅 PostgreSQL 需要）。
     *
     * MySQL 在 FOREIGN_KEY_CHECKS=0 下可直接 drop 表，FK 定义在重建后自动恢复；
     * SQLite 在 foreign_keys=OFF 下同理。PostgreSQL 的 session_replication_role=
     * replica 只禁用触发器，仍不允许 drop 被依赖的表（2BP01）。因此本方法查询
     * pg_catalog 中引用目标表的 FK，删除并返回其定义，供重建后恢复。
     *
     * @return array<int, array<string, mixed>> FK 定义（非 pgsql 恒为空）
     */
    public static function dropReferencingForeignKeys(string $targetTable): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $rows = DB::select(
            "SELECT
                con.conname AS fk_name,
                rel.relname AS from_table,
                att.attname AS from_column,
                frel.relname AS to_table,
                fatt.attname AS to_column,
                con.confdeltype AS delete_type,
                con.confupdtype AS update_type
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_class frel ON frel.oid = con.confrelid
            JOIN pg_namespace nsp ON nsp.oid = con.connamespace
            JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
            JOIN pg_attribute fatt ON fatt.attrelid = con.confrelid AND fatt.attnum = ANY(con.confkey)
            WHERE con.contype = 'f'
                AND nsp.nspname = ANY (current_schemas(false))
                AND frel.relname = ?
            ORDER BY rel.relname, con.conname, att.attnum",
            [$targetTable]
        );

        $fks = [];
        foreach ($rows as $row) {
            if (! isset($fks[$row->fk_name])) {
                $fks[$row->fk_name] = [
                    'name' => $row->fk_name,
                    'from_table' => $row->from_table,
                    'columns' => [],
                    'to_table' => $row->to_table,
                    'ref_columns' => [],
                    'on_delete' => static::pgsqlFkAction($row->delete_type),
                    'on_update' => static::pgsqlFkAction($row->update_type),
                ];
            }
            $fks[$row->fk_name]['columns'][] = $row->from_column;
            $fks[$row->fk_name]['ref_columns'][] = $row->to_column;
        }

        foreach ($fks as $fk) {
            $fromTable = static::quote($fk['from_table']);
            $constraint = static::quote($fk['name']);
            DB::statement("ALTER TABLE {$fromTable} DROP CONSTRAINT {$constraint}");
        }

        return array_values($fks);
    }

    /**
     * 恢复 {@see dropReferencingForeignKeys()} 删除的外键。
     *
     * @param array<int, array<string, mixed>> $fks
     */
    public static function restoreReferencingForeignKeys(array $fks): void
    {
        foreach ($fks as $fk) {
            $fromTable = static::quote($fk['from_table']);
            $toTable = static::quote($fk['to_table']);
            $constraint = static::quote($fk['name']);
            $cols = implode(', ', array_map([static::class, 'quote'], $fk['columns']));
            $refCols = implode(', ', array_map([static::class, 'quote'], $fk['ref_columns']));
            $onDelete = $fk['on_delete'] !== null ? " ON DELETE {$fk['on_delete']}" : '';
            $onUpdate = $fk['on_update'] !== null ? " ON UPDATE {$fk['on_update']}" : '';
            DB::statement("ALTER TABLE {$fromTable} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$cols}) REFERENCES {$toTable} ({$refCols}){$onDelete}{$onUpdate}");
        }
    }

    /** pg_constraint 外键动作码 → SQL 动作（NO ACTION 返回 null）。 */
    private static function pgsqlFkAction(string $code): ?string
    {
        return match ($code) {
            'r' => 'RESTRICT',
            'c' => 'CASCADE',
            'n' => 'SET NULL',
            'd' => 'SET DEFAULT',
            default => null, // 'a' = NO ACTION
        };
    }

    /**
     * 重建后把自增序列推进到当前最大 ID（仅 PostgreSQL 需要）。
     *
     * table rebuild 用 INSERT SELECT 保留了显式 ID，但 PostgreSQL 的序列不会自动
     * 跟进，后续插入会从 1 开始并与已存在的 ID 冲突（23505）。MySQL 的
     * AUTO_INCREMENT 会自动对齐，无需处理。
     */
    public static function resetAutoIncrement(string $table, string $column = 'id'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $quotedTable = static::quote($table);
        $quotedColumn = static::quote($column);

        DB::statement("SELECT setval(
            pg_get_serial_sequence('{$table}', '{$column}'),
            (SELECT COALESCE(MAX({$quotedColumn}), 1) FROM {$quotedTable}),
            (SELECT MAX({$quotedColumn}) IS NOT NULL FROM {$quotedTable})
        )");
    }

    /**
     * 删除一个唯一对象（约束 或 唯一索引），并按是否存在处理。
     *
     * PostgreSQL 上 Laravel 的 `$table->unique(...)` 建「唯一约束」，但裸
     * `CREATE UNIQUE INDEX` 建「独立唯一索引」；二者同名时，DROP CONSTRAINT 对
     * 独立索引失败（42704），DROP INDEX 对约束失败（2BP01）。本方法查
     * pg_constraint 判定真实类型后分别删除；对象不存在时为 no-op，避免在 PG
     * 上触发失败语句把事务置为 aborted。
     *
     * MySQL / SQLite 无约束/索引之分，统一用 dropUnique（SQLite 内部委托 DROP INDEX）。
     */
    public static function dropUniqueObjectIfExists(string $table, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $isConstraint = DB::select(
                "SELECT 1 FROM pg_constraint con
                 JOIN pg_class c ON c.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = con.connamespace
                 WHERE con.conname = ? AND c.relname = ?
                   AND n.nspname = ANY(current_schemas(false))",
                [$indexName, $table]
            );

            if ($isConstraint) {
                \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($indexName) {
                    $t->dropUnique($indexName);
                });
            } else {
                // 仅当同名「独立索引」存在时才 DROP（pg_index 关联索引关系）。
                $indexExists = DB::select(
                    "SELECT 1
                     FROM pg_index x
                     JOIN pg_class c ON c.oid = x.indexrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE c.relname = ?
                       AND n.nspname = ANY(current_schemas(false))",
                    [$indexName]
                );
                if ($indexExists) {
                    \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($indexName) {
                        $t->dropIndex($indexName);
                    });
                }
            }
        } else {
            // MySQL / SQLite：只在索引存在时删除（避免对不存在对象发语句）。
            $existing = array_column(\Illuminate\Support\Facades\Schema::getIndexes($table), 'name');
            if (in_array($indexName, $existing, true)) {
                \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($indexName) {
                    $t->dropUnique($indexName);
                });
            }
        }
    }

    /**
     * 确保存在以指定列为最左前缀的索引（支撑外键与按列过滤）。
     *
     * MySQL / PostgreSQL 的外键会自动建立可用索引，SQLite 不会为外键建索引。
     * 本方法按驱动检查是否已有以该列为最左前缀的索引，仅在缺失时才显式创建，
     * 从而避免在 MySQL / PostgreSQL 上产生重复索引。
     */
    public static function ensureColumnIndexed(string $table, string $column, string $indexName): void
    {
        if (in_array($column, static::leftmostIndexedColumns($table), true)) {
            return;
        }

        \Illuminate\Support\Facades\Schema::table(
            $table,
            fn (\Illuminate\Database\Schema\Blueprint $t) => $t->index($column, $indexName)
        );
    }

    /**
     * 返回该表中作为「某个索引最左列」的列名集合。
     *
     * @return array<int, string>
     */
    public static function leftmostIndexedColumns(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $columns = [];
            foreach (DB::select('PRAGMA index_list(' . static::quote($table) . ')') as $index) {
                $cols = DB::select('PRAGMA index_info(' . static::quote($index->name) . ')');
                if (isset($cols[0]->name)) {
                    $columns[] = $cols[0]->name;
                }
            }

            return $columns;
        }

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT COLUMN_NAME AS col FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1',
                [$table]
            );

            return array_map(static fn ($r) => $r->col, $rows);
        }

        // pgsql
        $rows = DB::select(
            "SELECT a.attname AS col
             FROM pg_index x
             JOIN pg_class i ON i.oid = x.indexrelid
             JOIN pg_class t ON t.oid = x.indrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.indkey[0]
             WHERE n.nspname = ANY (current_schemas(false)) AND t.relname = ?",
            [$table]
        );

        return array_map(static fn ($r) => $r->col, $rows);
    }

    /** @return string[] */
    private static function fkToggleStatements(bool $enable): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => ['PRAGMA foreign_keys = ' . ($enable ? 'ON' : 'OFF')],
            'mysql'  => ['SET FOREIGN_KEY_CHECKS = ' . ($enable ? '1' : '0')],
            // PostgreSQL: there is no session-level FK toggle.
            //   SET session_replication_role is PGC_SUSET, so an ordinary
            //   application role gets 42501 "permission denied to set
            //   parameter" (it is not grantable via GRANT SET ON PARAMETER).
            //   And even with superuser it does NOT help here: dropping a
            //   table that other tables reference fails with 2BP01 because the
            //   dependency comes from the referencing FK constraint itself,
            //   not from the trigger that session_replication_role disables.
            //   The correct PostgreSQL mechanism is
            //   dropReferencingForeignKeys() / restoreReferencingForeignKeys(),
            //   which every table-rebuild migration already uses.
            'pgsql'  => [],
            default  => [],
        };
    }
}
