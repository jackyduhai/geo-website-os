<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-8 Fact Re-review（事实复核状态权威）：
 *  - Entity physical purge 后，在 owning site / business-subject 粒度产生一条
 *    复核 marker，表示「该 scope 的站点事实需要人工重新核定」。
 *  - marker 是「当前复核状态」的唯一权威（可查询该 scope 是否存在未决复核）；
 *    AuditLog 只记录一次性事件，不用于反推当前状态。
 *  - 没有 fact_entity_refs，系统不声称能自动定位受影响的具体 Fact——
 *    故当前信号统一落在 site 粒度（scope='site'，scope_key=NULL）。
 *  - marker 不删除 / 不改写 / 不抑制 Fact 行，仅承载复核治理状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_review_markers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('scope', 20)->default('site'); // site | business_subject
            $table->string('scope_key', 80)->nullable();  // business_subject 时为 group / subject key
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('triggered_by_entity_id')->nullable();
            $table->string('status', 16)->default('open'); // open | resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // 当前状态查询入口（按站点 + 状态）。
            $table->index(['site_id', 'status'], 'fact_review_markers_site_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_review_markers');
    }
};
