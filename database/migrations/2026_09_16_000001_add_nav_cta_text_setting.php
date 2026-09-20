<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 新增「顶部/全站主 CTA 文案」设置项（基础信息组）。
 *
 * 此前主 CTA 按钮文案写死在 config(copy.nav.cta) 与若干 Blade 中，
 * 后台无法修改。现以设置项承接，留空回退 config 默认值，保证向后兼容。
 * 幂等：键已存在则不重复插入。
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'nav_cta_text')->exists();
        if (! $exists) {
            DB::table('settings')->insert([
                'key'       => 'nav_cta_text',
                'value'     => '获取产品方案',
                'group'     => 'general',
                'label'     => '主 CTA 按钮文案',
                'type'      => 'text',
                'hint'      => '顶部导航与首屏/产品/参数区主按钮文字，留空则用默认“获取产品方案”',
                'sort'      => 70,
                'created_at'=> now(),
                'updated_at'=> now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'nav_cta_text')->delete();
    }
};
