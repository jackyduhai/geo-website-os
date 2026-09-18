<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 导航菜单增加 key：非空时表示对「固定导航栏目」（config/copy.nav.menu 派生）
 * 的覆盖记录（改名 / 隐藏 / 排序）；为空时是后台追加的自定义菜单项（既有行为）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('key', 80)->nullable()->after('id');
        });

        // 部分 SQLite 版本对回调内加索引支持不一致，单独建立唯一索引
        Schema::table('menus', function (Blueprint $table) {
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn('key');
        });
    }
};
