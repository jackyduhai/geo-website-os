<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * 站点设置种子
 *
 * theme 分组是「可自定义样式」的落点：主题色、字体、容器宽度等都在这里，
 * 后台改完即时生效，不需要改代码也不需要重新部署。
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ---------- 基础 ----------
            ['site_name',        'Example Food', 'general', '站点名称', 'text', '出现在导航与页脚', 10],
            ['site_short_name',  'Example', 'general', '站点简称', 'text', '', 20],
            ['site_slogan',      '中式Sample SnackSample Marinade与调理鸡肉 OEM 代工', 'general', '站点标语', 'text', '首页主标下方一行', 30],
            ['site_description', 'Example Food Co., Ltd.专注中式Sample Snack风味调味研发与生产，'
                . '主营Sample SnackSample Marinade、鸡架Sample Marinade、Sample Breading撒料与调理鸡肉制品，提供配方定制与 OEM/ODM 代工服务。',
                'general', '站点简介', 'textarea', '用于首页与默认 meta description', 40],
            ['icp_number',       '', 'general', 'ICP 备案号', 'text', '接入前填入，页脚展示', 50],
            ['police_number',    '', 'general', '公安备案号', 'text', '选填', 60],
            ['nav_cta_text',     '免费获取样品', 'general', '主 CTA 按钮文案', 'text', '顶部导航与首屏/产品/参数区主按钮文字，留空用默认', 70],

            // ---------- 主题（取自 logo：Example红 #D40F19 / Example绿 #07903B） ----------
            ['theme_primary',    '#D40F19', 'theme', '主色', 'color', '用于标题、按钮、强调（Example红）', 10],
            ['theme_primary_dark', '#AF0C15', 'theme', '主色（深）', 'color', '悬停态', 20],
            ['theme_accent',     '#07903B', 'theme', '辅色', 'color', '用于事实块、标签（Example绿）', 30],
            ['theme_bg',         '#FBFAF8', 'theme', '页面底色', 'color', '', 40],
            ['theme_surface',    '#FFFFFF', 'theme', '卡片底色', 'color', '', 50],
            ['theme_text',       '#1E1A17', 'theme', '正文色', 'color', '', 60],
            ['theme_text_muted', '#6F655C', 'theme', '次要文字色', 'color', '', 70],
            ['theme_radius',     '10', 'theme', '圆角（px）', 'number', '卡片与按钮圆角', 80],
            ['theme_container',  '1200', 'theme', '内容区最大宽度（px）', 'number', '', 90],
            ['theme_font',       '', 'theme', '自定义字体', 'text', '留空使用系统字体栈', 100],
            ['theme_custom_css', '', 'theme', '自定义 CSS', 'textarea', '追加到全站样式末尾', 110],

            // ---------- 联系方式 ----------
            ['contact_phone',    '400-000-0000', 'contact', '业务合作电话', 'text', '', 10],
            ['contact_mobile',   '', 'contact', '业务手机 / 微信同号', 'text', '选填，显示在联系页与页脚', 15],
            ['contact_email',    '', 'contact', '业务邮箱', 'text', '选填', 20],
            ['contact_address',  'Sample Province省Sample City市沈河区 Example Street 39', 'contact', '地址', 'text', '', 30],
            ['contact_hours',    '周一至周六 8:30 - 17:30', 'contact', '工作时间', 'text', '选填', 40],
            ['contact_map_url',  '', 'contact', '地图链接', 'text', '选填，高德/百度地图的地点分享链接，联系页展示「查看地图」', 50],
            ['contact_wechat_qr', '', 'contact', '微信二维码', 'image', '留空时使用系统内置二维码', 55],

            // ---------- SEO ----------
            ['seo_title_suffix', 'Example Food', 'seo', '标题后缀', 'text', '页面标题追加，用 - 连接', 10],
            ['seo_default_desc', 'Example Food Co., Ltd.，中式Sample SnackSample Marinade与鸡架Sample Marinade源头工厂，'
                . '厂区约 9,000 平方米，年产能约 8,000 吨，支持配方定制与 OEM/ODM 代工。',
                'seo', '默认描述', 'textarea', '', 20],
            ['seo_og_image',     '', 'seo', '默认分享图', 'image', '', 30],
            ['seo_robots_extra', '', 'seo', 'robots.txt 追加内容', 'textarea', '高级项，谨慎修改', 40],

            // ---------- GEO ----------
            ['geo_org_name',     'Example Food Co., Ltd.', 'geo', 'Organization 名称', 'text', 'JSON-LD 使用', 10],
            ['geo_org_en_name',  'Sample City Example Food Co., Ltd.', 'geo', 'Organization 英文名', 'text', '', 20],
            ['geo_org_logo',     '', 'geo', 'Organization Logo', 'image', '建议 512×512 以上', 30],
            ['geo_llms_enabled', '1', 'geo', '生成 llms.txt', 'bool', '', 40],
            ['geo_sitemap_enabled', '1', 'geo', '生成 sitemap.xml', 'bool', '', 50],

            // ---------- 文案话术（底部 CTA / 咨询表单 / 404 / 页脚 slogan；留空回退默认） ----------
            // 底部统一 CTA（全站浅灰收口条；工厂页为全站唯一变体）
            ['copy_bcta_title',            '先拿一份样品试试', 'copy', '底部 CTA · 标题', 'text', '留空恢复默认', 10],
            ['copy_bcta_desc',             '说清你的产品和口味方向，我们安排寄样。', 'copy', '底部 CTA · 说明', 'text', '留空恢复默认', 20],
            ['copy_bcta_primary',          '免费获取样品', 'copy', '底部 CTA · 主按钮', 'text', '留空恢复默认', 30],
            ['copy_bcta_secondary',        '获取定制方案', 'copy', '底部 CTA · 次按钮', 'text', '留空恢复默认', 40],
            ['copy_bcta_factory_primary',  '预约工厂参观', 'copy', '工厂页 CTA · 主按钮（变体）', 'text', '工厂页访客意向更高，默认引导来厂参观；留空恢复默认', 50],
            ['copy_bcta_factory_secondary', '免费获取样品', 'copy', '工厂页 CTA · 次按钮（变体）', 'text', '留空恢复默认', 60],
            // 全局咨询表单（首页 / 联系页 / 各 CTA 同一套）
            ['copy_form_name_label',       '称呼', 'copy', '表单 · 姓名字段标题', 'text', '留空恢复默认', 100],
            ['copy_form_name_placeholder', '怎么称呼您', 'copy', '表单 · 姓名输入提示', 'text', '留空恢复默认', 105],
            ['copy_form_name_error',       '请填写称呼', 'copy', '表单 · 姓名未填提示', 'text', '留空恢复默认', 110],
            ['copy_form_phone_label',      '联系电话', 'copy', '表单 · 电话字段标题', 'text', '留空恢复默认', 120],
            ['copy_form_phone_placeholder', '手机号或微信号', 'copy', '表单 · 电话输入提示', 'text', '留空恢复默认', 125],
            ['copy_form_phone_error',      '请填写联系电话', 'copy', '表单 · 电话未填提示', 'text', '留空恢复默认', 130],
            ['copy_form_phone_invalid',    '手机号或微信号格式不正确。', 'copy', '表单 · 电话格式错误提示', 'text', '留空恢复默认', 135],
            ['copy_form_type_label',       '我是哪一类客户', 'copy', '表单 · 客户类型标题', 'text', '留空恢复默认', 140],
            ['copy_form_type_placeholder', '请选择', 'copy', '表单 · 客户类型占位', 'text', '留空恢复默认', 145],
            ['copy_form_type_error',       '请选择客户类型', 'copy', '表单 · 未选客户类型提示', 'text', '留空恢复默认', 150],
            ['copy_form_type_options',     "Sample Snack创业小店\n夜市·烧烤摊\n连锁快餐·外卖\n中餐酒楼·食堂\n轻食·健身渠道\n卤味·烤串店\n经销商\n其他", 'copy', '表单 · 客户类型选项', 'textarea', '每行一个；保存后前台下拉与后端校验同步生效；留空恢复默认八类', 155],
            ['copy_form_note_label',       '需求简述（选填）', 'copy', '表单 · 需求字段标题', 'text', '留空恢复默认', 160],
            ['copy_form_note_placeholder', '想做什么口味方向？大概什么用量？', 'copy', '表单 · 需求输入提示', 'text', '留空恢复默认', 165],
            ['copy_form_submit',           '提交，我要样品', 'copy', '表单 · 提交按钮', 'text', '留空恢复默认', 170],
            ['copy_form_submitting',       '提交中…', 'copy', '表单 · 提交中状态', 'text', '留空恢复默认', 175],
            ['copy_form_privacy',          '我们只用你的信息联系你，不会用于其他用途。', 'copy', '表单 · 隐私说明', 'text', '留空恢复默认', 180],
            ['copy_form_success',          '已收到，我们会尽快联系你。', 'copy', '表单 · 提交成功提示', 'text', '留空恢复默认', 185],
            // 404
            ['copy_404_title',             '没有找到这个页面', 'copy', '404 · 标题', 'text', '留空恢复默认', 300],
            ['copy_404_desc',              '链接可能已失效，或地址输入有误。你可以从下面这些入口继续，或直接返回首页。', 'copy', '404 · 说明', 'textarea', '留空恢复默认', 310],
            ['copy_404_primary',           '返回首页', 'copy', '404 · 主按钮', 'text', '留空恢复默认', 320],
            ['copy_404_secondary',         '联系我们', 'copy', '404 · 次按钮', 'text', '留空恢复默认', 330],
            // 页脚
            ['copy_footer_slogan',         '用真诚心，做好每一份鸡肉', 'copy', '页脚 · 品牌标语', 'text', 'logo 下方一句话；留空恢复默认', 400],

            // ---------- 对接（本期预留，默认关闭） ----------
            ['sync_geoflow_enabled', '0', 'sync', '启用 GEOFlow 推送接收', 'bool', '关闭时接口仍返回但拒绝写入', 10],
            ['sync_geoflow_token',   '', 'sync', 'GEOFlow 接口 Token', 'text', '后台生成与轮换', 20],
            ['sync_geoflow_endpoint', '', 'sync', 'GEOFlow 上游地址', 'text', '主动拉取模式使用', 30],
            ['sync_pull_enabled',    '0', 'sync', '启用主动拉取', 'bool', '本期预留，默认关闭', 40],
        ];

        foreach ($rows as $r) {
            [$key, $value, $group, $label, $type, $hint, $sort] = $r;
            Setting::updateOrCreate(
                ['key' => $key],
                compact('value', 'group', 'label', 'type', 'hint') + ['sort' => $sort]
            );
        }

        Setting::flush();

        $this->command->info('站点设置：共 ' . Setting::count() . ' 项');
    }
}
