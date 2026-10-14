<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P-STEP 18F：给「可翻译」资源表加 locale + translation_group。
 * ------------------------------------------------------------------
 * 翻译模型 = 同表多行：同一逻辑资源以 translation_group(uuid) 关联，每行一种
 * locale，拥有独立 slug / 翻译字段 / SEO；共享字段（status / type / 治理 / 对接 /
 * Entity.metadata）由默认语言（zh-CN）权威行单向同步。
 *
 * 唯一约束加入 locale：
 *   entities (site_id,type,slug)        → (site_id,type,slug,locale)
 *   contents slug 全局唯一              → (site_id,slug,locale)
 *   seo_metas 三类 partial unique       → 索引列加入 locale
 *
 * SQLite 下经历史 dropColumn 重建后无法 DROP INDEX 表级约束，故 contents 采用
 * 「建新表 → 拷数据 → 改名」重建；MySQL / PostgreSQL 可直接 ALTER + 索引增删。
 */
return new class extends Migration
{
    /** contents 重建后的列（不含 id）=> SQLite 类型定义。 */
    private array $contentColumns = [
        'site_id'           => 'INTEGER NOT NULL',
        'type'              => "VARCHAR(255) NOT NULL DEFAULT 'article'",
        'category_id'       => 'INTEGER',
        'group_id'          => 'INTEGER',
        'title'             => 'VARCHAR(255) NOT NULL',
        'slug'              => 'VARCHAR(255) NOT NULL',
        'summary'           => 'TEXT',
        'cover_id'          => 'INTEGER',
        'body'              => 'TEXT',
        'status'            => "VARCHAR(255) NOT NULL DEFAULT 'draft'",
        'published_at'      => 'DATETIME',
        'geo_conclusion'    => 'TEXT',
        'geo_explanation'   => 'TEXT',
        'geo_evidence'      => 'TEXT',
        'geo_boundary'      => 'TEXT',
        'geo_faq'           => 'TEXT',
        'geo_key_facts'     => 'TEXT',
        'fact_refs'        => 'TEXT',
        'owner'             => 'VARCHAR(255)',
        'reviewed_at'       => 'DATE',
        'review_due'        => 'DATE',
        'source_note'       => 'VARCHAR(255)',
        'og_image_id'       => 'INTEGER',
        'external_id'       => 'VARCHAR(255)',
        'external_source'   => 'VARCHAR(255)',
        'synced_at'         => 'DATETIME',
        'content_hash'      => 'VARCHAR(255)',
        'created_at'        => 'DATETIME',
        'updated_at'        => 'DATETIME',
        'deleted_at'        => 'DATETIME',
        'lock_manual'       => "TINYINT(1) NOT NULL DEFAULT '0'",
        'slot'              => 'VARCHAR(255)',
        'locale'            => "VARCHAR(16) NOT NULL DEFAULT 'zh-CN'",
        'translation_group' => 'VARCHAR(36)',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ---------------- entities ----------------
        Schema::table('entities', function (Blueprint $table) {
            $table->string('locale', 16)->default('zh-CN');
            $table->string('translation_group', 36)->nullable();
            $table->dropUnique('entities_site_type_slug_unique');
            $table->unique(['site_id', 'type', 'slug', 'locale'], 'entities_site_type_slug_locale_unique');
            $table->index('translation_group', 'entities_translation_group_index');
        });
        $this->backfillGroup('entities');

        // ---------------- contents ----------------
        $this->rebuildContents($driver);

        // ---------------- seo_metas ----------------
        Schema::table('seo_metas', function (Blueprint $table) {
            $table->string('locale', 16)->default('zh-CN');
        });

        if ($driver === 'mysql') {
            $this->rebuildSeoGuardsMysql(withLocale: true);
        } else {
            DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
            DB::statement('DROP INDEX IF EXISTS content_seo_meta_unique');
            DB::statement('DROP INDEX IF EXISTS entity_seo_meta_unique');
            DB::statement(
                'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id, locale) '
                . 'WHERE content_id IS NULL AND entity_id IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX content_seo_meta_unique ON seo_metas(site_id, content_id, locale) '
                . 'WHERE content_id IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX entity_seo_meta_unique ON seo_metas(site_id, entity_id, locale) '
                . 'WHERE entity_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // ---------------- seo_metas ----------------
        if ($driver === 'mysql') {
            $this->rebuildSeoGuardsMysql(withLocale: false);
        } else {
            DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
            DB::statement('DROP INDEX IF EXISTS content_seo_meta_unique');
            DB::statement('DROP INDEX IF EXISTS entity_seo_meta_unique');
            DB::statement(
                'CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_id) '
                . 'WHERE content_id IS NULL AND entity_id IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX content_seo_meta_unique ON seo_metas(site_id, content_id) '
                . 'WHERE content_id IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX entity_seo_meta_unique ON seo_metas(site_id, entity_id) '
                . 'WHERE entity_id IS NOT NULL'
            );
        }
        Schema::table('seo_metas', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        // ---------------- contents ----------------
        $this->rollbackContents($driver);

        // ---------------- entities ----------------
        // 同 contents：恢复非 locale 的 UNIQUE(site_id, type, slug) 前，删除非默认语言行。
        DB::table('entities')->where('locale', '!=', 'zh-CN')->delete();
        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('entities_translation_group_index');
            $table->dropUnique('entities_site_type_slug_locale_unique');
            $table->unique(['site_id', 'type', 'slug'], 'entities_site_type_slug_unique');
            $table->dropColumn(['translation_group', 'locale']);
        });
    }

    /**
     * MySQL：重建 seo_metas 的三个 partial-unique 等价生成列。
     * withLocale=true 时生成列拼接 locale（本迁移 up 之后）；false 时不拼（回滚到 2026_09_19 形态）。
     */
    private function rebuildSeoGuardsMysql(bool $withLocale): void
    {
        // 先删索引
        foreach (['sites_seo_meta_unique', 'content_seo_meta_unique', 'entity_seo_meta_unique'] as $idx) {
            DB::statement("DROP INDEX {$idx} ON seo_metas");
        }
        // 删旧生成列（2026_09_19 建的多列版本，以及本方法可能建过的拼接版本）
        foreach ([
            'site_guard_site',
            'content_guard_site', 'content_guard_id',
            'entity_guard_site', 'entity_guard_id',
            'site_guard', 'content_guard', 'entity_guard',
        ] as $col) {
            if (Schema::hasColumn('seo_metas', $col)) {
                DB::statement("ALTER TABLE seo_metas DROP COLUMN {$col}");
            }
        }

        if ($withLocale) {
            DB::statement("ALTER TABLE seo_metas ADD COLUMN site_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NULL AND entity_id IS NULL, CONCAT(site_id, '|', locale), NULL)) VIRTUAL");
            DB::statement("ALTER TABLE seo_metas ADD COLUMN content_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NOT NULL, CONCAT(site_id, '|', content_id, '|', locale), NULL)) VIRTUAL");
            DB::statement("ALTER TABLE seo_metas ADD COLUMN entity_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(entity_id IS NOT NULL, CONCAT(site_id, '|', entity_id, '|', locale), NULL)) VIRTUAL");
        } else {
            DB::statement("ALTER TABLE seo_metas ADD COLUMN site_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NULL AND entity_id IS NULL, site_id, NULL)) VIRTUAL");
            DB::statement("ALTER TABLE seo_metas ADD COLUMN content_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(content_id IS NOT NULL, CONCAT(site_id, '|', content_id), NULL)) VIRTUAL");
            DB::statement("ALTER TABLE seo_metas ADD COLUMN entity_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(entity_id IS NOT NULL, CONCAT(site_id, '|', entity_id), NULL)) VIRTUAL");
        }

        DB::statement('CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_guard)');
        DB::statement('CREATE UNIQUE INDEX content_seo_meta_unique ON seo_metas(content_guard)');
        DB::statement('CREATE UNIQUE INDEX entity_seo_meta_unique ON seo_metas(entity_guard)');
    }

    // ───────────────────────── contents: up ─────────────────────────

    private function rebuildContents(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->rebuildContentsSqlite();
            return;
        }

        // MySQL / PostgreSQL：直接 ALTER 加列，先建后删以维持 site_id 外键索引
        Schema::table('contents', function (Blueprint $table) {
            $table->string('locale', 16)->default('zh-CN');
            $table->string('translation_group', 36)->nullable();
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->unique(['site_id', 'slug', 'locale'], 'contents_site_slug_locale_unique');
            $table->unique(['site_id', 'slot', 'locale'], 'contents_slot_locale_unique');
            $table->index('translation_group', 'contents_translation_group_index');
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->dropUnique('contents_site_id_slug_unique');
            $table->dropUnique('contents_site_id_slot_unique');
        });
    }

    private function rebuildContentsSqlite(): void
    {
        $temp = 'contents_new';

        DB::statement('DROP TABLE IF EXISTS '.$temp);

        $colDefs = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach ($this->contentColumns as $name => $def) {
            $colDefs[] = '`'.$name.'` '.$def;
        }
        $colDefs[] = 'FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT';
        DB::statement('CREATE TABLE `'.$temp.'` ('.implode(', ', $colDefs).')');

        // 逐行拷贝（数据量小）：locale 缺省 zh-CN，group 缺省生成 uuid
        $oldRows = DB::select('SELECT * FROM contents');
        foreach ($oldRows as $row) {
            $src = (array) $row;
            $ins = [];
            foreach (array_keys($this->contentColumns) as $col) {
                if ($col === 'locale') {
                    $ins[$col] = ! empty($src['locale']) ? $src['locale'] : 'zh-CN';
                } elseif ($col === 'translation_group') {
                    $ins[$col] = ! empty($src['translation_group'])
                        ? $src['translation_group']
                        : (string) Str::uuid();
                } else {
                    $ins[$col] = $src[$col] ?? null;
                }
            }
            DB::table($temp)->insert($ins);
        }

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE '.$temp.' RENAME TO contents');

        DB::statement(
            'CREATE UNIQUE INDEX contents_site_slug_locale_unique '
            . 'ON contents(site_id, slug, locale)'
        );
        DB::statement('CREATE UNIQUE INDEX contents_slot_locale_unique ON contents(site_id, slot, locale)');
        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');
        DB::statement('CREATE INDEX contents_translation_group_index ON contents(translation_group)');
    }

    // ───────────────────────── contents: down ─────────────────────────

    private function rollbackContents(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->rollbackContentsSqlite();
            return;
        }

        // 回滚前清理非默认语言行：恢复后的 UNIQUE(site_id, slug) / UNIQUE(site_id, slot)
        // 无法容纳同一 slug/slot 的多语言行。仅保留默认语言（zh-CN）行，与改造前
        // 「一条内容一个 slug」的语义一致。
        DB::table('contents')->where('locale', '!=', 'zh-CN')->delete();

        // 先建旧索引（维持 site_id 外键索引），再删新索引，最后删列
        Schema::table('contents', function (Blueprint $table) {
            $table->unique(['site_id', 'slug'], 'contents_site_id_slug_unique');
            $table->unique(['site_id', 'slot'], 'contents_site_id_slot_unique');
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('contents_translation_group_index');
            $table->dropUnique('contents_site_slug_locale_unique');
            $table->dropUnique('contents_slot_locale_unique');
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['translation_group', 'locale']);
        });
    }

    private function rollbackContentsSqlite(): void
    {
        DB::statement('DROP INDEX IF EXISTS contents_translation_group_index');
        DB::statement('DROP INDEX IF EXISTS contents_site_slug_locale_unique');
        DB::statement('DROP INDEX IF EXISTS contents_slot_locale_unique');

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('contents_down');

        $colDefs = [];
        foreach ($this->contentColumns as $name => $def) {
            if ($name === 'locale' || $name === 'translation_group') {
                continue;
            }
            $colDefs[] = '`'.$name.'` '.$def;
        }
        $colDefs = array_merge(['id INTEGER PRIMARY KEY AUTOINCREMENT'], $colDefs);
        $colDefs[] = 'FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT';
        // 多站点：恢复 UNIQUE(site_id, slug) + UNIQUE(site_id, slot)，不能写 UNIQUE(slug)。
        $colDefs[] = 'UNIQUE(site_id, slug)';
        $colDefs[] = 'UNIQUE(site_id, slot)';

        DB::statement('CREATE TABLE `contents_down` ('.implode(', ', $colDefs).')');

        $keepCols = array_values(array_diff(
            array_keys($this->contentColumns),
            ['locale', 'translation_group']
        ));
        $selectList = implode(', ', array_map(fn ($c) => '`'.$c.'`', $keepCols));

        // 与非 sqlite 回滚一致：恢复后的 UNIQUE(site_id, slug) / UNIQUE(site_id, slot)
        // 无法容纳同一 slug/slot 的多语言行，必须先删除非默认语言（!= zh-CN）行，
        // 仅保留默认语言行，与改造前「一条内容一个 slug」语义一致。此前缺这一步，
        // 含 en 翻译的种子数据在回滚时触发 UNIQUE(site_id, slug) 冲突（23000）。
        DB::table('contents')->where('locale', '!=', 'zh-CN')->delete();

        DB::statement('INSERT INTO `contents_down` ('.$selectList.') SELECT '.$selectList.' FROM `contents`');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_down RENAME TO contents');

        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /** 给 entities 存量行回填 translation_group（升级库）；fresh 表空，无操作。 */
    private function backfillGroup(string $table): void
    {
        foreach (DB::table($table)->whereNull('translation_group')->get() as $row) {
            DB::table($table)->where('id', $row->id)->update([
                'translation_group' => (string) Str::uuid(),
            ]);
        }
    }
};
