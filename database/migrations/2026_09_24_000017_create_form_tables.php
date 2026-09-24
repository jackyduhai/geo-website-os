<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18H-2：产品化表单体系。
 * --------------------------------------------------
 * forms           表单定义（site-scoped）：字段集合之外的行为开关（成功文案、
 *                 consent、蜜罐、通知开关 / 收件人）；
 * form_fields     字段（每 locale 一行，对齐 Content/Entity 翻译模型）：类型 /
 *                 name / label / placeholder / help / required / validation /
 *                 options / sort_order；
 * form_submissions 提交（完整事实）：payload JSON 保存全量业务字段 + 归因；
 *                 Inquiry 由其投影（见 000018）。
 *
 * 安全边界：表单只存结构化数据，HTML 一律由注册渲染器生成并转义；
 * 不存储任意 Blade / HTML / PHP。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------- forms ----------------
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(1)->index();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('title', 160)->nullable();
            $table->string('success_message', 400)->nullable();
            $table->string('status', 16)->default('enabled');   // enabled / disabled
            $table->boolean('consent_required')->default(false);
            $table->boolean('honeypot_enabled')->default(true);
            $table->boolean('notification_enabled')->default(false);
            $table->string('notification_channels', 60)->default('email');
            $table->string('notification_recipients', 255)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });

        // ---------------- form_fields ----------------
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id')->index();
            $table->unsignedBigInteger('site_id')->default(1)->index();
            $table->string('type', 20);
            $table->string('name', 80);
            $table->string('label', 160)->nullable();
            $table->string('placeholder', 160)->nullable();
            $table->string('help_text', 255)->nullable();
            $table->boolean('required')->default(false);
            $table->string('validation', 160)->nullable();
            $table->text('options')->nullable();
            $table->string('locale', 12);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['form_id', 'locale', 'sort_order']);
        });

        // ---------------- form_submissions ----------------
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id')->index();
            $table->unsignedBigInteger('site_id')->default(1)->index();
            $table->string('locale', 12);
            $table->text('payload');                          // 全量业务字段 JSON
            $table->string('source_page', 255)->nullable();
            $table->string('landing_url', 255)->nullable();
            $table->string('referer', 255)->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 96)->nullable();
            $table->string('utm_term', 64)->nullable();
            $table->string('utm_content', 64)->nullable();
            $table->string('device_type', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('status', 16)->default('new');
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index(['site_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};
