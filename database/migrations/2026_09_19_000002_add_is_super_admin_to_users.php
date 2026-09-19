<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户超管标记（P-STEP 03：Site Bootstrap Contract）。
 *
 * 安装器创建的首个管理员通过 is_super_admin 获得系统级授权
 * （SystemAuthorization::canCrossSite），取代原「按业务种子邮箱硬编码判定」，
 * 使任意邮箱的管理员都可由安装器引导为超管。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
