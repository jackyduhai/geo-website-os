<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create table with CHECK constraint directly (SQLite supports CHECK in CREATE TABLE)
        DB::statement("
            CREATE TABLE seo_metas (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                site_id INTEGER NOT NULL,
                content_id INTEGER NULL,
                entity_id INTEGER NULL,
                title VARCHAR NULL,
                description TEXT NULL,
                keywords TEXT NULL,
                canonical VARCHAR NULL,
                og_title VARCHAR NULL,
                og_description TEXT NULL,
                og_image_path VARCHAR NULL,
                og_type VARCHAR NOT NULL DEFAULT 'website',
                twitter_card VARCHAR NOT NULL DEFAULT 'summary_large_image',
                noindex TINYINT(1) NOT NULL DEFAULT 0,
                nofollow TINYINT(1) NOT NULL DEFAULT 0,
                robots TEXT NULL,
                schema_type VARCHAR NULL,
                metadata TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
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

        // Standard indexes
        DB::statement('CREATE INDEX seo_metas_site_id_index ON seo_metas(site_id)');
        DB::statement('CREATE INDEX seo_metas_content_id_index ON seo_metas(content_id)');
        DB::statement('CREATE INDEX seo_metas_entity_id_index ON seo_metas(entity_id)');
        DB::statement('CREATE INDEX seo_metas_canonical_index ON seo_metas(canonical)');

        // Partial unique indexes
        DB::statement("
            CREATE UNIQUE INDEX sites_seo_meta_unique
            ON seo_metas(site_id)
            WHERE content_id IS NULL AND entity_id IS NULL;
        ");

        DB::statement("
            CREATE UNIQUE INDEX content_seo_meta_unique
            ON seo_metas(site_id, content_id)
            WHERE content_id IS NOT NULL;
        ");

        DB::statement("
            CREATE UNIQUE INDEX entity_seo_meta_unique
            ON seo_metas(site_id, entity_id)
            WHERE entity_id IS NOT NULL;
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entity_seo_meta_unique;');
        DB::statement('DROP INDEX IF EXISTS content_seo_meta_unique;');
        DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique;');
        DB::statement('DROP INDEX IF EXISTS seo_metas_canonical_index;');
        DB::statement('DROP INDEX IF EXISTS seo_metas_entity_id_index;');
        DB::statement('DROP INDEX IF EXISTS seo_metas_content_id_index;');
        DB::statement('DROP INDEX IF EXISTS seo_metas_site_id_index;');
        DB::statement('DROP TABLE IF EXISTS seo_metas;');
    }
};
