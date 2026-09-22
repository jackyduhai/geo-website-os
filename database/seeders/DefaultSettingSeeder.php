<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * 产品级站点设置默认值（GEO Website OS Core Runtime）。
 *
 * 与仅用于演示的 SettingSeeder 不同，本 Seeder 提供的是「开箱即用的产品中性默认」：
 *   - 由安装器 geo:install 在创建默认站点后调用，保证全新生产安装后后台七个设置分组
 *     都有完整字段可编辑（此前字段定义只存在于 Demo SettingSeeder，fresh install 后七组全空）；
 *   - 联系方式 / 对接 Token / 自定义代码 / 文案覆盖默认留空，前台按空值整块隐藏或回退 config；
 *   - 主题 Token 取 GEO Website OS Design System 中性默认；GEO 产出开关默认开启；
 *   - 不含任何具体客户或特定行业的业务内容。
 *
 * 幂等：按 (site_id, key) updateOrCreate，可重复执行；不创建已标记 RETIRE 的键
 * （见 SettingController::RETIRED：site_short_name / site_slogan /
 * sync_geoflow_endpoint / sync_pull_enabled）。
 *
 * 演示行业示例由 SettingSeeder 在本 Seeder 之上做覆盖，二者不要耦合业务数据。
 */
class DefaultSettingSeeder extends Seeder
{
    public function run(): void
    {
        $appName = (string) config('app.name');

        // TD-12：Site.name 是站点显示名唯一权威，settings.site_name 仅为镜像。
        // 这里跟随当前 / 默认站点名，而不是独立硬编码 APP_NAME，以免覆盖
        // geo:install --site-name 的自定义名或后台改名；站点名缺失时才回退 APP_NAME。
        $siteName = \App\Support\SiteContext::currentSite()?->name
            ?: \App\Models\Site::default()?->name
            ?: $appName;
        $siteName = trim((string) $siteName) !== '' ? $siteName : $appName;

        $rows = [
            // ---------- 基础信息 ----------
            ['site_name',        $siteName, 'general', '站点名称', 'text', '出现在导航与页脚', 10],
            ['site_description', '',       'general', '站点简介', 'textarea', '用于首页与默认 meta description', 40],
            ['icp_number',       '',       'general', 'ICP 备案号', 'text', '接入前填入，页脚展示', 50],
            ['police_number',    '',       'general', '公安备案号', 'text', '选填，页脚展示', 60],
            ['nav_cta_text',     '',       'general', '主 CTA 按钮文案', 'text', '顶部导航与首屏主按钮文字，留空用默认', 70],

            // ---------- 主题（Design System 中性默认：品牌蓝 #2563EB / 辅助绿 #0E9F6E） ----------
            ['theme_primary',      '#2563EB', 'theme', '主色', 'color', '用于标题、按钮、强调（品牌蓝）', 10],
            ['theme_primary_dark', '#1D4ED8', 'theme', '主色（深）', 'color', '悬停态', 20],
            ['theme_accent',       '#0E9F6E', 'theme', '辅色', 'color', '用于事实块、标签（辅助绿）', 30],
            ['theme_bg',           '#F8FAFC', 'theme', '页面底色', 'color', '', 40],
            ['theme_surface',      '#FFFFFF', 'theme', '卡片底色', 'color', '', 50],
            ['theme_text',         '#1F2937', 'theme', '正文色', 'color', '', 60],
            ['theme_text_muted',   '#6B7280', 'theme', '次要文字色', 'color', '', 70],
            ['theme_radius',       '10',   'theme', '圆角（px）', 'number', '卡片与按钮圆角，0–48', 80],
            ['theme_container',    '1200', 'theme', '内容区最大宽度（px）', 'number', '800–2400', 90],
            ['theme_font',         '',     'theme', '自定义字体', 'text', '留空使用系统字体栈', 100],
            ['theme_custom_css',   '',     'theme', '自定义 CSS', 'textarea', '追加到全站样式末尾；仅在确认可信时填写', 110],

            // ---------- 联系方式（默认留空，前台按空值整块隐藏） ----------
            ['contact_phone',     '', 'contact', '业务合作电话', 'text', '', 10],
            ['contact_mobile',    '', 'contact', '业务手机 / 微信同号', 'text', '选填，显示在联系页与页脚', 15],
            ['contact_email',     '', 'contact', '业务邮箱', 'text', '选填，需为合法邮箱', 20],
            ['contact_address',   '', 'contact', '地址', 'text', '', 30],
            ['contact_hours',     '', 'contact', '工作时间', 'text', '选填', 40],
            ['contact_map_url',   '', 'contact', '地图链接', 'text', '选填，地图地点分享链接，联系页展示「查看地图」', 50],
            ['contact_wechat_qr', '', 'contact', '微信二维码', 'image', '留空则不展示二维码', 55],

            // ---------- SEO ----------
            ['seo_title_suffix', $appName, 'seo', '标题后缀', 'text', '页面标题追加，用 - 连接', 10],
            ['seo_default_desc', '',       'seo', '默认描述', 'textarea', '页面未单独设置描述时使用', 20],
            ['seo_og_image',     '',       'seo', '默认分享图', 'image', '社交分享默认图，建议 1200×630', 30],
            ['seo_robots_extra', '',       'seo', 'robots.txt 追加内容', 'textarea', '高级项，追加到 robots.txt 末尾', 40],
            ['seo_head_code',    '',       'seo', '自定义 head 代码', 'textarea', '高级项：原样输出到前台 </head> 前（统计 / 验证代码），内容须自行确保可信', 50],

            // ---------- GEO ----------
            ['geo_org_name',       $appName, 'geo', 'Organization 名称', 'text', 'JSON-LD 使用', 10],
            ['geo_org_en_name',    '',       'geo', 'Organization 英文名', 'text', '留空则不输出 alternateName', 20],
            ['geo_org_logo',       '',       'geo', 'Organization Logo', 'image', '建议 512×512 以上方形 PNG', 30],
            ['geo_llms_enabled',   '1',      'geo', '生成 llms.txt', 'bool', '关闭后 /llms.txt 返回 404', 40],
            ['geo_sitemap_enabled', '1',     'geo', '生成 sitemap.xml', 'bool', '关闭后 /sitemap.xml 返回 404', 50],

            // ---------- 文案话术（默认留空，Copy 层逐句回退 config 中性默认） ----------
            ['copy_bcta_title',             '', 'copy', '底部 CTA · 标题', 'text', '留空恢复默认', 10],
            ['copy_bcta_desc',              '', 'copy', '底部 CTA · 说明', 'text', '留空恢复默认', 20],
            ['copy_bcta_primary',           '', 'copy', '底部 CTA · 主按钮', 'text', '留空恢复默认', 30],
            ['copy_bcta_secondary',         '', 'copy', '底部 CTA · 次按钮', 'text', '留空恢复默认', 40],
            ['copy_bcta_factory_primary',   '', 'copy', '专题页 CTA · 主按钮（变体）', 'text', '留空恢复默认', 50],
            ['copy_bcta_factory_secondary', '', 'copy', '专题页 CTA · 次按钮（变体）', 'text', '留空恢复默认', 60],
            ['copy_form_name_label',        '', 'copy', '表单 · 姓名字段标题', 'text', '留空恢复默认', 100],
            ['copy_form_name_placeholder',  '', 'copy', '表单 · 姓名输入提示', 'text', '留空恢复默认', 105],
            ['copy_form_name_error',        '', 'copy', '表单 · 姓名未填提示', 'text', '留空恢复默认', 110],
            ['copy_form_phone_label',       '', 'copy', '表单 · 电话字段标题', 'text', '留空恢复默认', 120],
            ['copy_form_phone_placeholder', '', 'copy', '表单 · 电话输入提示', 'text', '留空恢复默认', 125],
            ['copy_form_phone_error',       '', 'copy', '表单 · 电话未填提示', 'text', '留空恢复默认', 130],
            ['copy_form_phone_invalid',     '', 'copy', '表单 · 电话格式错误提示', 'text', '留空恢复默认', 135],
            ['copy_form_type_label',        '', 'copy', '表单 · 客户类型标题', 'text', '留空恢复默认', 140],
            ['copy_form_type_placeholder',  '', 'copy', '表单 · 客户类型占位', 'text', '留空恢复默认', 145],
            ['copy_form_type_error',        '', 'copy', '表单 · 未选客户类型提示', 'text', '留空恢复默认', 150],
            ['copy_form_type_options',      '', 'copy', '表单 · 客户类型选项', 'textarea', '每行一个；保存后前台下拉与后端校验同步生效；留空恢复默认', 155],
            ['copy_form_note_label',        '', 'copy', '表单 · 需求字段标题', 'text', '留空恢复默认', 160],
            ['copy_form_note_placeholder',  '', 'copy', '表单 · 需求输入提示', 'text', '留空恢复默认', 165],
            ['copy_form_submit',            '', 'copy', '表单 · 提交按钮', 'text', '留空恢复默认', 170],
            ['copy_form_submitting',        '', 'copy', '表单 · 提交中状态', 'text', '留空恢复默认', 175],
            ['copy_form_privacy',           '', 'copy', '表单 · 隐私说明', 'text', '留空恢复默认', 180],
            ['copy_form_success',           '', 'copy', '表单 · 提交成功提示', 'text', '留空恢复默认', 185],
            ['copy_404_title',              '', 'copy', '404 · 标题', 'text', '留空恢复默认', 300],
            ['copy_404_desc',               '', 'copy', '404 · 说明', 'textarea', '留空恢复默认', 310],
            ['copy_404_primary',            '', 'copy', '404 · 主按钮', 'text', '留空恢复默认', 320],
            ['copy_404_secondary',          '', 'copy', '404 · 次按钮', 'text', '留空恢复默认', 330],
            ['copy_footer_slogan',          '', 'copy', '页脚 · 品牌标语', 'text', 'logo 下方一句话；留空恢复默认', 400],

            // ---------- 外部对接（接收推送完整可用；主动拉取未实现，相关键已 RETIRE） ----------
            ['sync_geoflow_enabled',  '0', 'sync', '启用外部内容推送接收', 'bool', '关闭时接口仍返回但拒绝写入', 10],
            ['sync_geoflow_token',    '',  'sync', '接口 Token', 'text', '后台生成与轮换，仅创建时明文展示一次', 20],
            ['sync_auto_publish',    '0', 'sync', '接收内容自动发布', 'bool', '关闭时门禁通过也进入草稿，待人工发布', 25],
        ];

        foreach ($rows as $r) {
            [$key, $value, $group, $label, $type, $hint, $sort] = $r;
            Setting::updateOrCreate(
                ['key' => $key],
                compact('value', 'group', 'label', 'type', 'hint') + ['sort' => $sort]
            );
        }

        Setting::flush();
    }
}
