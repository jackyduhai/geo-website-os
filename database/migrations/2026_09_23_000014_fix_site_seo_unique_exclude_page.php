<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-2a（TD-64 索引层）：修正站点级 SeoMeta 部分唯一索引。
 * --------------------------------------------------
 * 原 sites_seo_meta_unique 的谓词仅 content_id IS NULL AND entity_id IS NULL，
 * 未排除 page_id；page-level SeoMeta（content/entity 为空、page_id 非空）因此被
 * 计入站点级唯一槽，导致同一站点同一语言「站点级 SEO」与任一「页面级 SEO」无法
 * 共存（第二行触发 unique 冲突）。此处补 page_id IS NULL，使四类绑定的唯一索引
 * 互斥且互不重叠，与 findSiteLevelSeo 的 whereNull('page_id') 查询口径一致。
 *
 * MySQL：调整 site_guard 生成列表达式，补 page_id IS NULL 条件。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX sites_seo_meta_unique ON seo_metas');
            DB::statement('ALTER TABLE seo_metas DROP COLUMN site_guard');
            DB::statement("ALTER TABLE seo_metas ADD COLUMN site_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NULL AND entity_id IS NULL AND page_id IS NULL,
                   CONCAT(site_id, '|', locale), NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_guard)');
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id, locale) '
            . 'WHERE content_id IS NULL AND entity_id IS NULL AND page_id IS NULL'
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX sites_seo_meta_unique ON seo_metas');
            DB::statement('ALTER TABLE seo_metas DROP COLUMN site_guard');
            DB::statement("ALTER TABLE seo_metas ADD COLUMN site_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NULL AND entity_id IS NULL,
                   CONCAT(site_id, '|', locale), NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_guard)');
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id, locale) '
            . 'WHERE content_id IS NULL AND entity_id IS NULL'
        );
    }
};
