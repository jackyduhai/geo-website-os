<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'page_blocks' => [
            'columns' => [
                'id'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'     => 'INTEGER NOT NULL',
                'page'        => 'VARCHAR(255) NOT NULL',
                'type'        => 'VARCHAR(255) NOT NULL',
                'title'       => 'VARCHAR(255)',
                'subtitle'    => 'VARCHAR(255)',
                'content'     => 'TEXT',
                'category_id' => 'INTEGER',
                'limit'       => 'INTEGER NOT NULL DEFAULT 0',
                'sort'        => 'INTEGER NOT NULL DEFAULT 0',
                'is_active'   => 'TINYINT(1) NOT NULL DEFAULT 1',
                'created_at'  => 'DATETIME',
                'updated_at'  => 'DATETIME',
            ],
            'indexes' => [
                'page_blocks_page_is_active_sort_index' => ['page', 'is_active', 'sort'],
                'page_blocks_site_id_index'             => ['site_id'],
            ],
        ],
        'media' => [
            'columns' => [
                'id'            => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'       => 'INTEGER NOT NULL',
                'disk'          => 'VARCHAR(255) NOT NULL',
                'path'          => 'VARCHAR(255) NOT NULL',
                'original_name' => 'VARCHAR(255)',
                'mime'          => 'VARCHAR(255)',
                'size'          => 'INTEGER NOT NULL DEFAULT 0',
                'width'         => 'INTEGER',
                'height'        => 'INTEGER',
                'alt'           => 'VARCHAR(255)',
                'title'         => 'VARCHAR(255)',
                'uploaded_by'   => 'INTEGER',
                'created_at'    => 'DATETIME',
                'updated_at'    => 'DATETIME',
            ],
            'indexes' => [
                'media_mime_index'    => ['mime'],
                'media_site_id_index' => ['site_id'],
            ],
        ],
        'banners' => [
            'columns' => [
                'id'         => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'    => 'INTEGER NOT NULL',
                'position'   => 'VARCHAR(255) NOT NULL',
                'title'      => 'VARCHAR(255)',
                'subtitle'   => 'VARCHAR(255)',
                'link'       => 'VARCHAR(255)',
                'link_text'  => 'VARCHAR(255)',
                'image_id'   => 'INTEGER',
                'target'     => 'INTEGER NOT NULL DEFAULT 0',
                'sort'       => 'INTEGER NOT NULL DEFAULT 0',
                'is_active'  => 'TINYINT(1) NOT NULL DEFAULT 1',
                'start_at'   => 'DATETIME',
                'end_at'     => 'DATETIME',
                'created_at' => 'DATETIME',
                'updated_at' => 'DATETIME',
            ],
            'indexes' => [
                'banners_position_is_active_sort_index' => ['position', 'is_active', 'sort'],
                'banners_site_id_index'                 => ['site_id'],
            ],
        ],
        'audit_logs' => [
            'columns' => [
                'id'          => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'site_id'     => 'INTEGER NOT NULL',
                'user_id'     => 'INTEGER',
                'action'      => 'VARCHAR(255) NOT NULL',
                'target_type' => 'VARCHAR(255)',
                'target_id'   => 'INTEGER',
                'summary'     => 'VARCHAR(255)',
                'detail'      => 'TEXT',
                'ip'          => 'VARCHAR(255)',
                'created_at'  => 'DATETIME',
                'updated_at'  => 'DATETIME',
            ],
            'indexes' => [
                'audit_logs_created_at_index'          => ['created_at'],
                'audit_logs_target_type_target_id_index' => ['target_type', 'target_id'],
                'audit_logs_site_id_index'             => ['site_id'],
            ],
        ],
    ];

    public function up(): void
    {
        $defaultSiteId = 1;

        foreach ($this->tables as $table => $config) {
            $tempTable = $table . '_new';

            // 1. Create new table with site_id
            $colDefs = [];
            foreach ($config['columns'] as $col => $def) {
                $colDefs[] = "`$col` $def";
            }
            $colDefs[] = "FOREIGN KEY (`site_id`) REFERENCES `sites`(`id`) ON DELETE RESTRICT";
            DB::statement("CREATE TABLE `$tempTable` (" . implode(', ', $colDefs) . ")");

            // 2. Copy data with site_id = default site
            $oldCols = array_filter(array_keys($config['columns']), fn($c) => $c !== 'site_id');
            $colList = implode(', ', array_map(fn($c) => "`$c`", $oldCols));
            DB::statement("INSERT INTO `$tempTable` (`site_id`, $colList) SELECT $defaultSiteId, $colList FROM `$table`");

            // 3. Drop old table (also drops its indexes, freeing index names)
            DB::statement("DROP TABLE `$table`");

            // 4. Rename new table to original name
            DB::statement("ALTER TABLE `$tempTable` RENAME TO `$table`");

            // 5. Create indexes (after rename, index names are free)
            foreach ($config['indexes'] as $idxName => $idxCols) {
                $idxColList = implode(', ', array_map(fn($c) => "`$c`", $idxCols));
                DB::statement("CREATE INDEX `$idxName` ON `$table` ($idxColList)");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $config) {
            $tempTable = $table . '_old';

            // 1. Create old table without site_id
            $oldCols = array_filter($config['columns'], fn($c) => $c !== 'site_id', ARRAY_FILTER_USE_KEY);
            $colDefs = [];
            foreach ($oldCols as $col => $def) {
                $colDefs[] = "`$col` $def";
            }
            DB::statement("CREATE TABLE `$tempTable` (" . implode(', ', $colDefs) . ")");

            // 2. Copy data (exclude site_id)
            $colList = implode(', ', array_map(fn($c) => "`$c`", array_keys($oldCols)));
            DB::statement("INSERT INTO `$tempTable` ($colList) SELECT $colList FROM `$table`");

            // 3. Drop current table (frees index names)
            DB::statement("DROP TABLE `$table`");

            // 4. Rename temp table to original
            DB::statement("ALTER TABLE `$tempTable` RENAME TO `$table`");

            // 5. Create original indexes (exclude site_id indexes)
            foreach ($config['indexes'] as $idxName => $idxCols) {
                if (in_array('site_id', $idxCols)) {
                    continue;
                }
                $idxColList = implode(', ', array_map(fn($c) => "`$c`", $idxCols));
                DB::statement("CREATE INDEX `$idxName` ON `$table` ($idxColList)");
            }
        }
    }
};
