<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4-B: 为3张空表增加 site_id（Site 隔离）。
 *
 * 表：content_revisions, inquiries, sync_logs
 * 策略：SQLite table rebuild（因需同时建立 NOT NULL + FK，ALTER TABLE ADD COLUMN
 *       无法在无 DEFAULT 的情况下加 NOT NULL 列）。
 * 数据：3张表当前均为0行，无历史数据回填。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildContentRevisions();
        $this->rebuildInquiries();
        $this->rebuildSyncLogs();
    }

    public function down(): void
    {
        $this->rollbackContentRevisions();
        $this->rollbackInquiries();
        $this->rollbackSyncLogs();
    }

    // ── content_revisions ──────────────────────────────────────────

    private function rebuildContentRevisions(): void
    {
        Schema::create('content_revisions_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            $table->index(['site_id', 'content_id', 'created_at']);
        });

        DB::statement('INSERT INTO content_revisions_new (id, site_id, content_id, user_id, note, snapshot, created_at, updated_at)
                        SELECT id, 1, content_id, user_id, note, snapshot, created_at, updated_at FROM content_revisions');

        Schema::drop('content_revisions');
        Schema::rename('content_revisions_new', 'content_revisions');
    }

    private function rollbackContentRevisions(): void
    {
        Schema::create('content_revisions_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            $table->index(['content_id', 'created_at']);
        });

        DB::statement('INSERT INTO content_revisions_old (id, content_id, user_id, note, snapshot, created_at, updated_at)
                        SELECT id, content_id, user_id, note, snapshot, created_at, updated_at FROM content_revisions');

        Schema::drop('content_revisions');
        Schema::rename('content_revisions_old', 'content_revisions');
    }

    // ── inquiries ──────────────────────────────────────────────────

    private function rebuildInquiries(): void
    {
        Schema::create('inquiries_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('company')->nullable();
            $table->string('demand_type')->default('其他咨询');
            $table->string('monthly_use')->nullable();
            $table->text('message');
            $table->string('source_page')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('new');
            $table->text('handle_note')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->timestamps();
            $table->string('landing_url')->nullable();
            $table->string('referer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('device_type')->nullable();

            $table->index(['site_id', 'status', 'created_at']);
        });

        DB::statement('INSERT INTO inquiries_new
                        SELECT id, 1, name, phone, company, demand_type, monthly_use, message,
                               source_page, ip, user_agent, status, handle_note, handled_at,
                               created_at, updated_at, landing_url, referer, utm_source, utm_medium,
                               utm_campaign, utm_term, utm_content, device_type
                        FROM inquiries');

        Schema::drop('inquiries');
        Schema::rename('inquiries_new', 'inquiries');
    }

    private function rollbackInquiries(): void
    {
        Schema::create('inquiries_old', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('company')->nullable();
            $table->string('demand_type')->default('其他咨询');
            $table->string('monthly_use')->nullable();
            $table->text('message');
            $table->string('source_page')->nullable();
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('new');
            $table->text('handle_note')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->timestamps();
            $table->string('landing_url')->nullable();
            $table->string('referer')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('device_type')->nullable();

            $table->index(['status', 'created_at']);
        });

        DB::statement('INSERT INTO inquiries_old
                        SELECT id, name, phone, company, demand_type, monthly_use, message,
                               source_page, ip, user_agent, status, handle_note, handled_at,
                               created_at, updated_at, landing_url, referer, utm_source, utm_medium,
                               utm_campaign, utm_term, utm_content, device_type
                        FROM inquiries');

        Schema::drop('inquiries');
        Schema::rename('inquiries_old', 'inquiries');
    }

    // ── sync_logs ──────────────────────────────────────────────────

    private function rebuildSyncLogs(): void
    {
        Schema::create('sync_logs_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('direction');
            $table->string('action')->nullable();
            $table->string('external_id')->nullable();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('status')->default('ok');
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'external_id']);
            $table->index(['site_id', 'direction', 'status']);
        });

        DB::statement('INSERT INTO sync_logs_new (id, site_id, direction, action, external_id, content_id, status, message, payload, created_at, updated_at)
                        SELECT id, 1, direction, action, external_id, content_id, status, message, payload, created_at, updated_at FROM sync_logs');

        Schema::drop('sync_logs');
        Schema::rename('sync_logs_new', 'sync_logs');
    }

    private function rollbackSyncLogs(): void
    {
        Schema::create('sync_logs_old', function (Blueprint $table) {
            $table->id();
            $table->string('direction');
            $table->string('action')->nullable();
            $table->string('external_id')->nullable();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('status')->default('ok');
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('external_id');
            $table->index(['direction', 'status']);
        });

        DB::statement('INSERT INTO sync_logs_old (id, direction, action, external_id, content_id, status, message, payload, created_at, updated_at)
                        SELECT id, direction, action, external_id, content_id, status, message, payload, created_at, updated_at FROM sync_logs');

        Schema::drop('sync_logs');
        Schema::rename('sync_logs_old', 'sync_logs');
    }
};
