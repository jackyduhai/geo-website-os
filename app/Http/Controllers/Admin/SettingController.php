<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Setting;
use App\Support\ImageOptimizer;
use App\Support\PageCache;
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
        foreach ($items as $item) {
            $key = $item->key;

            // 图片：上传则替换为优化后的公开路径（视图文件字段名为 file_{key}）；
            // 未上传时若提交了文本路径字段则采用该路径，否则（旧表单无此字段）保留原值。
            if ($item->type === 'image') {
                $fileKey = 'file_' . $key;
                if ($request->hasFile($fileKey)) {
                    $path = ImageOptimizer::store($request->file($fileKey), 'settings', ImageOptimizer::MAXW_LOGO);
                    Setting::set($key, $path);
                } elseif ($request->exists($key)) {
                    Setting::set($key, (string) $request->input($key, ''));
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
                $value = is_array($request->input($key))
                    ? json_encode($request->input($key), JSON_UNESCAPED_UNICODE)
                    : (string) $request->input($key, '');
            } else {
                $value = (string) $request->input($key, '');
            }

            // 字段级校验（空值一律允许，表示清空并回退默认）。
            if ($message = $this->validateValue($item, $value)) {
                $errors[$key] = $message;
                continue;
            }

            Setting::set($key, $value);
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        Setting::flush();
        PageCache::flush();
        AuditLog::record('settings.update', "更新站点设置：{$group}");

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

        return null;
    }
}
