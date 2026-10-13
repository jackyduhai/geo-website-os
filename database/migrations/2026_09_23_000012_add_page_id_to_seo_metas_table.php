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
 *
 * MySQL 不支持 partial index，用虚拟生成列（page_id 为 NULL 时守卫列也为 NULL）等价实现。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('seo_metas', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable();
            $table->index('page_id', 'seo_metas_page_id_index');
        });

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE seo_metas ADD COLUMN page_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(page_id IS NOT NULL, CONCAT(site_id, '|', page_id, '|', locale), NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX page_seo_meta_unique ON seo_metas(page_guard)');
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX page_seo_meta_unique ON seo_metas(site_id, page_id, locale) '
                . 'WHERE page_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX page_seo_meta_unique ON seo_metas');
            if (Schema::hasColumn('seo_metas', 'page_guard')) {
                DB::statement('ALTER TABLE seo_metas DROP COLUMN page_guard');
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS page_seo_meta_unique');
        }

        Schema::table('seo_metas', function (Blueprint $table) {
            $table->dropIndex('seo_metas_page_id_index');
            $table->dropColumn('page_id');
        });
    }
};
