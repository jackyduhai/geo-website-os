<?php

use App\Support\MigrationPortability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4-D: 为 5 张核心表增加 site_id，并将唯一约束改为站点范围。
 *
 * - contents: UNIQUE(slug) -> UNIQUE(site_id, slug), UNIQUE(slot) -> UNIQUE(site_id, slot)
 * - categories: UNIQUE(slug) -> UNIQUE(site_id, slug)
 * - groups: UNIQUE(category_id, slug) -> UNIQUE(site_id, category_id, slug)
 * - facts: UNIQUE(key) -> UNIQUE(site_id, key)
 * - settings: UNIQUE(key) -> UNIQUE(site_id, key)
 *
 * 采用 table rebuild（唯一约束变更所需）。全程使用 Schema Blueprint 与
 * MigrationPortability，保证 MySQL / PostgreSQL / SQLite 可移植；不手写方言 DDL。
 * 既有数据 site_id 回填为默认站点 1。
 *
 * 索引时机：所有索引（含 unique）都在 drop 旧表 + rename 之后再建。否则
 * PostgreSQL 中索引名是 schema 级的，新表与旧表同名索引会冲突（42P07）。
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPortability::disableForeignKeyChecks();

        $this->rebuildContents();
        $this->rebuildCategories();
        $this->rebuildGroups();
        $this->rebuildFacts();
        $this->rebuildSettings();

        MigrationPortability::enableForeignKeyChecks();

        $violations = MigrationPortability::foreignKeyViolations();
        if (count($violations) > 0) {
            throw new \RuntimeException('FK violations after migration: ' . json_encode($violations));
        }
    }

    public function down(): void
    {
        MigrationPortability::disableForeignKeyChecks();

        $this->rollbackContents();
        $this->rollbackCategories();
        $this->rollbackGroups();
        $this->rollbackFacts();
        $this->rollbackSettings();

        MigrationPortability::enableForeignKeyChecks();

        $violations = MigrationPortability::foreignKeyViolations();
        if (count($violations) > 0) {
            throw new \RuntimeException('FK violations after rollback: ' . json_encode($violations));
        }
    }

    // ── contents ───────────────────────────────────────────────────

    private function contentsColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
        $table->string('type')->default('article');
        $table->unsignedBigInteger('category_id')->nullable();
        $table->unsignedBigInteger('group_id')->nullable();
        $table->string('title');
        $table->string('slug');
        $table->text('summary')->nullable();
        $table->unsignedBigInteger('cover_id')->nullable();
        $table->text('body')->nullable();
        $table->string('status')->default('draft');
        $table->timestamp('published_at')->nullable();
        $table->text('geo_conclusion')->nullable();
        $table->text('geo_explanation')->nullable();
        $table->text('geo_evidence')->nullable();
        $table->text('geo_boundary')->nullable();
        $table->text('geo_faq')->nullable();
        $table->text('geo_key_facts')->nullable();
        $table->text('fact_refs')->nullable();
        $table->string('owner')->nullable();
        $table->date('reviewed_at')->nullable();
        $table->date('review_due')->nullable();
        $table->string('source_note')->nullable();
        $table->string('seo_title')->nullable();
        $table->string('seo_desc')->nullable();
        $table->string('canonical')->nullable();
        $table->unsignedBigInteger('og_image_id')->nullable();
        $table->boolean('noindex')->default(false);
        $table->string('external_id')->nullable();
        $table->string('external_source')->nullable();
        $table->timestamp('synced_at')->nullable();
        $table->string('content_hash')->nullable();
        $table->boolean('lock_manual')->default(false);
        $table->string('slot')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function contentsIndexes(Blueprint $table): void
    {
        $table->unique(['site_id', 'slug'], 'contents_site_id_slug_unique');
        $table->unique(['site_id', 'slot'], 'contents_site_id_slot_unique');
        $table->index('external_id', 'contents_external_id_index');
        $table->index(['category_id', 'group_id'], 'contents_category_id_group_id_index');
        $table->index(['type', 'category_id'], 'contents_type_category_id_index');
        $table->index(['status', 'published_at'], 'contents_status_published_at_index');
    }

    private function rebuildContents(): void
    {
        Schema::create('contents_new', function (Blueprint $table) {
            $this->contentsColumns($table);
        });

        DB::statement('INSERT INTO contents_new
            (site_id, id, type, category_id, group_id, title, slug, summary, cover_id, body, status,
             published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary, geo_faq,
             geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note, seo_title, seo_desc,
             canonical, og_image_id, noindex, external_id, external_source, synced_at, content_hash,
             lock_manual, slot, created_at, updated_at, deleted_at)
            SELECT 1, id, type, category_id, group_id, title, slug, summary, cover_id, body, status,
             published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary, geo_faq,
             geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note, seo_title, seo_desc,
             canonical, og_image_id, noindex, external_id, external_source, synced_at, content_hash,
             lock_manual, slot, created_at, updated_at, deleted_at
            FROM contents');

        $incomingFks = MigrationPortability::dropReferencingForeignKeys('contents');
        Schema::drop('contents');
        Schema::rename('contents_new', 'contents');
        MigrationPortability::restoreReferencingForeignKeys($incomingFks);
        MigrationPortability::resetAutoIncrement('contents');

        Schema::table('contents', function (Blueprint $table) {
            $this->contentsIndexes($table);
        });
    }

    private function rollbackContents(): void
    {
        Schema::create('contents_old', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('article');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('cover_id')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->text('geo_conclusion')->nullable();
            $table->text('geo_explanation')->nullable();
            $table->text('geo_evidence')->nullable();
            $table->text('geo_boundary')->nullable();
            $table->text('geo_faq')->nullable();
            $table->text('geo_key_facts')->nullable();
            $table->text('fact_refs')->nullable();
            $table->string('owner')->nullable();
            $table->date('reviewed_at')->nullable();
            $table->date('review_due')->nullable();
            $table->string('source_note')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_desc')->nullable();
            $table->string('canonical')->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('external_id')->nullable();
            $table->string('external_source')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('content_hash')->nullable();
            $table->boolean('lock_manual')->default(false);
            $table->string('slot')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('INSERT INTO contents_old
            (id, type, category_id, group_id, title, slug, summary, cover_id, body, status,
             published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary, geo_faq,
             geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note, seo_title, seo_desc,
             canonical, og_image_id, noindex, external_id, external_source, synced_at, content_hash,
             lock_manual, slot, created_at, updated_at, deleted_at)
            SELECT id, type, category_id, group_id, title, slug, summary, cover_id, body, status,
             published_at, geo_conclusion, geo_explanation, geo_evidence, geo_boundary, geo_faq,
             geo_key_facts, fact_refs, owner, reviewed_at, review_due, source_note, seo_title, seo_desc,
             canonical, og_image_id, noindex, external_id, external_source, synced_at, content_hash,
             lock_manual, slot, created_at, updated_at, deleted_at
            FROM contents');

        Schema::drop('contents');
        Schema::rename('contents_old', 'contents');

        // 回滚后恢复旧形态索引（名称由 Laravel 自动生成，旧表原索引随 drop 已删除）。
        Schema::table('contents', function (Blueprint $table) {
            $table->unique('slug');
            $table->unique('slot');
            $table->index('external_id');
            $table->index(['category_id', 'group_id']);
            $table->index(['type', 'category_id']);
            $table->index(['status', 'published_at']);
        });
    }

    // ── categories ─────────────────────────────────────────────────

    private function rebuildCategories(): void
    {
        Schema::create('categories_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('list');
            $table->string('template')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_nav')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_desc')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO categories_new
            (site_id, id, parent_id, name, slug, type, template, description, icon, sort,
             is_nav, is_active, seo_title, seo_desc, created_at, updated_at)
            SELECT 1, id, parent_id, name, slug, type, template, description, icon, sort,
             is_nav, is_active, seo_title, seo_desc, created_at, updated_at
            FROM categories');

        $incomingFks = MigrationPortability::dropReferencingForeignKeys('categories');
        Schema::drop('categories');
        Schema::rename('categories_new', 'categories');
        MigrationPortability::restoreReferencingForeignKeys($incomingFks);
        MigrationPortability::resetAutoIncrement('categories');

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['site_id', 'slug'], 'categories_site_id_slug_unique');
            $table->index(['is_nav', 'is_active'], 'categories_is_nav_is_active_index');
            $table->index(['parent_id', 'sort'], 'categories_parent_id_sort_index');
        });
    }

    private function rollbackCategories(): void
    {
        Schema::create('categories_old', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->default('list');
            $table->string('template')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_nav')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_desc')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO categories_old
            (id, parent_id, name, slug, type, template, description, icon, sort,
             is_nav, is_active, seo_title, seo_desc, created_at, updated_at)
            SELECT id, parent_id, name, slug, type, template, description, icon, sort,
             is_nav, is_active, seo_title, seo_desc, created_at, updated_at
            FROM categories');

        Schema::drop('categories');
        Schema::rename('categories_old', 'categories');

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('slug');
            $table->index(['is_nav', 'is_active']);
            $table->index(['parent_id', 'sort']);
        });
    }

    // ── groups ─────────────────────────────────────────────────────

    private function rebuildGroups(): void
    {
        $groupsTable = MigrationPortability::quote('groups');
        Schema::create('groups_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("INSERT INTO groups_new
            (site_id, id, category_id, name, slug, description, sort, is_active, created_at, updated_at)
            SELECT 1, id, category_id, name, slug, description, sort, is_active, created_at, updated_at
            FROM {$groupsTable}");

        $incomingFks = MigrationPortability::dropReferencingForeignKeys('groups');
        Schema::drop('groups');
        Schema::rename('groups_new', 'groups');
        MigrationPortability::restoreReferencingForeignKeys($incomingFks);
        MigrationPortability::resetAutoIncrement('groups');

        Schema::table('groups', function (Blueprint $table) {
            $table->unique(['site_id', 'category_id', 'slug'], 'groups_site_category_slug_unique');
            $table->index(['category_id', 'sort'], 'groups_category_id_sort_index');
        });
    }

    private function rollbackGroups(): void
    {
        $groupsTable = MigrationPortability::quote('groups');
        Schema::create('groups_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("INSERT INTO groups_old
            (id, category_id, name, slug, description, sort, is_active, created_at, updated_at)
            SELECT id, category_id, name, slug, description, sort, is_active, created_at, updated_at
            FROM {$groupsTable}");

        Schema::drop('groups');
        Schema::rename('groups_old', 'groups');

        Schema::table('groups', function (Blueprint $table) {
            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'sort']);
        });
    }

    // ── facts ──────────────────────────────────────────────────────

    private function rebuildFacts(): void
    {
        $key = MigrationPortability::quote('key');
        $group = MigrationPortability::quote('group');
        Schema::create('facts_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('key');
            $table->string('label');
            $table->text('value');
            $table->string('group')->nullable();
            $table->string('source')->nullable();
            $table->string('owner')->nullable();
            $table->date('reviewed_at')->nullable();
            $table->date('review_due')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::statement("INSERT INTO facts_new
            (site_id, id, {$key}, label, value, {$group}, source, owner, reviewed_at, review_due,
             is_public, sort, created_at, updated_at)
            SELECT 1, id, {$key}, label, value, {$group}, source, owner, reviewed_at, review_due,
             is_public, sort, created_at, updated_at
            FROM facts");

        $incomingFks = MigrationPortability::dropReferencingForeignKeys('facts');
        Schema::drop('facts');
        Schema::rename('facts_new', 'facts');
        MigrationPortability::restoreReferencingForeignKeys($incomingFks);
        MigrationPortability::resetAutoIncrement('facts');

        Schema::table('facts', function (Blueprint $table) {
            $table->unique(['site_id', 'key'], 'facts_site_id_key_unique');
            $table->index('review_due', 'facts_review_due_index');
            $table->index(['group', 'sort'], 'facts_group_sort_index');
        });
    }

    private function rollbackFacts(): void
    {
        $key = MigrationPortability::quote('key');
        $group = MigrationPortability::quote('group');
        Schema::create('facts_old', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('label');
            $table->text('value');
            $table->string('group')->nullable();
            $table->string('source')->nullable();
            $table->string('owner')->nullable();
            $table->date('reviewed_at')->nullable();
            $table->date('review_due')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::statement("INSERT INTO facts_old
            (id, {$key}, label, value, {$group}, source, owner, reviewed_at, review_due,
             is_public, sort, created_at, updated_at)
            SELECT id, {$key}, label, value, {$group}, source, owner, reviewed_at, review_due,
             is_public, sort, created_at, updated_at
            FROM facts");

        Schema::drop('facts');
        Schema::rename('facts_old', 'facts');

        Schema::table('facts', function (Blueprint $table) {
            $table->unique('key');
            $table->index('review_due');
            $table->index(['group', 'sort']);
        });
    }

    // ── settings ───────────────────────────────────────────────────

    private function rebuildSettings(): void
    {
        $key = MigrationPortability::quote('key');
        $group = MigrationPortability::quote('group');
        Schema::create('settings_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->string('type')->default('text');
            $table->text('hint')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::statement("INSERT INTO settings_new
            (site_id, id, {$key}, value, {$group}, label, type, hint, sort, created_at, updated_at)
            SELECT 1, id, {$key}, value, {$group}, label, type, hint, sort, created_at, updated_at
            FROM settings");

        $incomingFks = MigrationPortability::dropReferencingForeignKeys('settings');
        Schema::drop('settings');
        Schema::rename('settings_new', 'settings');
        MigrationPortability::restoreReferencingForeignKeys($incomingFks);
        MigrationPortability::resetAutoIncrement('settings');

        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['site_id', 'key'], 'settings_site_id_key_unique');
            $table->index(['group', 'sort'], 'settings_group_sort_index');
        });
    }

    private function rollbackSettings(): void
    {
        $key = MigrationPortability::quote('key');
        $group = MigrationPortability::quote('group');
        Schema::create('settings_old', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->json('value')->nullable();
            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->string('type')->default('text');
            $table->text('hint')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::statement("INSERT INTO settings_old
            (id, {$key}, value, {$group}, label, type, hint, sort, created_at, updated_at)
            SELECT id, {$key}, value, {$group}, label, type, hint, sort, created_at, updated_at
            FROM settings");

        Schema::drop('settings');
        Schema::rename('settings_old', 'settings');

        Schema::table('settings', function (Blueprint $table) {
            $table->unique('key');
            $table->index(['group', 'sort']);
        });
    }
};
