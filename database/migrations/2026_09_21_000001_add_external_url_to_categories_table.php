<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补列：栏目「外链跳转」类型所需的 external_url。
 *
 * 背景：栏目新建/编辑表单与 CategoryController 早已暴露 type=external 与
 * external_url 字段，但建表迁移遗漏了该列，导致保存任何栏目都会因
 * "table categories has no column named external_url" 而 500。
 * 本迁移只补齐既有 UI / 控制器契约所需的列，不改动任何历史迁移（保留升级链）。
 *
 * 说明：本次仅让该字段可持久化、消除阻断；前台导航对外链栏目的跳转接线属于
 * 独立的产品完整性缺口，另行跟踪，不在本迁移内实现。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'external_url')) {
                $table->string('external_url', 255)->nullable()->after('icon');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'external_url')) {
                $table->dropColumn('external_url');
            }
        });
    }
};
