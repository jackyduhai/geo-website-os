<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 为 sites.is_default 添加部分唯一索引
 * 保证数据库层面只有一个 default site
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite 支持 partial index：只有 is_default = 1 的行才参与唯一约束
        DB::statement('CREATE UNIQUE INDEX sites_is_default_unique ON sites(is_default) WHERE is_default = 1');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_is_default_unique');
    }
};
