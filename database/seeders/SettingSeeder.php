<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Facts;
use Illuminate\Database\Seeder;

/**
 * 站点设置的「演示行业示例」覆盖层（Demo / Example）。
 *
 * 仅由 DemoSeeder 在演示 / 开发 / 测试环境显式装载，geo:install 不调用本 Seeder。
 * 本 Seeder 先调用 DefaultSettingSeeder 落齐产品中性默认（保证字段完整），再把
 * 工业材料行业的演示话术 / 简介覆盖上去，便于开箱看到一个完整示例站点。
 *
 * 这些都是虚构、行业中性的 Example 内容（工业材料是通用行业，不指向任何具体客户）；
 * 联系方式、Token 等仍保持空值。已 RETIRE 的键（site_short_name / site_slogan /
 * sync_geoflow_endpoint / sync_pull_enabled）不在此重建。
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // 1) 产品中性默认（七组完整字段 + 中性默认值）
        $this->call(DefaultSettingSeeder::class);

        // 2) 演示行业覆盖（只改 value，字段定义沿用 DefaultSettingSeeder）
        $company = Facts::company();
        $siteName = $company['name'] ?? config('app.name');
        $enName = $company['name_en'] ?? '';

        $overrides = [
            // 基础 / 导航
            'site_name'        => $siteName,
            'site_supported_locales' => ['zh-CN', 'en'],
            'site_description' => '示例制造有限公司专注工业涂料、结构胶粘剂与功能性助剂的研发与生产，'
                . '提供从选型、打样到稳定供货的产品与定制化解决方案。',
            'nav_cta_text'     => '获取产品方案',
            'contact_hours'    => '周一至周五 9:00 - 18:00',
            'contact_hours_en' => 'Monday to Friday 9:00 - 18:00',

            // SEO / GEO
            'seo_title_suffix' => $siteName,
            'seo_default_desc' => '示例制造有限公司，工业涂料、结构胶粘剂与功能性助剂研发生产商，'
                . '支持选型、打样与定制化解决方案，按规格参数稳定供货。',
            'seo_default_en_desc' => 'Example Manufacturing Co., Ltd., a developer and manufacturer of '
                . 'industrial coatings, structural adhesives and functional additives, offering product '
                . 'selection, sampling and customized solutions, with stable supply to specification.',
            'geo_org_name'     => $siteName,
            'geo_org_en_name'  => $enName,

            // 底部统一 CTA
            'copy_bcta_title'             => '先拿一份产品资料',
            'copy_bcta_desc'              => '说清你的产品需求与规格参数，我们安排技术对接。',
            'copy_bcta_primary'           => '获取产品方案',
            'copy_bcta_secondary'         => '预约技术沟通',
            'copy_bcta_factory_primary'   => '预约工厂参观',
            'copy_bcta_factory_secondary' => '获取产品方案',

            // 全局咨询表单
            'copy_form_name_label'        => '称呼',
            'copy_form_name_placeholder'  => '怎么称呼您',
            'copy_form_name_error'        => '请填写称呼',
            'copy_form_phone_label'       => '联系电话',
            'copy_form_phone_placeholder' => '手机号或微信号',
            'copy_form_phone_error'       => '请填写联系电话',
            'copy_form_phone_invalid'     => '手机号或微信号格式不正确。',
            'copy_form_type_label'        => '我是哪一类客户',
            'copy_form_type_placeholder'  => '请选择',
            'copy_form_type_error'        => '请选择客户类型',
            'copy_form_type_options'      => "企业客户\n工程客户\n品牌客户\n渠道伙伴\n个人客户\n其他",
            'copy_form_note_label'        => '需求简述（选填）',
            'copy_form_note_placeholder'  => '需要什么产品？大概规格与用量？',
            'copy_form_submit'            => '提交需求',
            'copy_form_submitting'        => '提交中…',
            'copy_form_privacy'           => '我们只用你的信息联系你，不会用于其他用途。',
            'copy_form_success'           => '已收到，我们会尽快联系你。',

            // 404
            'copy_404_title'     => '没有找到这个页面',
            'copy_404_desc'      => '链接可能已失效，或地址输入有误。你可以从下面这些入口继续，或直接返回首页。',
            'copy_404_primary'   => '返回首页',
            'copy_404_secondary' => '联系我们',

            // 页脚
            'copy_footer_slogan' => '以稳定品质，服务每一次制造',
        ];

        foreach ($overrides as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Setting::flush();

        $this->command->info('站点设置（含演示示例）：共 ' . Setting::count() . ' 项');
    }
}
