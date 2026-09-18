<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客户在线留言/询盘
 * 官网自运营闭环：前台表单直接落库，后台跟进；不依赖后期 GEOFlow。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);                       // 称呼
            $table->string('phone', 30);                      // 联系电话
            $table->string('company', 120)->nullable();       // 公司/门店名称
            $table->string('demand_type', 20)->default('其他'); // 代工/采购/经销/其他
            $table->string('monthly_use', 60)->nullable();    // 预计月用量
            $table->text('message');                          // 需求描述
            $table->string('source_page', 255)->nullable();   // 来源页
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('status', 16)->default('new');     // new 待跟进 / handled 已跟进 / archived 归档
            $table->text('handle_note')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
