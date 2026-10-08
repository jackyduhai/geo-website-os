<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-1 Entity Lifecycle：
 *  - 新增 role（organization：site_subject / customer；其余 NULL）作为生命周期策略唯一权威；
 *  - 新增 deleted_at（SoftDeletes，可恢复）；
 *  - 用「active 时 = slug、软删时 = NULL」的生成列 + 唯一索引替代原 (site_id,type,slug)
 *    唯一约束：软删行不再占用 slug（MySQL 唯一索引允许多个 NULL），ACTIVE 行仍 DB 级唯一。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('role', 32)->nullable()->after('type');
            $table->timestamp('deleted_at')->nullable()->after('published_at');
            $table->index('role', 'entities_role_index');
        });

        // 回填 role：is_site_organization → site_subject；metadata.role=customer → customer。
        foreach (DB::table('entities')->get(['id', 'metadata']) as $row) {
            $metadata = json_decode((string) $row->metadata, true);
            $role = null;
            if (is_array($metadata)) {
                if (! empty($metadata['is_site_organization'])) {
                    $role = 'site_subject';
                } elseif (($metadata['role'] ?? null) === 'customer') {
                    $role = 'customer';
                }
            }
            if ($role !== null) {
                DB::table('entities')->where('id', $row->id)->update(['role' => $role]);
            }
        }

        // 替换唯一约束：原约束 (site_id,type,slug,locale) 会被软删行占用，
        // 改为生成列驱动的 ACTIVE 唯一（含 locale）。
        Schema::table('entities', function (Blueprint $table) {
            $table->dropUnique('entities_site_type_slug_locale_unique');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->string('active_slug', 128)->nullable()
                ->storedAs('(CASE WHEN deleted_at IS NULL THEN slug ELSE NULL END)');
            $table->unique(['site_id', 'type', 'locale', 'active_slug'], 'entities_active_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropUnique('entities_active_slug_unique');
            $table->dropColumn('active_slug');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->unique(['site_id', 'type', 'slug', 'locale'], 'entities_site_type_slug_locale_unique');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex('entities_role_index');
            $table->dropColumn(['role', 'deleted_at']);
        });
    }
};