<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Setting;
use App\Support\Audit\AuditSnapshot;
use App\Support\ImageOptimizer;
use App\Support\PageCache;
use App\Support\Theme\ThemePresets;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    /**
     * 后台设置分组（与 settings.group 对应）。
     */
    public const GROUPS = [
        'general' => '基础信息',
        'theme'   => '主题样式',
        'contact' => '联系方式',
        'copy'    => '文案话术',
        'seo'     => 'SEO 设置',
        'geo'     => 'GEO 设置',
        'analytics' => 'Analytics 统计',
        'sync'    => 'GEOFlow 对接',
    ];

    /**
     * 已退役（RETIRE）设置键：曾在旧演示 Seeder 中定义、但没有任何 Runtime consumer
     * （或对应功能从未实现）。后台不渲染、不接受写入；历史库中的残留行保留不删，
     * 仅对运营不可见、不可改。不新增删除 migration，保留工程历史。
     */
    public const RETIRED = [
        'site_short_name'      => '无前台 consumer，站点短名未被使用',
        'site_slogan'          => '无前台 consumer，首页副标题使用 site_description',
        'sync_geoflow_endpoint' => '主动拉取（pull）功能未实现',
        'sync_pull_enabled'    => '主动拉取（pull）功能未实现，仅健康检查占位',
    ];

    public function index(string $group = 'general')
    {
        abort_unless(array_key_exists($group, self::GROUPS), 404);

        $items = Setting::where('group', $group)
            ->whereNotIn('key', array_keys(self::RETIRED))
            ->orderBy('sort')
            ->get();
        $images = Media::whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->orderByDesc('id')->limit(40)->get();

        return view('admin.settings.form', [
            'group'    => $group,
            'groups'   => self::GROUPS,
            'settings' => $items,
            'images'   => $images,
            'retired'  => self::RETIRED,
        ]);
    }

    public function update(Request $request, string $group)
    {
        abort_unless(array_key_exists($group, self::GROUPS), 404);

        // 仅遍历该分组「未退役」的设置行；伪造提交的 RETIRED 键天然不在其中，无法写入。
        $items = Setting::where('group', $group)
            ->whereNotIn('key', array_keys(self::RETIRED))
            ->orderBy('sort')
            ->get();

        $errors = [];
        $before = [];
        $after = [];
        foreach ($items as $item) {
            $key = $item->key;

            // 图片：上传则替换为优化后的公开路径（视图文件字段名为 file_{key}）；
            // 未上传时若提交了文本路径字段则采用该路径，否则（旧表单无此字段）保留原值。
            if ($item->type === 'image') {
                $fileKey = 'file_' . $key;
                if ($request->hasFile($fileKey)) {
                    $path = ImageOptimizer::store($request->file($fileKey), 'settings', ImageOptimizer::MAXW_LOGO);
                    $this->applySetting($key, $path, $before, $after);
                } elseif ($request->exists($key)) {
                    $this->applySetting($key, (string) $request->input($key, ''), $before, $after);
                }
                continue;
            }

            // 旧标签页没有该字段时不覆盖（避免旧表单清空新字段）。
            if (! $request->exists($key)) {
                continue;
            }

            if ($item->type === 'bool') {
                $value = $request->boolean($key) ? '1' : '0';
            } elseif ($item->type === 'json') {
                $decoded = is_array($request->input($key)) ? $request->input($key) : [];
                if ($key === 'site_supported_locales') {
                    // 默认语言必须始终可用；只保留官方支持语言，防止伪造提交写入非法语言码。
                    $defaultLocale = (string) Setting::get('site_default_locale', \App\Support\Localization\LocaleRegistry::default());
                    $decoded = array_values(array_intersect(
                        array_unique(array_merge($decoded, [$defaultLocale])),
                        \App\Support\Localization\LocaleRegistry::supported()
                    ));
                }
                $value = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                $value = (string) $request->input($key, '');
            }

            // 字段级校验（空值一律允许，表示清空并回退默认）。
            if ($message = $this->validateValue($item, $value)) {
                $errors[$key] = $message;
                continue;
            }

            $this->applySetting($key, $value, $before, $after);
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        // Analytics 组交叉校验：启用某 provider 时对应 ID 必填（ID 格式已在 validateValue 校验）。
        if ($group === 'analytics') {
            $pairs = [
                ['analytics_ga4_enabled', 'analytics_ga4_id', 'Google Analytics 4'],
                ['analytics_gtm_enabled', 'analytics_gtm_id', 'Google Tag Manager'],
                ['analytics_meta_enabled', 'analytics_meta_id', 'Meta Pixel'],
            ];
            foreach ($pairs as [$enKey, $idKey, $plabel]) {
                if (Setting::get($enKey) === '1' && trim((string) Setting::get($idKey, '')) === '') {
                    $errors[$idKey] = "已启用 {$plabel}，请填写对应 ID。";
                }
            }
            if ($errors !== []) {
                return back()->withErrors($errors)->withInput();
            }
        }

        // TD-12 单一事实源：基础信息里的「站点名称」只是权威 Site.name 的编辑入口，
        // 保存时回写当前管理站点的 Site.name；Site 模型 saved 事件会再单向镜像
        // settings.site_name，保证两处始终一致（清空则保留原站点名，不置空权威名）。
        if ($group === 'general' && $request->exists('site_name')) {
            $displayName = trim((string) $request->input('site_name', ''));
            $currentSite = \App\Support\SiteContext::currentSite();
            if ($displayName !== '' && $currentSite && $currentSite->name !== $displayName) {
                $currentSite->name = $displayName;
                $currentSite->save();
            }
        }

        Setting::flush();
        PageCache::flush();

        // TD-92：设置变更须带审计安全 before/after（经 AuditSnapshot 脱敏 / 归一化，
        // 只保留真正变化字段）；无变化不产生日志，避免噪音。
        $changes = AuditSnapshot::changes($before, $after);
        if ($changes !== []) {
            AuditLog::record('settings.update', "更新站点设置：{$group}", ['changes' => $changes], 'settings');
        }

        return redirect()->route('admin.settings.index', ['group' => $group])
            ->with('success', '设置已保存。');
    }

    public function regenerateToken(Request $request)
    {
        // 产品中性前缀 gwos_（GEO Website OS），随机 48 字符。
        $token = 'gwos_' . Str::random(48);
        Setting::set('sync_geoflow_token', $token);
        Setting::flush();
        AuditLog::record('settings.token', '重新生成 GEOFlow 接口 Token');

        return redirect()->route('admin.settings.index', ['group' => 'sync'])
            ->with('token', $token);
    }

    /**
     * 一键应用行业视觉预设（P-STEP 18D）。
     *
     * 预设只写入 theme_* 视觉令牌（颜色 / 圆角 / 宽度 / 密度 / 阴影 / 字体），
     * 不触碰任何 IA、导航、文案或内容；theme_primary_dark 恒置空，交由 ThemePalette
     * 从新主色自动派生。写入限定在 ThemePresets::allowedKeys() 白名单，且键必须已在
     * settings 表注册（updateOrCreate 天然按当前管理站点作用域）。
     */
    public function applyPreset(Request $request)
    {
        $validated = $request->validate([
            'preset' => ['required', 'string'],
        ]);

        $preset = $validated['preset'];
        abort_unless(ThemePresets::exists($preset), 404);

        $tokens = ThemePresets::tokens($preset);
        foreach (ThemePresets::allowedKeys() as $key) {
            if (! array_key_exists($key, $tokens)) {
                continue;
            }
            Setting::set($key, (string) $tokens[$key]);
        }

        Setting::flush();
        PageCache::flush();
        AuditLog::record('settings.theme_preset', "应用行业视觉预设：{$preset}");

        return redirect()->route('admin.settings.index', ['group' => 'theme'])
            ->with('success', '已应用视觉预设（仅外观，不影响内容与导航）。');
    }

    /**
     * 写入一个设置并收集其 before/after（仅值真正变化时），供审计快照使用。
     */
    private function applySetting(string $key, string $value, array &$before, array &$after): void
    {
        $old = (string) Setting::get($key, '');
        if ($old !== $value) {
            $before[$key] = $old;
            $after[$key] = $value;
        }
        Setting::set($key, $value);
    }

    /**
     * 按字段类型 / 键做轻量服务端校验；返回中文错误信息，通过返回 null。
     * 空字符串视为「清空 / 回退默认」，一律放行。
     */
    private function validateValue(Setting $item, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $label = $item->label ?: $item->key;

        if ($item->type === 'color' && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return "「{$label}」必须是 #RRGGBB 格式的十六进制颜色值。";
        }

        if ($item->type === 'number') {
            if (! ctype_digit($value)) {
                return "「{$label}」必须是非负整数。";
            }
            $num = (int) $value;
            if ($item->key === 'theme_radius' && ($num < 0 || $num > 48)) {
                return '「圆角（px）」需在 0–48 之间。';
            }
            if ($item->key === 'theme_container' && ($num < 800 || $num > 2400)) {
                return '「内容区最大宽度（px）」需在 800–2400 之间。';
            }
        }

        if ($item->key === 'contact_email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return '「业务邮箱」不是合法的邮箱地址。';
        }

        if ($item->key === 'contact_map_url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '「地图链接」不是合法的 URL（需以 http:// 或 https:// 开头）。';
        }

        if ($item->key === 'theme_density' && ! in_array($value, ['comfortable', 'compact'], true)) {
            return '「排版密度」只能是 comfortable 或 compact。';
        }

        if ($item->key === 'theme_shadow' && ! in_array($value, ['flat', 'soft'], true)) {
            return '「阴影质感」只能是 flat 或 soft。';
        }

        if ($item->key === 'theme_color_mode' && ! in_array($value, ['light', 'dark', 'system'], true)) {
            return '「默认外观模式」只能是 light（浅色）、dark（深色）或 system（跟随系统）。';
        }

        if ($item->key === 'analytics_ga4_id' && ! preg_match('/^G-[A-Za-z0-9-]+$/', $value)) {
            return '「GA4 Measurement ID」需以 G- 开头（如 G-XXXXXXXXXX）。';
        }
        if ($item->key === 'analytics_gtm_id' && ! preg_match('/^GTM-[A-Za-z0-9]+$/', $value)) {
            return '「GTM Container ID」需以 GTM- 开头（如 GTM-XXXXXX）。';
        }
        if ($item->key === 'analytics_meta_id' && ! ctype_digit($value)) {
            return '「Meta Pixel ID」需为纯数字。';
        }

        return null;
    }
}
