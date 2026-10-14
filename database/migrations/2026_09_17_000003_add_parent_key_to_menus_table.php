<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自定义菜单支持二级：
 * - parent_id 非空：挂在某个「自定义一级菜单」（menus 自身）下；
 * - parent_key 非空：挂在某个「固定一级栏目」（蓝图 key，如 products/about）下；
 * 两者皆空=一级菜单。仅支持两级，不允许更深层级。
 *
 * 注：全新安装的建表迁移已包含该列，此处对已存在的库做幂等增补。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menus', 'parent_key')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->string('parent_key', 80)->nullable()->after('parent_id');
            });
        }

        // 索引可能已由建表迁移创建，异常兜底保证幂等
        try {
            Schema::table('menus', function (Blueprint $table) {
                $table->index(['parent_key', 'is_active'], 'menus_parent_key_active_index');
            });
        } catch (\Throwable $e) {
            // 索引已存在则跳过
        }
    }

    public function down(): void
    {
        // ⚠️ 索引名有两来源（20G-6 · C-17）：
        //   1. 本迁移 up() 建的 menus_parent_key_active_index
        //   2. 后续迁移 2026_09_18_000005 建的 menus_parent_key_is_active_index
        //      （以及建表迁移 2026_09_14_000005 的默认命名索引）
        // 原实现只删第1 个名字，导致索引残留；而 SQLite 的 DROP COLUMN
        // 在仍有索引引用该列时会直接失败 —— 于是回滚整体报错、不可用。
        // 按「实际存在的索引名」逐个删除，不依赖名字猜测。
        //
        // ⚠️ PostgreSQL：不能对「不存在的索引」发 DROP 再用 try/catch 兜底——
        // PG 语句失败后当前事务进入 aborted（25P02），PHP 即使 catch 了异常，
        // 事务仍无法恢复，后续 hasColumn / dropIndex 全部 25P02 失败。因此必须
        // 先用 getIndexes() 核实索引存在，再 DROP，绝不在 PG 上触发失败语句。
        $existing = array_column(Schema::getIndexes('menus'), 'name');
        foreach ([
            'menus_parent_key_active_index',
            'menus_parent_key_is_active_index',
        ] as $indexName) {
            if (in_array($indexName, $existing, true)) {
                Schema::table('menus', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        if (Schema::hasColumn('menus', 'parent_key')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('parent_key');
            });
        }
    }
};
