<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEOFlow 幂等键唯一约束（20G-2 · C-4 并发兜底）
 *
 * 背景：external_id 是 GEOFlow 侧的业务主键，也是官网侧的幂等键。
 * 此前 contents.external_id 只有普通索引、没有唯一约束，upsert 是「先查后插」模式，
 * 两个并发请求（上游重试 / 多线程 / 网关重发）会同时查不到记录而双双插入，
 * 产生重复行；随后 status()/unpublish() 的 first() 只命中其中一条，另一条成永久孤儿。
 *
 * 本迁移：
 *   1. 先探测存量重复——有重复则中止并报错，绝不静默删数据；
 *   2. 无重复才创建 UNIQUE(site_id, external_id)。
 *
 * 注意：site_id 必须参与唯一键，否则多站下 A 站与 B 站的相同 external_id 会互相冲突。
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite 不支持 ALTER TABLE ADD CONSTRAINT，需重建表；
        // MySQL / PostgreSQL 可直接加约束。此处按驱动分支。
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();
            return;
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->unique(['site_id', 'external_id'], 'contents_site_external_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->downSqlite();
            return;
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->dropUnique('contents_site_external_unique');
        });
    }

    /**
     * SQLite 回滚：对称重建表，只移除 UNIQUE(site_id, external_id)。
     *
     * ⚠️ 绝对不能用 `CREATE TABLE ... AS SELECT *`（20G-6 · C-16）：
     * CTAS 只复制「查询结果的形状」，不复制原表定义——列类型退化为 INT/TEXT、
     * PRIMARY KEY / AUTOINCREMENT / UNIQUE / FOREIGN KEY / DEFAULT 全部丢失。
     * 实测后果：回滚后 UNIQUE(site_id, slug) 失效，同站重复 slug 可入库；
     * 主键与外键一并消失，破坏数据完整性与多站隔离的地基。
     *
     * 因此这里必须像 upSqlite() 一样**显式写出完整列定义**，
     * 唯一差别是不含 UNIQUE(site_id, external_id)。
     */
    protected function downSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('contents_rollback');

        DB::statement('CREATE TABLE contents_rollback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            type VARCHAR(255) NOT NULL DEFAULT \'article\',
            category_id INTEGER,
            group_id INTEGER,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            summary TEXT,
            cover_id INTEGER,
            body TEXT,
            status VARCHAR(255) NOT NULL DEFAULT \'draft\',
            published_at DATETIME,
            geo_conclusion TEXT,
            geo_explanation TEXT,
            geo_evidence TEXT,
            geo_boundary TEXT,
            geo_faq TEXT,
            geo_key_facts TEXT,
            fact_refs TEXT,
            owner VARCHAR(255),
            reviewed_at DATE,
            review_due DATE,
            source_note VARCHAR(255),
            og_image_id INTEGER,
            external_id VARCHAR(255),
            external_source VARCHAR(255),
            synced_at DATETIME,
            content_hash VARCHAR(255),
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME,
            lock_manual TINYINT(1) NOT NULL DEFAULT 0,
            slot VARCHAR(255),
            locale VARCHAR(16) NOT NULL DEFAULT \'zh-CN\',
            translation_group VARCHAR(36),
            UNIQUE(site_id, slug, locale),
            UNIQUE(site_id, slot),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT
        )');

        //显式列清单：避免依赖列序，将来加列时也更安全
        DB::statement('INSERT INTO contents_rollback SELECT
            id, site_id, type, category_id, group_id, title, slug, summary, cover_id, body,
            status, published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary,
            geo_faq, geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note,
            og_image_id, external_id, external_source, synced_at, content_hash,
            created_at, updated_at, deleted_at, lock_manual, slot, locale, translation_group
        FROM contents');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_rollback RENAME TO contents');

        // 重建索引（与 upSqlite 对称）
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');
        DB::statement('CREATE INDEX contents_translation_group_index ON contents(translation_group)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function upSqlite(): void
    {
        $duplicates = DB::select(
            "SELECT site_id, external_id, COUNT(*) AS c
             FROM contents
             WHERE external_id IS NOT NULL AND external_id != ''
             GROUP BY site_id, external_id
             HAVING COUNT(*) > 1"
        );

        if ($duplicates !== []) {
            $detail = collect($duplicates)
                ->map(fn ($d) => "site_id={$d->site_id} external_id={$d->external_id} (×{$d->c})")
                ->implode(', ');

            throw new RuntimeException(
                "无法创建 contents(site_id, external_id) 唯一约束：存在重复 external_id → {$detail}。"
                .'请先人工清理重复数据后重试。本迁移不会自动删除任何内容。'
            );
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists('contents_new');

        DB::statement('CREATE TABLE contents_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            type VARCHAR(255) NOT NULL DEFAULT \'article\',
            category_id INTEGER,
            group_id INTEGER,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            summary TEXT,
            cover_id INTEGER,
            body TEXT,
            status VARCHAR(255) NOT NULL DEFAULT \'draft\',
            published_at DATETIME,
            geo_conclusion TEXT,
            geo_explanation TEXT,
            geo_evidence TEXT,
            geo_boundary TEXT,
            geo_faq TEXT,
            geo_key_facts TEXT,
            fact_refs TEXT,
            owner VARCHAR(255),
            reviewed_at DATE,
            review_due DATE,
            source_note VARCHAR(255),
            og_image_id INTEGER,
            external_id VARCHAR(255),
            external_source VARCHAR(255),
            synced_at DATETIME,
            content_hash VARCHAR(255),
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME,
            lock_manual TINYINT(1) NOT NULL DEFAULT 0,
            slot VARCHAR(255),
            locale VARCHAR(16) NOT NULL DEFAULT \'zh-CN\',
            translation_group VARCHAR(36),
            UNIQUE(site_id, slug, locale),
            UNIQUE(site_id, slot),
            UNIQUE(site_id, external_id),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT
        )');

        DB::statement('INSERT INTO contents_new SELECT
            id, site_id, type, category_id, group_id, title, slug, summary, cover_id, body,
            status, published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary,
            geo_faq, geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note,
            og_image_id, external_id, external_source, synced_at, content_hash,
            created_at, updated_at, deleted_at, lock_manual, slot, locale, translation_group
        FROM contents');

        DB::statement('DROP TABLE contents');
        DB::statement('ALTER TABLE contents_new RENAME TO contents');

        // 重建索引
        DB::statement('CREATE INDEX contents_category_id_group_id_index ON contents(category_id, group_id)');
        DB::statement('CREATE INDEX contents_type_category_id_index ON contents(type, category_id)');
        DB::statement('CREATE INDEX contents_status_published_at_index ON contents(status, published_at)');
        DB::statement('CREATE INDEX contents_site_id_index ON contents(site_id)');
        DB::statement('CREATE INDEX contents_external_id_index ON contents(external_id)');
        DB::statement('CREATE INDEX contents_translation_group_index ON contents(translation_group)');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
