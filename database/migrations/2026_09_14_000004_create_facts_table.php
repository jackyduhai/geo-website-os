<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事实库 facts（单一事实源）
 *
 * 来源：业务事实母稿（Demo 数据，经核定后入库）。
 * 作用：
 *   1. 内容编辑时可引用（contents.fact_refs），页面渲染时校验口径一致；
 *   2. llms.txt 的「主体信息」节、JSON-LD 的 Organization 节点均由本表生成；
 *   3. 后台按 review_due 提醒复核，避免事实过期。
 *
 * 纪律：任何页面不得写出与 is_public=1 的记录相冲突的数字。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();          // 如 FACT-COMPANY-003
            $table->string('label', 80);                  // 如「成立时间」
            $table->text('value');                        // 如「2017 年 3 月」
            $table->string('group', 40)->nullable();      // company | product | business | trust | service
            $table->string('source', 160)->nullable();    // 依据，如「营业执照」「诊断报告」
            $table->string('owner', 60)->nullable();      // 责任人
            $table->date('reviewed_at')->nullable();      // 核定日期
            $table->date('review_due')->nullable();       // 下次复核日期
            $table->boolean('is_public')->default(true);  // 是否可用于对外页面
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['group', 'sort']);
            $table->index('review_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts');
    }
};
