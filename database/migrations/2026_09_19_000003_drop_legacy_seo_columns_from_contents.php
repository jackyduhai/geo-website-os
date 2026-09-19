<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 删除 contents 表 legacy SEO 字段（P-STEP 07 / P0-B 最终步骤）。
 *
 * 前置契约（geo:upgrade 已保证顺序）：
 *   geo:backfill-seo 已在 drop 之前把 seo_title / seo_desc / canonical /
 *   noindex 全量迁移至 Content-level SeoMeta，且行为对拍一致。
 *
 * 删除后 Resolution 链（对 5.6-A 冻结契约的授权演进，见 ADR）：
 *   Title:       SeoMeta.title → Content.title → Site.name → System
 *   Description: SeoMeta.description → Content.summary → Site.description → System
 *   Noindex:     SeoMeta.noindex → false
 *   OG Image / Canonical 链不变（og_image_id 属正式链，保留）。
 *
 * 回滚：本 migration down() 重建空列（数据恢复依赖升级前备份，见 rollback 契约）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_desc', 'canonical', 'noindex']);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_desc', 180)->nullable();
            $table->string('canonical', 255)->nullable();
            $table->boolean('noindex')->default(false);
        });
    }
};
