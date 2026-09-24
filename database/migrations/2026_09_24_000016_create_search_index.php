<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 搜索索引（P-STEP 18H-1 Search Productization）。
 * ------------------------------------------------------------------
 * 建立两层「派生只读索引（Derived Read Model）」——它们不是事实源，权威数据仍是
 * contents / entities / entity_relations，可随时由 search:reindex 全量重建：
 *
 *   1. {@see search_documents}：全数据库驱动通用的普通表（MySQL / PostgreSQL / SQLite），
 *      供 DatabaseLikeEngine 在无 FTS5 时做 LIKE + DB 层分页；
 *   2. {@see search_index}：仅当 SQLite 编译了 FTS5 时创建的 FTS5 虚拟表，供
 *      SqliteFtsEngine 做 MATCH / bm25 / 全文检索。
 *
 * path 为相对前台路径（建索引时固化），让结果 URL 直接经 PublicUrl 拼接、无需回查
 * 模型（无 N+1）。FTS 列顺序（bm25 / 列位置以此为准）：
 *   0 resource_type · 1 resource_id · 2 site_id · 3 locale · 4 slug · 5 path ·
 *   6 title · 7 summary · 8 body · 9 published_at
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) 全驱动通用的普通索引表
        if (! Schema::hasTable('search_documents')) {
            Schema::create('search_documents', function (Blueprint $table) {
                $table->id();
                $table->string('resource_type', 30);
                $table->unsignedBigInteger('resource_id');
                $table->unsignedBigInteger('site_id');
                $table->string('locale', 16);
                $table->string('slug')->nullable();
                $table->string('path')->nullable();
                $table->string('title');
                $table->text('summary')->nullable();
                $table->text('body')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['resource_type', 'resource_id', 'site_id', 'locale'],
                    'search_doc_unique',
                );
                $table->index(['site_id', 'locale'], 'search_doc_site_locale_idx');
            });
        }

        // 2) SQLite FTS5 虚拟表（驱动 + 能力双重守卫，失败不阻断安装）
        if (DB::getDriverName() === 'sqlite' && $this->fts5Available()) {
            DB::statement(
                "CREATE VIRTUAL TABLE search_index USING fts5(
                    resource_type UNINDEXED,
                    resource_id   UNINDEXED,
                    site_id       UNINDEXED,
                    locale        UNINDEXED,
                    slug          UNINDEXED,
                    path          UNINDEXED,
                    title,
                    summary,
                    body,
                    published_at  UNINDEXED
                )"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement('DROP TABLE IF EXISTS search_index');
            } catch (\Throwable) {
                // 虚拟表本就不存在时忽略
            }
        }

        Schema::dropIfExists('search_documents');
    }

    /**
     * 探测当前 SQLite 是否编译了 FTS5（建 / 删一个一次性虚拟表）。
     * 任何异常都判定为不可用，系统回退到普通表 + LIKE，安装绝不因此失败。
     */
    private function fts5Available(): bool
    {
        try {
            DB::statement('CREATE VIRTUAL TABLE _fts5_probe USING fts5(x)');
            DB::statement('DROP TABLE _fts5_probe');

            return true;
        } catch (\Throwable) {
            try {
                DB::statement('DROP TABLE IF EXISTS _fts5_probe');
            } catch (\Throwable) {
                // 忽略清理异常
            }

            return false;
        }
    }
};
