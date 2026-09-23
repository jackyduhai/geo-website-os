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
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('contents_translation_group_index');
            $table->dropUnique('contents_site_slug_locale_unique');
            $table->dropUnique('contents_slot_unique');
            $table->dropColumn(['translation_group', 'locale']);
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->unique('slug');
            $table->unique('slot');
        });

        // entities
        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('entities_translation_group_index');
            $table->dropUnique('entities_site_type_slug_locale_unique');
            $table->unique(['site_id', 'type', 'slug'], 'entities_site_type_slug_unique');
            $table->dropColumn(['translation_group', 'locale']);
        });
    }

    /** contents 建新表（slug 无全局唯一）→ 拷数据 → 改名 → 建命名索引。 */
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
