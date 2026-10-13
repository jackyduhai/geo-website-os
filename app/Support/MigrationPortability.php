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
 *     pgsql  : SET session_replication_role = replica/origin（需超级用户）
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

    /** @return string[] */
    private static function fkToggleStatements(bool $enable): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => ['PRAGMA foreign_keys = ' . ($enable ? 'ON' : 'OFF')],
            'mysql'  => ['SET FOREIGN_KEY_CHECKS = ' . ($enable ? '1' : '0')],
            'pgsql'  => ['SET session_replication_role = ' . ($enable ? "'origin'" : "'replica'")],
            default  => [],
        };
    }
}
