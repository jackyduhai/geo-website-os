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
        // 因此这里按「实际存在的索引名」逐个删除，不依赖名字猜测。
        foreach ([
            'menus_parent_key_active_index',
            'menus_parent_key_is_active_index',
        ] as $indexName) {
            try {
                Schema::table('menus', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (\Throwable $e) {
                // 索引不存在（未创建或已被后续迁移改名）→ 跳过
            }
        }

        if (Schema::hasColumn('menus', 'parent_key')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('parent_key');
            });
        }
    }
};
