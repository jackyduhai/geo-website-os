<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-7：media 增加 delete_failed_at（可重试持久状态）。
 *
 * 文件删除失败（部分文件仍存在且无法删除）时保留 DB 记录并置位该时间戳，
 * 供 media:retry-deletes 幂等重试；不伪装成删除成功。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'delete_failed_at')) {
                $table->timestamp('delete_failed_at')->nullable()->after('uploaded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'delete_failed_at')) {
                $table->dropColumn('delete_failed_at');
            }
        });
    }
};
