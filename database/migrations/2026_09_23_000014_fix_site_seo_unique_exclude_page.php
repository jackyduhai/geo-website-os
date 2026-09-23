<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P-STEP 18G-2a（TD-64 索引层）：修正站点级 SeoMeta 部分唯一索引。
 * --------------------------------------------------
 * 原 sites_seo_meta_unique（add_locale_to_translatables 重建）的谓词仅
 * content_id IS NULL AND entity_id IS NULL，未排除 page_id；page-level
 * SeoMeta（content / entity 为空、page_id 非空）因此被计入站点级唯一槽，
 * 导致同一站点同一语言「站点级 SEO」与任一「页面级 SEO」无法共存（第二行
 * 触发 unique 冲突）。此处补 page_id IS NULL，使四类绑定的唯一索引互斥且
 * 互不重叠，与 findSiteLevelSeo 的 whereNull('page_id') 查询口径一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id, locale) '
            . 'WHERE content_id IS NULL AND entity_id IS NULL AND page_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
        DB::statement(
            'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id, locale) '
            . 'WHERE content_id IS NULL AND entity_id IS NULL'
        );
    }
};
