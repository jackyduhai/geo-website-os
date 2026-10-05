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
 * contents 的 slug/slot 唯一约束在 SQLite 中经历史 dropColumn 重建后是无法
 * DROP INDEX 的 sqlite_autoindex，故 contents 采用「建新表 → 拷数据 → 改名」重建。
 */
return new class extends Migration
{
    /** contents 重建后的列（不含 id）=> 类型定义。 */
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
        // ---------------- entities ----------------
        Schema::table('entities', function (Blueprint $table) {
            $table->string('locale', 16)->default('zh-CN');
            $table->string('translation_group', 36)->nullable();
            $table->dropUnique('entities_site_type_slug_unique');
            $table->unique(['site_id', 'type', 'slug', 'locale'], 'entities_site_type_slug_locale_unique');
            $table->index('translation_group', 'entities_translation_group_index');
        });
        $this->backfillGroup('entities');

        // ---------------- contents（表重建） ----------------
        $this->rebuildContents();

        // ---------------- seo_metas ----------------
        Schema::table('seo_metas', function (Blueprint $table) {
            $table->string('locale', 16)->default('zh-CN');
        });

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

    public function down(): void
    {
        // seo_metas
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
        Schema::table('seo_metas', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        // contents：drop 新索引、去 locale/group、恢复 slug 全局唯一
        //
        // ⚠️ SQLite 下 `dropUnique('名字')` 对**表级**唯一约束无效（20G-8-B 实测）。
        // 约束是 down 的直接产物：`2026_09_17_000002` 用 `->unique()` 在 contents 上
        // 建出的是表级约束（sqlite_autoindex_contents_N，origin=u），
        // 而本迁移 down 里的 `dropUnique('contents_slot_unique')` / `dropUnique(
        // 'contents_site_slug_locale_unique')` 按名找的是**命名索引**，找不到 →
        // 报错并中断回滚链（且 Laravel 会吞掉异常、退出码仍是 0，极难发现）。
        //
        // 修法：contents 的 down 走**显式重建表**（与 up 的 rebuildContents() 对称），
        // 顺带把 up 里创建的命名索引先删掉（那些是 origin=c，可以按名 DROP）。
        $this->downSqliteContents();

        // entities
        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('entities_translation_group_index');
            $table->dropUnique('entities_site_type_slug_locale_unique');
            $table->unique(['site_id', 'type', 'slug'], 'entities_site_type_slug_unique');
            $table->dropColumn(['translation_group', 'locale']);
        });
    }

    /** contents 建新表（slug 无全局唯一）→ 拷数据 → 改名 → 建命名索引。 */
    /**
     * contents 的 SQLite 回滚：显式重建表，去掉 locale / translation_group，
     * 恢复「slug 全局唯一」的上游形态。
     *
     * 为什么必须重建而不能用 dropUnique（20G-8-B 实测）：
     * 本迁移的 up() 是「建新表 → 改名」，建出的唯一约束是**表级**
     * （`sqlite_autoindex_contents_N`，origin=u）。SQLite 不支持按名DROP 表级约束，
     * 而 `dropUnique('contents_slot_unique')` 找的是命名索引 → 报错、回滚链中断。
     *
     * 与 20G-6 · C-16 同一个坑的另一个面：那次的教训是「CTAS 重建会丢约束」，
     * 所以这里**显式写出完整列定义 + 主键 + 外键 + 索引**，不做 CTAS。
     *
     * 保留的列 = up() 之前的形态（即无 locale / 无 translation_group），
     * 唯一约束恢复为 up() 之前的 UNIQUE(site_id, slug) / UNIQUE(site_id, slot)
     * （实测确认；注意不是 UNIQUE(slug)——本库是多站点，同slug 可跨站重复）。
     */
    private function downSqliteContents(): void
    {
        // 先删命名索引（origin=c，可按名 DROP）——顺序无所谓，
        // 因为随后整表会被 DROP 掉。
        DB::statement('DROP INDEX IF EXISTS contents_translation_group_index');
        DB::statement('DROP INDEX IF EXISTS contents_site_slug_locale_unique');

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('contents_down');

        $colDefs = [];
        foreach ($this->contentColumns as $name => $def) {
            // 回滚目标：去掉 locale / translation_group
            if ($name === 'locale' || $name === 'translation_group') {
                continue;
            }
            $colDefs[] = '`'.$name.'` '.$def;
        }
        $colDefs = array_merge(['id INTEGER PRIMARY KEY AUTOINCREMENT'], $colDefs);
        $colDefs[] = 'FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT';
        // 唯一约束：恢复**该迁移 up 之前**的形态 —— 实测为
        //   UNIQUE(site_id, slug) + UNIQUE(site_id, slot)
        // ⚠️ 不能写 `UNIQUE(slug)`：本库是**多站点**系统，同一 slug 会在
        // 多个站点各有一行（多站隔离的基线设计）。写成 UNIQUE(slug) 后，
        // 回滚时 `INSERT INTO contents_down SELECT ...` 遇到
        // 「A 站中文页 + A 站英文页 slug 相同」就直接唯一约束冲突 →
        // 回滚失败（20G-8-A 实测：有数据时 FAIL，空库时却通过）。
        $colDefs[] = 'UNIQUE(site_id, slug)';
        $colDefs[] = 'UNIQUE(site_id, slot)';

        DB::statement('CREATE TABLE `contents_down` ('.implode(', ', $colDefs).')');

        $keepCols = array_values(array_diff(
            array_keys($this->contentColumns),
            ['locale', 'translation_group']
        ));
        $selectList = implode(', ', array_map(fn ($c) => '`'.$c.'`', $keepCols));

        DB::statement('INSERT INTO `contents_down` ('.$selectList.') SELECT '.$selectList.' FROM `contents`');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_down RENAME TO contents');

        // 重建索引（与 rebuildContents 对称）
        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function rebuildContents(): void
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

        // 命名索引 / 唯一
        DB::statement(
            'CREATE UNIQUE INDEX contents_site_slug_locale_unique '
            . 'ON contents(site_id, slug, locale)'
        );
        DB::statement('CREATE UNIQUE INDEX contents_slot_unique ON contents(slot)');
        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');
        DB::statement('CREATE INDEX contents_translation_group_index ON contents(translation_group)');
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
