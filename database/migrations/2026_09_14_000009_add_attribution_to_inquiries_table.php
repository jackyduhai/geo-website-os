<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 留言来源归因：首次落地页、外部 Referer、UTM、设备类型。
 * 用户无需填写，由中间件在首次访问时写入 session，表单以隐藏字段带回，
 * 设备类型以服务端 UA 解析为准（不信任前端传值）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('landing_url', 255)->nullable()->after('source_page');  // 首次落地页
            $table->string('referer', 255)->nullable()->after('landing_url');      // 外部来源页
            $table->string('utm_source', 64)->nullable()->after('referer');
            $table->string('utm_medium', 64)->nullable()->after('utm_source');
            $table->string('utm_campaign', 96)->nullable()->after('utm_medium');
            $table->string('utm_term', 64)->nullable()->after('utm_campaign');
            $table->string('utm_content', 64)->nullable()->after('utm_term');
            $table->string('device_type', 16)->nullable()->after('utm_content');   // mobile/tablet/desktop
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn([
                'landing_url', 'referer', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_term', 'utm_content', 'device_type',
            ]);
        });
    }
};
