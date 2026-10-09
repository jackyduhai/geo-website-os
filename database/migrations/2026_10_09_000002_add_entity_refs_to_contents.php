<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-3 Entity References + ContentGate：
 *  - 新增 contents.entity_refs（JSON，nullable）：内容正文里显式声明的语义引用
 *    （「本内容谈论这些实体」），与 fact_refs 同形态，供 ContentGate 做生命周期感知校验。
 *  - 与 content_entity（结构引用，产生 Schema about / GEO 边）严格分离：
 *    entity_refs 是语义引用，Entity purge 时保留，由门禁识别悬空语义引用。
 *  - 不做 NLP / 字符串扫描；不据 WARNING 改变 Content 发布状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL 用 jsonb，其他驱动退化为 json（与建表迁移一致）。
        $json = DB::connection()->getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        Schema::table('contents', function (Blueprint $table) use ($json) {
            $table->{$json}('entity_refs')->nullable()->after('fact_refs');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('entity_refs');
        });
    }
};
