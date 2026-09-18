<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.4-D: Core tables site_id migration
 *
 * Rebuild 5 core tables with site_id + site-scoped unique constraints:
 * - contents: UNIQUE(slug) -> UNIQUE(site_id, slug), UNIQUE(slot) -> UNIQUE(site_id, slot)
 * - categories: UNIQUE(slug) -> UNIQUE(site_id, slug)
 * - groups: UNIQUE(category_id, slug) -> UNIQUE(site_id, category_id, slug), preserves FK category_id->categories
 * - facts: UNIQUE(key) -> UNIQUE(site_id, key)
 * - settings: UNIQUE(key) -> UNIQUE(site_id, key)
 *
 * Uses SQLite table rebuild (required for unique constraint changes).
 * Existing data is backfilled to site_id = 1 (default site).
 */
return new class extends Migration
{
    private array $tables = [
        'contents' => [
            'columns' => [
                'id'              => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'         => 'INTEGER NOT NULL',
                'type'            => "VARCHAR(255) NOT NULL DEFAULT 'article'",
                'category_id'     => 'INTEGER',
                'group_id'        => 'INTEGER',
                'title'           => 'VARCHAR(255) NOT NULL',
                'slug'            => 'VARCHAR(255) NOT NULL',
                'summary'         => 'TEXT',
                'cover_id'        => 'INTEGER',
                'body'            => 'TEXT',
                'status'          => "VARCHAR(255) NOT NULL DEFAULT 'draft'",
                'published_at'    => 'DATETIME',
                'geo_conclusion'  => 'TEXT',
                'geo_explanation' => 'TEXT',
                'geo_evidence'    => 'TEXT',
                'geo_boundary'    => 'TEXT',
                'geo_faq'         => 'TEXT',
                'geo_key_facts'   => 'TEXT',
                'fact_refs'       => 'TEXT',
                'owner'           => 'VARCHAR(255)',
                'reviewed_at'     => 'DATE',
                'review_due'      => 'DATE',
                'source_note'     => 'VARCHAR(255)',
                'seo_title'       => 'VARCHAR(255)',
                'seo_desc'        => 'VARCHAR(255)',
                'canonical'       => 'VARCHAR(255)',
                'og_image_id'     => 'INTEGER',
                'noindex'         => "TINYINT(1) NOT NULL DEFAULT '0'",
                'external_id'     => 'VARCHAR(255)',
                'external_source' => 'VARCHAR(255)',
                'synced_at'       => 'DATETIME',
                'content_hash'    => 'VARCHAR(255)',
                'created_at'      => 'DATETIME',
                'updated_at'      => 'DATETIME',
                'deleted_at'      => 'DATETIME',
                'lock_manual'     => "TINYINT(1) NOT NULL DEFAULT '0'",
                'slot'            => 'VARCHAR(255)',
            ],
            'unique' => [
                'contents_site_id_slug_unique' => ['site_id', 'slug'],
                'contents_site_id_slot_unique' => ['site_id', 'slot'],
            ],
            'fk' => [
                'site_id' => ['sites', 'id', 'RESTRICT'],
            ],
            'indexes' => [
                'contents_external_id_index'          => ['external_id'],
                'contents_category_id_group_id_index' => ['category_id', 'group_id'],
                'contents_type_category_id_index'     => ['type', 'category_id'],
                'contents_status_published_at_index'  => ['status', 'published_at'],
                'contents_site_id_index'              => ['site_id'],
            ],
        ],
        'categories' => [
            'columns' => [
                'id'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'     => 'INTEGER NOT NULL',
                'parent_id'   => 'INTEGER',
                'name'        => 'VARCHAR(255) NOT NULL',
                'slug'        => 'VARCHAR(255) NOT NULL',
                'type'        => "VARCHAR(255) NOT NULL DEFAULT 'list'",
                'template'    => 'VARCHAR(255)',
                'description' => 'TEXT',
                'icon'        => 'VARCHAR(255)',
                'sort'        => "INTEGER NOT NULL DEFAULT '0'",
                'is_nav'      => "TINYINT(1) NOT NULL DEFAULT '1'",
                'is_active'   => "TINYINT(1) NOT NULL DEFAULT '1'",
                'seo_title'   => 'VARCHAR(255)',
                'seo_desc'    => 'VARCHAR(255)',
                'created_at'  => 'DATETIME',
                'updated_at'  => 'DATETIME',
            ],
            'unique' => [
                'categories_site_id_slug_unique' => ['site_id', 'slug'],
            ],
            'fk' => [
                'site_id' => ['sites', 'id', 'RESTRICT'],
            ],
            'indexes' => [
                'categories_is_nav_is_active_index' => ['is_nav', 'is_active'],
                'categories_parent_id_sort_index'  => ['parent_id', 'sort'],
                'categories_site_id_index'         => ['site_id'],
            ],
        ],
        'groups' => [
            'columns' => [
                'id'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'     => 'INTEGER NOT NULL',
                'category_id' => 'INTEGER NOT NULL',
                'name'        => 'VARCHAR(255) NOT NULL',
                'slug'        => 'VARCHAR(255) NOT NULL',
                'description' => 'TEXT',
                'sort'        => "INTEGER NOT NULL DEFAULT '0'",
                'is_active'   => "TINYINT(1) NOT NULL DEFAULT '1'",
                'created_at'  => 'DATETIME',
                'updated_at'  => 'DATETIME',
            ],
            'unique' => [
                'groups_site_id_category_id_slug_unique' => ['site_id', 'category_id', 'slug'],
            ],
            'fk' => [
                'site_id'     => ['sites', 'id', 'RESTRICT'],
                'category_id' => ['categories', 'id', 'CASCADE'],
            ],
            'indexes' => [
                'groups_category_id_sort_index' => ['category_id', 'sort'],
                'groups_site_id_index'          => ['site_id'],
            ],
        ],
        'facts' => [
            'columns' => [
                'id'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'     => 'INTEGER NOT NULL',
                'key'         => 'VARCHAR(255) NOT NULL',
                'label'       => 'VARCHAR(255) NOT NULL',
                'value'       => 'TEXT NOT NULL',
                'group'       => 'VARCHAR(255)',
                'source'      => 'VARCHAR(255)',
                'owner'       => 'VARCHAR(255)',
                'reviewed_at' => 'DATE',
                'review_due'  => 'DATE',
                'is_public'   => "TINYINT(1) NOT NULL DEFAULT '1'",
                'sort'        => "INTEGER NOT NULL DEFAULT '0'",
                'created_at'  => 'DATETIME',
                'updated_at'  => 'DATETIME',
            ],
            'unique' => [
                'facts_site_id_key_unique' => ['site_id', 'key'],
            ],
            'fk' => [
                'site_id' => ['sites', 'id', 'RESTRICT'],
            ],
            'indexes' => [
                'facts_review_due_index'  => ['review_due'],
                'facts_group_sort_index'  => ['group', 'sort'],
                'facts_site_id_index'     => ['site_id'],
            ],
        ],
        'settings' => [
            'columns' => [
                'id'         => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'    => 'INTEGER NOT NULL',
                'key'        => 'VARCHAR(255) NOT NULL',
                'value'      => 'TEXT',
                'group'      => "VARCHAR(255) NOT NULL DEFAULT 'general'",
                'label'      => 'VARCHAR(255)',
                'type'       => "VARCHAR(255) NOT NULL DEFAULT 'text'",
                'hint'       => 'TEXT',
                'sort'       => "INTEGER NOT NULL DEFAULT '0'",
                'created_at' => 'DATETIME',
                'updated_at' => 'DATETIME',
            ],
            'unique' => [
                'settings_site_id_key_unique' => ['site_id', 'key'],
            ],
            'fk' => [
                'site_id' => ['sites', 'id', 'RESTRICT'],
            ],
            'indexes' => [
                'settings_group_sort_index' => ['group', 'sort'],
                'settings_site_id_index'    => ['site_id'],
            ],
        ],
    ];

    public function up(): void
    {
        // Temporarily disable FK checks for SQLite rebuild
        DB::statement('PRAGMA foreign_keys = OFF');

        $defaultSiteId = 1;

        foreach ($this->tables as $table => $config) {
            $tempTable = $table . '_new';

            // 1. Build column definitions
            $colDefs = [];
            foreach ($config['columns'] as $col => $def) {
                $colDefs[] = "`$col` $def";
            }

            // 2. Add unique constraints
            foreach ($config['unique'] as $ukName => $ukCols) {
                $colList = implode(', ', array_map(fn($c) => "`$c`", $ukCols));
                $colDefs[] = "UNIQUE($colList)";
            }

            // 3. Add foreign keys
            foreach ($config['fk'] as $fkCol => $fkDef) {
                [$fkTable, $fkId, $onDelete] = $fkDef;
                $colDefs[] = "FOREIGN KEY (`$fkCol`) REFERENCES `$fkTable`(`$fkId`) ON DELETE $onDelete";
            }

            DB::statement("CREATE TABLE `$tempTable` (" . implode(', ', $colDefs) . ")");

            // 4. Copy data with site_id = default site
            $oldCols = array_filter(array_keys($config['columns']), fn($c) => $c !== 'site_id');
            $colList = implode(', ', array_map(fn($c) => "`$c`", $oldCols));
            DB::statement("INSERT INTO `$tempTable` (`site_id`, $colList) SELECT $defaultSiteId, $colList FROM `$table`");

            // 5. Drop old table
            DB::statement("DROP TABLE `$table`");

            // 6. Rename new table
            DB::statement("ALTER TABLE `$tempTable` RENAME TO `$table`");

            // 7. Create non-unique indexes
            foreach ($config['indexes'] as $idxName => $idxCols) {
                $idxColList = implode(', ', array_map(fn($c) => "`$c`", $idxCols));
                DB::statement("CREATE INDEX `$idxName` ON `$table` ($idxColList)");
            }
        }

        // Re-enable FK checks
        DB::statement('PRAGMA foreign_keys = ON');

        // Verify no FK violations
        $violations = DB::select('PRAGMA foreign_key_check');
        if (count($violations) > 0) {
            throw new \RuntimeException('FK violations after migration: ' . json_encode($violations));
        }
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        foreach ($this->tables as $table => $config) {
            $tempTable = $table . '_old';

            // 1. Build old column definitions (without site_id)
            $oldCols = array_filter($config['columns'], fn($c) => $c !== 'site_id', ARRAY_FILTER_USE_KEY);
            $colDefs = [];
            foreach ($oldCols as $col => $def) {
                $colDefs[] = "`$col` $def";
            }

            // 2. Add original unique constraints (without site_id)
            foreach ($config['unique'] as $ukName => $ukCols) {
                $origCols = array_filter($ukCols, fn($c) => $c !== 'site_id');
                if (!empty($origCols)) {
                    $colList = implode(', ', array_map(fn($c) => "`$c`", $origCols));
                    $colDefs[] = "UNIQUE($colList)";
                }
            }

            // 3. Add original FKs (without site_id)
            foreach ($config['fk'] as $fkCol => $fkDef) {
                if ($fkCol === 'site_id') {
                    continue;
                }
                [$fkTable, $fkId, $onDelete] = $fkDef;
                $colDefs[] = "FOREIGN KEY (`$fkCol`) REFERENCES `$fkTable`(`$fkId`) ON DELETE $onDelete";
            }

            DB::statement("CREATE TABLE `$tempTable` (" . implode(', ', $colDefs) . ")");

            // 4. Copy data (exclude site_id)
            $colList = implode(', ', array_map(fn($c) => "`$c`", array_keys($oldCols)));
            DB::statement("INSERT INTO `$tempTable` ($colList) SELECT $colList FROM `$table`");

            // 5. Drop current table
            DB::statement("DROP TABLE `$table`");

            // 6. Rename
            DB::statement("ALTER TABLE `$tempTable` RENAME TO `$table`");

            // 7. Create original indexes (exclude site_id indexes)
            foreach ($config['indexes'] as $idxName => $idxCols) {
                if (in_array('site_id', $idxCols)) {
                    continue;
                }
                $idxColList = implode(', ', array_map(fn($c) => "`$c`", $idxCols));
                DB::statement("CREATE INDEX `$idxName` ON `$table` ($idxColList)");
            }
        }

        DB::statement('PRAGMA foreign_keys = ON');

        $violations = DB::select('PRAGMA foreign_key_check');
        if (count($violations) > 0) {
            throw new \RuntimeException('FK violations after rollback: ' . json_encode($violations));
        }
    }
};
