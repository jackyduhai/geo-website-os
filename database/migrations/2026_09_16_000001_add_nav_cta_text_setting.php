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
                // value 为 JSON 列（MySQL json / PostgreSQL jsonb）：经查询构造器写入需
                // 显式 JSON 编码；模型 Setting 因有 'value'=>'json' cast 会自动编码。
                // 否则 MySQL 报 3140 Invalid JSON text（SQLite 不校验，故此前未暴露）。
                'value'     => json_encode('获取产品方案', JSON_UNESCAPED_UNICODE),
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
