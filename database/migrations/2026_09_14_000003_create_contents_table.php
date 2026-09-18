<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 内容表 contents（文章 / 单页 / 产品共用）
 *
 * 核心设计：GEO 四层结构（结论 / 解释 / 证据 / 边界）为独立字段，不塞进正文 HTML。
 * 理由：模板可强制渲染顺序与语义标签，JSON-LD 可准确映射，AI 提取更稳。
 *
 * 治理字段与 SEO 字段同表存放，发布前由门禁校验完整性。
 * 对接字段保证 GEOFlow 推送幂等，不覆盖人工修改。
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL 用 jsonb（可索引、查询快），其他驱动退化为 json
        $json = DB::connection()->getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        Schema::create('contents', function (Blueprint $table) use ($json) {
            $table->id();
            $table->string('type', 20)->default('article');       // article | page | product
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            // v0.9.22 叙事插槽：结构化页面可运营片段以 slot 标识（非独立页面，无 URL）
            $table->string('slot', 120)->nullable()->unique();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('cover_id')->nullable();
            $table->longText('body')->nullable();                 // Markdown 正文
            $table->string('status', 20)->default('draft');       // draft | published | archived
            $table->timestamp('published_at')->nullable();

            // ---------- GEO 四层结构 ----------
            $table->text('geo_conclusion')->nullable();           // 结论：一段话直接答问题
            $table->text('geo_explanation')->nullable();          // 解释：为什么、怎么做
            $table->{$json}('geo_evidence')->nullable();          // 证据：[{label,value,source,url?}] ≥2 条
            $table->text('geo_boundary')->nullable();             // 边界：不适用场景、限制条件
            $table->{$json}('geo_faq')->nullable();               // 问答对：[{q,a}]
            $table->{$json}('geo_key_facts')->nullable();         // 关键事实：[{key,value}]

            // ---------- 治理字段 ----------
            $table->{$json}('fact_refs')->nullable();             // 引用的事实库 key 列表
            $table->string('owner', 60)->nullable();              // 责任人
            $table->date('reviewed_at')->nullable();              // 最后复核日期
            $table->date('review_due')->nullable();               // 下次复核日期
            $table->string('source_note', 255)->nullable();       // 来源说明

            // ---------- SEO ----------
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_desc', 180)->nullable();
            $table->string('canonical', 255)->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable();
            $table->boolean('noindex')->default(false);

            // ---------- GEOFlow 对接 ----------
            $table->string('external_id', 128)->nullable();
            $table->string('external_source', 40)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('content_hash', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'category_id']);
            $table->index(['category_id', 'group_id']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
