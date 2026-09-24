<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-2b：pages 增加系统页标识。
 * --------------------------------------------------
 * is_system / system_key 标识由安装器注入、与固定路由绑定的系统页（产品总览 /
 * 场景总览 / 知识总览 / About×3 / 工厂 / 合作 / 联系）。系统页提供页面身份、
 * SEO 与模板绑定；业务事实仍来自 Site / Setting / Entity / Content，Page 不复制。
 *
 * system_key 每站每语言唯一（partial index，NULL 不参与）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_home');
            $table->string('system_key', 40)->nullable()->after('is_system');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pages_site_systemkey_locale_unique ON pages '
            . '(site_id, system_key, locale) WHERE system_key IS NOT NULL'
        );
        DB::statement('CREATE INDEX pages_system_index ON pages (site_id, is_system)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pages_site_systemkey_locale_unique');
        DB::statement('DROP INDEX IF EXISTS pages_system_index');

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'system_key']);
        });
    }
};
