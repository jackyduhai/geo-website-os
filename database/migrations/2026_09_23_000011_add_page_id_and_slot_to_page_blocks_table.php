<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-1：page_blocks 增加 page_id + slot。
 * --------------------------------------------------
 * - Landing / 组合页 blocks：page_id 指向 pages，slot 标识槽位（默认 main）。
 * - 现有首页 blocks：page_id 为 NULL（沿用 page='home' 字符串机制，18G-2 再迁），
 *   本迁移对首页零破坏。
 *
 * 不添加数据库外键（SQLite 经 ALTER 加约束需重建表）；Page 删除时的级联清理在
 * 模型事件（Page::deleting）中完成，避免孤儿 block。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable();
            $table->string('slot', 40)->default('main');
            $table->index('page_id', 'page_blocks_page_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->dropIndex('page_blocks_page_id_index');
            $table->dropColumn(['page_id', 'slot']);
        });
    }
};
