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
        try {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropIndex('menus_parent_key_active_index');
            });
        } catch (\Throwable $e) {
            // 忽略不存在的索引
        }
        if (Schema::hasColumn('menus', 'parent_key')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('parent_key');
            });
        }
    }
};
