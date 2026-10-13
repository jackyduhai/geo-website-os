<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 为 sites.is_default 添加部分唯一约束
 * 保证数据库层面只有一个 default site
 *
 * 可移植性：
 * - SQLite / PostgreSQL 原生支持 partial index（WHERE 子句）。
 * - MySQL 不支持 partial index，改用虚拟生成列（非默认行为 NULL，唯一索引允许多个 NULL）
 *   来等价实现“只有一个默认站点”。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sites ADD COLUMN is_default_guard TINYINT GENERATED ALWAYS AS (IF(is_default = 1, 1, NULL)) VIRTUAL');
            DB::statement('CREATE UNIQUE INDEX sites_is_default_unique ON sites (is_default_guard)');
            return;
        }

        // SQLite / PostgreSQL 支持 partial index：只有 is_default 为真的行才参与唯一约束。
        // PostgreSQL 的 boolean 不能与整数比较（42883），需用 IS TRUE；SQLite 两者皆可。
        $predicate = $driver === 'pgsql' ? 'is_default IS TRUE' : 'is_default = 1';
        DB::statement("CREATE UNIQUE INDEX sites_is_default_unique ON sites(is_default) WHERE {$predicate}");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX sites_is_default_unique ON sites');
            DB::statement('ALTER TABLE sites DROP COLUMN is_default_guard');
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sites_is_default_unique');
    }
};
