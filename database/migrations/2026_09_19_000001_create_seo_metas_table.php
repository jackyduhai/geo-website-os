<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('keywords')->nullable();
            $table->string('canonical')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image_path')->nullable();
            $table->string('og_type')->default('website');
            $table->string('twitter_card')->default('summary_large_image');
            $table->boolean('noindex')->default(false);
            $table->boolean('nofollow')->default(false);
            $table->json('robots')->nullable();
            $table->string('schema_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('content_id');
            $table->index('entity_id');
            $table->index('canonical');
        });

        // SQLite CHECK constraint for three-state binding
        \DB::statement("
            CREATE TRIGGER seo_metas_binding_check BEFORE INSERT ON seo_metas
            BEGIN
                SELECT CASE
                    WHEN (NEW.content_id IS NULL AND NEW.entity_id IS NULL) THEN 0
                    WHEN (NEW.content_id IS NOT NULL AND NEW.entity_id IS NULL) THEN 0
                    WHEN (NEW.content_id IS NULL AND NEW.entity_id IS NOT NULL) THEN 0
                    ELSE RAISE(ABORT, 'Invalid seo_metas binding: content_id and entity_id cannot both be non-null')
                END;
            END;
        ");

        \DB::statement("
            CREATE TRIGGER seo_metas_binding_check_update BEFORE UPDATE ON seo_metas
            BEGIN
                SELECT CASE
                    WHEN (NEW.content_id IS NULL AND NEW.entity_id IS NULL) THEN 0
                    WHEN (NEW.content_id IS NOT NULL AND NEW.entity_id IS NULL) THEN 0
                    WHEN (NEW.content_id IS NULL AND NEW.entity_id IS NOT NULL) THEN 0
                    ELSE RAISE(ABORT, 'Invalid seo_metas binding: content_id and entity_id cannot both be non-null')
                END;
            END;
        ");

        // Partial unique indexes
        \DB::statement("
            CREATE UNIQUE INDEX sites_seo_meta_unique
            ON seo_metas(site_id)
            WHERE content_id IS NULL AND entity_id IS NULL;
        ");

        \DB::statement("
            CREATE UNIQUE INDEX content_seo_meta_unique
            ON seo_metas(site_id, content_id)
            WHERE content_id IS NOT NULL;
        ");

        \DB::statement("
            CREATE UNIQUE INDEX entity_seo_meta_unique
            ON seo_metas(site_id, entity_id)
            WHERE entity_id IS NOT NULL;
        ");
    }

    public function down(): void
    {
        \DB::statement('DROP INDEX IF EXISTS entity_seo_meta_unique;');
        \DB::statement('DROP INDEX IF EXISTS content_seo_meta_unique;');
        \DB::statement('DROP INDEX IF EXISTS sites_seo_meta_unique;');
        \DB::statement('DROP TRIGGER IF EXISTS seo_metas_binding_check_update;');
        \DB::statement('DROP TRIGGER IF EXISTS seo_metas_binding_check;');
        Schema::dropIfExists('seo_metas');
    }
};
