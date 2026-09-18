<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.22 · 叙事插槽（narrative slot）
 * ------------------------------------------------------------------
 * 1. contents 增加 slot 列：结构化页面（产品/场景/工厂/合作/关于/联系）里
 *    可运营的「叙事段落」（hero 导语、企业简介正文）以 slot 片段形式复用
 *    contents 表存储，后台「页面文案」维护；slot 行不是独立页面，不产生
 *    URL、不进搜索/sitemap/feed/列表，Content 模型以 not_slot 全局作用域排除。
 * 2. 清理双轨残留：软删 10 篇薄占位文章（公司/工厂/历程/资质/联系 +
 *    5 篇产品系列占位），它们不驱动规范页、不进 sitemap，仅直接输 URL 可达；
 *    3 篇真实知识文章保留。
 * 3. 停用被静态路由遮蔽的旧影子栏目（关于/产品/联系的 DB 栏目树），
 *    消除可直达的空/薄栏目页；知识中心、新闻动态栏目保留。
 */
return new class extends Migration
{
    /** 双轨薄占位文章 slug（5 篇公司类 + 5 篇产品系列类） */
    private array $retireContentSlugs = [
        'company-profile', 'factory-capacity', 'development-history',
        'certifications', 'contact-us',
        'chinese-marinade-series', 'western-marinade-series',
        'special-marinade-series', 'coating-powder-series',
        'prepared-chicken-series',
    ];

    /** 被静态路由遮蔽、仅承载占位文置的旧栏目 slug（知识/新闻保留） */
    private array $retireCategorySlugs = [
        'about', 'company', 'factory', 'certification', 'history',
        'products', 'chinese-marinade', 'western-marinade', 'special-marinade',
        'coating-powder', 'prepared-chicken', 'contact',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('contents', 'slot')) {
            Schema::table('contents', function (Blueprint $table) {
                // slot 行使用保留 slug（_slot_ 前缀），slug 列仍非空、唯一
                $table->string('slot', 120)->nullable()->unique()->after('slug');
            });
        }

        // 软删双轨占位文章（可恢复）
        DB::table('contents')
            ->whereIn('slug', $this->retireContentSlugs)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        // 停用旧影子栏目（不删除，可逆）
        DB::table('categories')
            ->whereIn('slug', $this->retireCategorySlugs)
            ->update(['is_active' => false, 'is_nav' => false]);

        $this->forgetCaches();
    }

    public function down(): void
    {
        DB::table('categories')
            ->whereIn('slug', $this->retireCategorySlugs)
            ->update(['is_active' => true, 'is_nav' => true]);

        DB::table('contents')
            ->whereIn('slug', $this->retireContentSlugs)
            ->update(['deleted_at' => null]);

        if (Schema::hasColumn('contents', 'slot')) {
            Schema::table('contents', function (Blueprint $table) {
                $table->dropUnique(['slot']);
                $table->dropColumn('slot');
            });
        }

        $this->forgetCaches();
    }

    private function forgetCaches(): void
    {
        foreach (['nav.tree', 'main.menu', 'main.menu.blueprint', 'footer.extra'] as $k) {
            Cache::forget($k);
        }
    }
};
