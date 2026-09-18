<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 栏目图标：存储统一图标库的 key（见 config/icons.php）。
 * 为空时前台按栏目 slug 回退到默认语义图标。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'icon')) {
            return; // 幂等：开发库可能已手动加过列
        }
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon', 40)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'icon')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('icon');
            });
        }
    }
};
