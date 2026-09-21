<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补列：事实库表单/控制器契约中的 unit（单位）与 source_url（来源链接）。
 *
 * 背景：后台「事实库」新建/编辑表单（fact-form）与 FactController 早已暴露
 * unit、source_url 两个字段，但 facts 建表迁移从未创建这两列，导致在后台
 * 新增任何事实都会因 "table facts has no column named unit" 而 500（UAT Bug#6）。
 * 本迁移只补齐既有 UI / 控制器契约所需的可空列，不改动任何历史迁移（保留升级链）。
 *
 * 说明：本次仅让字段可持久化、消除阻断；事实库在新架构中已降级为安装期
 * Example 种子来源，是否在前台/LLM 输出中消费 unit / source_url 属于后续
 * 产品决策，不在本迁移内实现。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facts', function (Blueprint $table) {
            if (! Schema::hasColumn('facts', 'unit')) {
                $table->string('unit', 20)->nullable()->after('value');
            }
            if (! Schema::hasColumn('facts', 'source_url')) {
                $table->string('source_url', 255)->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facts', function (Blueprint $table) {
            if (Schema::hasColumn('facts', 'source_url')) {
                $table->dropColumn('source_url');
            }
            if (Schema::hasColumn('facts', 'unit')) {
                $table->dropColumn('unit');
            }
        });
    }
};
