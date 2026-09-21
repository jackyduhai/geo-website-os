<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 17B：Content 类型收敛 —— 退役 Content(type=product)。
 *
 * 背景：产品 / 服务 / 组织等「目录资源」统一由 Entity（实体）承载，并由前台
 * Entity Catalog（/products/{slug}、/solutions/{slug}…）渲染。历史上后台 Content
 * 也允许 type=product，形成「后台可发布产品内容、前台却按 Entity 取数 → 404，
 * 且 sitemap / llms / geo / 搜索仍输出这些 URL」的双轨缺陷（见 16A 报告 §6 与
 * admin-management-completion-design.md §5 方案 A）。
 *
 * 本迁移仅做幂等数据归位：把既有的 contents.type='product' 归并为 'article'，
 * 使其作为普通文章继续可访问 / 可编辑，不再伪装成目录产品。不新增 contents.entity_id、
 * 不猜测 Content 与 Entity 的关系（Content 与 Entity 为平行资源，见 5.6-C 冻结契约）。
 *
 * 注意：这是不可逆的数据语义归并（无法在 down 时区分哪些 article 原属 product），
 * 故 down() 刻意留空；类型列本身保留，不删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents') || ! Schema::hasColumn('contents', 'type')) {
            return;
        }

        DB::table('contents')->where('type', 'product')->update(['type' => 'article']);
    }

    public function down(): void
    {
        // 故意留空：product → article 为不可逆的语义归并。
    }
};
