<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * 站点设置：7 个分组，键值驱动；保存即清缓存即时生效。
 * 主题分组是「可自定义样式」的落点，改色值/圆角/容器宽度不需要动代码。
 */
class SettingController extends Controller
{
    public const GROUPS = [
        'general' => '基础信息',
        'theme'   => '主题样式',
        'contact' => '联系方式',
        'copy'    => '文案话术',
        'seo'     => 'SEO 设置',
        'geo'     => 'GEO 设置',
        'sync'    => 'GEOFlow 对接',
    ];

    public function index(Request $request, string $group = 'general'): View
    {
        abort_unless(array_key_exists($group, self::GROUPS), 404);

        $settings = Setting::where('group', $group)->orderBy('sort')->get();
        $images = Media::where('mime', 'like', 'image/%')->latest()->limit(40)->get();

        return view('admin.settings.form', compact('group', 'settings', 'images'));
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        abort_unless(array_key_exists($group, self::GROUPS), 404);

        $items = Setting::where('group', $group)->get();

        foreach ($items as $item) {
            $key = $item->key;

            if ($item->type === 'bool') {
                Setting::set($key, $request->boolean($key) ? '1' : '0');
                continue;
            }

            if ($item->type === 'image' && $request->hasFile("file_$key")) {
                $path = \App\Support\ImageOptimizer::store($request->file("file_$key"), 'media/' . date('Ym'), \App\Support\ImageOptimizer::MAXW_LOGO);
                Setting::set($key, 'storage/' . $path);
                continue;
            }

            // 旧标签页/旧表单中不存在的字段必须保留原值，不能按缺省 '' 覆盖
            // （新增设置键后，用户用改动前打开的页面保存会误清空新字段）。
            // 文本框显式提交空串仍会正常清空（exists 为 true）。
            if (! $request->exists($key)) {
                continue;
            }

            $value = $request->input($key, '');
            Setting::set($key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
        }

        Setting::flush();
        AuditLog::record('settings.updated', '更新站点设置分组：' . self::GROUPS[$group], [], 'setting', null);

        return back()->with('success', '设置已保存并即时生效');
    }

    public function regenerateToken(): RedirectResponse
    {
        $token = 'yhf_' . Str::random(48);
        Setting::set('sync_geoflow_token', $token);
        Setting::flush();
        AuditLog::record('settings.token', '重新生成 GEOFlow 对接 Token', [], 'setting', null);

        return back()->with('success', '新 Token 已生成，请妥善复制给 GEOFlow 侧');
    }
}
