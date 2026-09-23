<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-1：seo_metas 增加 page_id，支持组合页面级 SEO（SeoMetaResolver::resolvePage）。
 * --------------------------------------------------
 * 新增部分唯一索引：(site_id, page_id, locale) WHERE page_id IS NOT NULL，
 * 与现有 site / content / entity 三类 partial unique 并列，互不重叠。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metas', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable();
            $table->index('page_id', 'seo_metas_page_id_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX page_seo_meta_unique ON seo_metas(site_id, page_id, locale) '
            . 'WHERE page_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS page_seo_meta_unique');

        Schema::table('seo_metas', function (Blueprint $table) {
            $table->dropIndex('seo_metas_page_id_index');
            $table->dropColumn('page_id');
        });
    }
};
