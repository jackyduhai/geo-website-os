<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人工锁定标记：锁定后 GEOFlow 推送不得覆盖，只能人工在后台修改。
 * 架构方案「人工优先级最高」的落点。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('lock_manual')->default(false)->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('lock_manual');
        });
    }
};
