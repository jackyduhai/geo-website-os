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
            $this->downSqliteSlot();
        }

        $this->forgetCaches();
    }

/**
     * SQLite 回滚：删掉 slot 列，**保留其余全部约束**。
     *
     * ⚠️ 两种写法都会失败（20G-8-B 实测，两种都踩过）：
     *
     * ① `$table->dropUnique(['slot'])` ——
     *    `up()` 的 `->unique()` 在 SQLite 下建出的是**表级**约束
     *    （`sqlite_autoindex_contents_N`，`PRAGMA index_list` 的 origin=u），
     *    按列名 DROP 找不到，报错并中断回滚链。
     *
     * ② `$table->dropColumn('slot')` ——
     *    SQLite 的 DROP COLUMN 走表重建路径，而 Laravel **不复制其他唯一约束**：
     *    实测重建后只剩 `UNIQUE(site_id, slot)`，把本迁移 up 之前的
     *    `UNIQUE(site_id, slug, locale)` 直接弄丢（locale / translation_group
     *    是 18F 引入的，不能连带丢失）。
     *
     * 故显式重建表：完整写出列定义 + 全部唯一约束 + 外键 + 索引。
     * 禁用 CTAS（`CREATE TABLE ... AS SELECT`），它会丢 PK / AUTOINCREMENT /
     * UNIQUE / FK / DEFAULT —— 20G-6 · C-16 的教训。
     *
     * 注意：locale / translation_group 保留（本迁移 up 之前它们已存在，
     * 由更早的迁移引入，不是本迁移该删的东西）。
     */
    private function downSqliteSlot(): void
    {
        /**
         * 按**实际存在的列**重建，只删slot。
         *
         * ⚠️ 不能硬编码列清单（20G-8-B 实测踩坑）：
         * 逐步回滚到本迁移时，表处于**中间态** —— 09_23_000001 的 down 已经把
         * locale / translation_group 删掉、site_id 也尚未加入，
         * 此时 contents 只有 36 列。硬编码 `site_id` / `locale` / `UNIQUE(site_id,
         * slug, locale)` 会直接报「no such column: site_id」→ 回滚链中断。
         *
         * 故：读 PRAGMA table_info拿到真实列 → 排除 slot → 重建 →
         * 约束按实际存在的列推导（只保留所有列都在的复合唯一）。
         */
        $cols = [];
        foreach (DB::select('PRAGMA table_info(contents)') as $c) {
            $cols[] = $c;
        }
        $keep = array_values(array_filter($cols, fn ($c) => $c->name !== 'slot'));

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('contents_noslot');

        // 按实际列生成 DDL（保留类型 / NOT NULL / DEFAULT）
        $defs = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
        $names = [];
        foreach ($keep as $c) {
            if ($c->name === 'id') {
                continue;
            }
            $def = '`'.$c->name.'` '.$c->type;
            if ((int) $c->notnull === 1) {
                $def .= ' NOT NULL';
            }
            if ($c->dflt_value !== null) {
                $def .= ' DEFAULT '.$c->dflt_value;
            }
            $defs[] = $def;
            $names[] = $c->name;
        }

        $has = array_flip($names);

        // 复合唯一：只保留所有列都存在的（中间态下 site_id 可能已不在）
        $uniques = [];
        if (isset($has['site_id'], $has['slug'], $has['locale'])) {
            $uniques[] = 'UNIQUE(site_id, slug, locale)';
        } elseif (isset($has['slug'])) {
            $uniques[] = 'UNIQUE(slug)';
        }
        if (isset($has['site_id'], $has['slot'])) {
            $uniques[] = 'UNIQUE(site_id, slot)';
        }
        $defs = array_merge($defs, $uniques);
        if (isset($has['site_id'])) {
            $defs[] = 'FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT';
        }

        DB::statement('CREATE TABLE contents_noslot ('.implode(', ', $defs).')');

        $colList = implode(', ', array_map(fn ($n) => '`'.$n.'`', $names));
        DB::statement('INSERT INTO contents_noslot ('.$colList.') SELECT '.$colList.' FROM contents');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_noslot RENAME TO contents');

        // 重建索引（仅索引仍存在的列）
        $idx = [
            'contents_category_id_group_id_index' => ['category_id', 'group_id'],
            'contents_type_category_id_index' => ['type', 'category_id'],
            'contents_status_published_at_index' => ['status', 'published_at'],
            'contents_site_id_index' => ['site_id'],
            'contents_external_id_index' => ['external_id'],
            'contents_translation_group_index' => ['translation_group'],
        ];
        foreach ($idx as $name => $colsOf) {
            $ok = true;
            foreach ($colsOf as $n) {
                if (! isset($has[$n])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                DB::statement('CREATE INDEX '.$name.' ON contents('.implode(', ', $colsOf).')');
            }
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function forgetCaches(): void
    {
        foreach (['nav.tree', 'main.menu', 'main.menu.blueprint', 'footer.extra'] as $k) {
            Cache::forget($k);
        }
    }
};
