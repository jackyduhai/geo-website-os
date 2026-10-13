<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->createMySql();
        } else {
            $this->createSqliteOrPgsql($driver);
        }
    }

    private function createMySql(): void
    {
        DB::statement("
            CREATE TABLE seo_metas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                content_id BIGINT UNSIGNED NULL,
                entity_id BIGINT UNSIGNED NULL,
                title VARCHAR(255) NULL,
                description TEXT NULL,
                keywords TEXT NULL,
                canonical VARCHAR(255) NULL,
                og_title VARCHAR(255) NULL,
                og_description TEXT NULL,
                og_image_path VARCHAR(255) NULL,
                og_type VARCHAR(255) NOT NULL DEFAULT 'website',
                twitter_card VARCHAR(255) NOT NULL DEFAULT 'summary_large_image',
                noindex TINYINT(1) NOT NULL DEFAULT 0,
                nofollow TINYINT(1) NOT NULL DEFAULT 0,
                robots TEXT NULL,
                schema_type VARCHAR(255) NULL,
                metadata TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                CONSTRAINT chk_seo_metas_binding CHECK (
                    (content_id IS NULL AND entity_id IS NULL)
                    OR (content_id IS NOT NULL AND entity_id IS NULL)
                    OR (content_id IS NULL AND entity_id IS NOT NULL)
                ),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE,
                FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
            )
        ");

        DB::statement('CREATE INDEX seo_metas_site_id_index ON seo_metas(site_id)');
        DB::statement('CREATE INDEX seo_metas_content_id_index ON seo_metas(content_id)');
        DB::statement('CREATE INDEX seo_metas_entity_id_index ON seo_metas(entity_id)');
        DB::statement('CREATE INDEX seo_metas_canonical_index ON seo_metas(canonical)');

        // MySQL 不支持 partial index：用虚拟生成列（不满足条件时为 NULL，唯一索引允许多个 NULL）
        DB::statement('ALTER TABLE seo_metas ADD COLUMN site_guard_site BIGINT UNSIGNED GENERATED ALWAYS AS (IF(content_id IS NULL AND entity_id IS NULL, site_id, NULL)) VIRTUAL');
        DB::statement('CREATE UNIQUE INDEX sites_seo_meta_unique ON seo_metas(site_guard_site)');

        DB::statement('ALTER TABLE seo_metas ADD COLUMN content_guard_site BIGINT UNSIGNED GENERATED ALWAYS AS (IF(content_id IS NOT NULL, site_id, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE seo_metas ADD COLUMN content_guard_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(content_id IS NOT NULL, content_id, NULL)) VIRTUAL');
        DB::statement('CREATE UNIQUE INDEX content_seo_meta_unique ON seo_metas(content_guard_site, content_guard_id)');

        DB::statement('ALTER TABLE seo_metas ADD COLUMN entity_guard_site BIGINT UNSIGNED GENERATED ALWAYS AS (IF(entity_id IS NOT NULL, site_id, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE seo_metas ADD COLUMN entity_guard_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(entity_id IS NOT NULL, entity_id, NULL)) VIRTUAL');
        DB::statement('CREATE UNIQUE INDEX entity_seo_meta_unique ON seo_metas(entity_guard_site, entity_guard_id)');
    }

    private function createSqliteOrPgsql(string $driver): void
    {
        $idType = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
        $refType = $driver === 'pgsql' ? 'BIGINT' : 'INTEGER';
        $boolType = $driver === 'pgsql' ? 'SMALLINT NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';
        $timeType = $driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';

        DB::statement("
            CREATE TABLE seo_metas (
                id {$idType},
                site_id {$refType} NOT NULL,
                content_id {$refType} NULL,
                entity_id {$refType} NULL,
                title VARCHAR NULL,
                description TEXT NULL,
                keywords TEXT NULL,
                canonical VARCHAR NULL,
                og_title VARCHAR NULL,
                og_description TEXT NULL,
                og_image_path VARCHAR NULL,
                og_type VARCHAR NOT NULL DEFAULT 'website',
                twitter_card VARCHAR NOT NULL DEFAULT 'summary_large_image',
                noindex {$boolType},
                nofollow {$boolType},
                robots TEXT NULL,
                schema_type VARCHAR NULL,
                metadata TEXT NULL,
                created_at {$timeType} NULL,
                updated_at {$timeType} NULL,
                CONSTRAINT chk_seo_metas_binding CHECK (
                    (content_id IS NULL AND entity_id IS NULL)
                    OR (content_id IS NOT NULL AND entity_id IS NULL)
                    OR (content_id IS NULL AND entity_id IS NOT NULL)
                ),
                FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY(content_id) REFERENCES contents(id) ON DELETE CASCADE,
                FOREIGN KEY(entity_id) REFERENCES entities(id) ON DELETE CASCADE
            )
        ");

        DB::statement('CREATE INDEX seo_metas_site_id_index ON seo_metas(site_id)');
        DB::statement('CREATE INDEX seo_metas_content_id_index ON seo_metas(content_id)');
        DB::statement('CREATE INDEX seo_metas_entity_id_index ON seo_metas(entity_id)');
        DB::statement('CREATE INDEX seo_metas_canonical_index ON seo_metas(canonical)');

        DB::statement("
            CREATE UNIQUE INDEX sites_seo_meta_unique
            ON seo_metas(site_id)
            WHERE content_id IS NULL AND entity_id IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX content_seo_meta_unique
            ON seo_metas(site_id, content_id)
            WHERE content_id IS NOT NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX entity_seo_meta_unique
            ON seo_metas(site_id, entity_id)
            WHERE entity_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX entity_seo_meta_unique ON seo_metas');
            DB::statement('DROP INDEX content_seo_meta_unique ON seo_metas');
            DB::statement('DROP INDEX sites_seo_meta_unique ON seo_metas');
            DB::statement('DROP INDEX seo_metas_canonical_index ON seo_metas');
            DB::statement('DROP INDEX seo_metas_entity_id_index ON seo_metas');
            DB::statement('DROP INDEX seo_metas_content_id_index ON seo_metas');
            DB::statement('DROP INDEX seo_metas_site_id_index ON seo_metas');
            DB::statement('DROP TABLE seo_metas');
            return;
        }

        DB::statement('DROP INDEX IF EXISTS entity_seo_meta_unique');
        DB::statement('DROP INDEX IF EXISTS content_seo_meta_unique');
        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique');
        DB::statement('DROP INDEX IF EXISTS seo_metas_canonical_index');
        DB::statement('DROP INDEX IF EXISTS seo_metas_entity_id_index');
        DB::statement('DROP INDEX IF EXISTS seo_metas_content_id_index');
        DB::statement('DROP INDEX IF EXISTS seo_metas_site_id_index');
        DB::statement('DROP TABLE IF EXISTS seo_metas');
    }
};
