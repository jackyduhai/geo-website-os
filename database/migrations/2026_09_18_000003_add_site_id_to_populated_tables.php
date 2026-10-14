<?php

use App\Support\MigrationPortability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4-C: 为已含数据的 4 张表增加 site_id（站点隔离）。
 *
 * 表：page_blocks, media, banners, audit_logs
 * 策略：table rebuild（需同时建立 NOT NULL + FK，ALTER ADD COLUMN 无法无 DEFAULT
 *       加 NOT NULL）。全程使用 Schema Blueprint，保证 MySQL / PostgreSQL / SQLite
 *       可移植；不手写方言 DDL（早期版本的 INTEGER PRIMARY KEY AUTOINCREMENT 仅
 *       SQLite 合法，会在 MySQL 上 1064 失败）。
 * 数据：既有行 site_id 回填为默认站点 1。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildPageBlocks();
        $this->rebuildMedia();
        $this->rebuildBanners();
        $this->rebuildAuditLogs();
    }

    public function down(): void
    {
        $this->rollbackPageBlocks();
        $this->rollbackMedia();
        $this->rollbackBanners();
        $this->rollbackAuditLogs();
    }

    // ── page_blocks ────────────────────────────────────────────────

    private function rebuildPageBlocks(): void
    {
        $limit = MigrationPortability::quote('limit');
        // 先建新表（仅 FK 约束），非 FK 索引在 drop 旧表 + rename 之后再建，
        // 否则 PostgreSQL 中索引名是 schema 级的，会与旧表同名索引冲突（42P07）。
        Schema::create('page_blocks_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('page');
            $table->string('type');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('content')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('limit')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("INSERT INTO page_blocks_new
            (site_id, id, page, type, title, subtitle, content, category_id, {$limit}, sort, is_active, created_at, updated_at)
            SELECT 1, id, page, type, title, subtitle, content, category_id, {$limit}, sort, is_active, created_at, updated_at
            FROM page_blocks");

        Schema::drop('page_blocks');
        Schema::rename('page_blocks_new', 'page_blocks');
        MigrationPortability::resetAutoIncrement('page_blocks');

        Schema::table('page_blocks', function (Blueprint $table) {
            $table->index(['page', 'is_active', 'sort'], 'page_blocks_page_is_active_sort_index');
        });
        MigrationPortability::ensureColumnIndexed('page_blocks', 'site_id', 'page_blocks_site_id_index');
    }

    private function rollbackPageBlocks(): void
    {
        $limit = MigrationPortability::quote('limit');
        Schema::create('page_blocks_old', function (Blueprint $table) {
            $table->id();
            $table->string('page');
            $table->string('type');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('content')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('limit')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['page', 'is_active', 'sort']);
        });

        DB::statement("INSERT INTO page_blocks_old
            (id, page, type, title, subtitle, content, category_id, {$limit}, sort, is_active, created_at, updated_at)
            SELECT id, page, type, title, subtitle, content, category_id, {$limit}, sort, is_active, created_at, updated_at
            FROM page_blocks");

        Schema::drop('page_blocks');
        Schema::rename('page_blocks_old', 'page_blocks');
    }

    // ── media ──────────────────────────────────────────────────────

    private function rebuildMedia(): void
    {
        Schema::create('media_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO media_new
            (site_id, id, disk, path, original_name, mime, size, width, height, alt, title, uploaded_by, created_at, updated_at)
            SELECT 1, id, disk, path, original_name, mime, size, width, height, alt, title, uploaded_by, created_at, updated_at
            FROM media');

        Schema::drop('media');
        Schema::rename('media_new', 'media');
        MigrationPortability::resetAutoIncrement('media');

        Schema::table('media', function (Blueprint $table) {
            $table->index('mime', 'media_mime_index');
        });
        MigrationPortability::ensureColumnIndexed('media', 'site_id', 'media_site_id_index');
    }

    private function rollbackMedia(): void
    {
        Schema::create('media_old', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('mime');
        });

        DB::statement('INSERT INTO media_old
            (id, disk, path, original_name, mime, size, width, height, alt, title, uploaded_by, created_at, updated_at)
            SELECT id, disk, path, original_name, mime, size, width, height, alt, title, uploaded_by, created_at, updated_at
            FROM media');

        Schema::drop('media');
        Schema::rename('media_old', 'media');
    }

    // ── banners ────────────────────────────────────────────────────

    private function rebuildBanners(): void
    {
        Schema::create('banners_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('position');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('link')->nullable();
            $table->string('link_text')->nullable();
            $table->unsignedBigInteger('image_id')->nullable();
            $table->unsignedInteger('target')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO banners_new
            (site_id, id, position, title, subtitle, link, link_text, image_id, target, sort, is_active, start_at, end_at, created_at, updated_at)
            SELECT 1, id, position, title, subtitle, link, link_text, image_id, target, sort, is_active, start_at, end_at, created_at, updated_at
            FROM banners');

        Schema::drop('banners');
        Schema::rename('banners_new', 'banners');
        MigrationPortability::resetAutoIncrement('banners');

        Schema::table('banners', function (Blueprint $table) {
            $table->index(['position', 'is_active', 'sort'], 'banners_position_is_active_sort_index');
        });
        MigrationPortability::ensureColumnIndexed('banners', 'site_id', 'banners_site_id_index');
    }

    private function rollbackBanners(): void
    {
        Schema::create('banners_old', function (Blueprint $table) {
            $table->id();
            $table->string('position');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('link')->nullable();
            $table->string('link_text')->nullable();
            $table->unsignedBigInteger('image_id')->nullable();
            $table->unsignedInteger('target')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['position', 'is_active', 'sort']);
        });

        DB::statement('INSERT INTO banners_old
            (id, position, title, subtitle, link, link_text, image_id, target, sort, is_active, start_at, end_at, created_at, updated_at)
            SELECT id, position, title, subtitle, link, link_text, image_id, target, sort, is_active, start_at, end_at, created_at, updated_at
            FROM banners');

        Schema::drop('banners');
        Schema::rename('banners_old', 'banners');
    }

    // ── audit_logs ─────────────────────────────────────────────────

    private function rebuildAuditLogs(): void
    {
        Schema::create('audit_logs_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('summary')->nullable();
            $table->text('detail')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO audit_logs_new
            (site_id, id, user_id, action, target_type, target_id, summary, detail, ip, created_at, updated_at)
            SELECT 1, id, user_id, action, target_type, target_id, summary, detail, ip, created_at, updated_at
            FROM audit_logs');

        Schema::drop('audit_logs');
        Schema::rename('audit_logs_new', 'audit_logs');
        MigrationPortability::resetAutoIncrement('audit_logs');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index(['target_type', 'target_id'], 'audit_logs_target_type_target_id_index');
        });
        MigrationPortability::ensureColumnIndexed('audit_logs', 'site_id', 'audit_logs_site_id_index');
    }

    private function rollbackAuditLogs(): void
    {
        Schema::create('audit_logs_old', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('summary')->nullable();
            $table->text('detail')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['target_type', 'target_id']);
        });

        DB::statement('INSERT INTO audit_logs_old
            (id, user_id, action, target_type, target_id, summary, detail, ip, created_at, updated_at)
            SELECT id, user_id, action, target_type, target_id, summary, detail, ip, created_at, updated_at
            FROM audit_logs');

        Schema::drop('audit_logs');
        Schema::rename('audit_logs_old', 'audit_logs');
    }
};
