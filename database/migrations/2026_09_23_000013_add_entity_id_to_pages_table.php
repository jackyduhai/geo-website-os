<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-2a（Detail override 方案 ii）：pages 增加 nullable entity_id。
 * --------------------------------------------------
 * 一个 Entity 在一个 Site 至多拥有一个“详情载体 Page”（partial unique
 * (site_id, entity_id) WHERE entity_id IS NOT NULL）。该 Page 仅承载详情的
 * 结构覆盖（可组合槽），固定槽仍由 Entity 直驱；**绝不复制业务数据**。
 *
 * MySQL 不支持 partial index，用虚拟生成列（entity_id 为 NULL 时守卫列也为 NULL）等价实现。
 *
 * 级联删除：Entity 删除时的级联清理在 Entity 模型 deleted 钩子完成（Page::deleting
 * 已级联 block/seo），与 DB FK ON DELETE CASCADE 功能等价、覆盖全部删除路径。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('pages', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->index('entity_id', 'pages_entity_id_index');
        });

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE pages ADD COLUMN entity_guard VARCHAR(255) GENERATED ALWAYS AS (
                IF(entity_id IS NOT NULL, CONCAT(site_id, '|', entity_id), NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX pages_entity_unique ON pages(entity_guard)');
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX pages_entity_unique ON pages(site_id, entity_id) '
                . 'WHERE entity_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX pages_entity_unique ON pages');
            if (Schema::hasColumn('pages', 'entity_guard')) {
                DB::statement('ALTER TABLE pages DROP COLUMN entity_guard');
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS pages_entity_unique');
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex('pages_entity_id_index');
            $table->dropColumn('entity_id');
        });
    }
};
