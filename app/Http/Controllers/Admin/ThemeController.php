<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Http\SubRequest;
use App\Support\SystemAuthorization;
use App\Support\Theme\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 主题管理后台（P-STEP 17E）。
 *
 * 主题是**文件资源**（resources/themes/{slug}/theme.json + views/），本控制器只暴露
 * 「发现 / 预览 / 激活」生命周期，不提供上传、删除或在线编辑（归 Theme SDK / 文件部署，
 * 避免后台写文件的安全面）。激活态是 per-site 设置 theme_active，ThemeManager 负责原子
 * 切换（不存在的主题直接返回 false、不写设置，因此不会留下半激活状态）。
 *
 * 预览（{@see ThemeManager::preview()}）仅在当前请求内临时以指定主题渲染前台首页，
 * 不写设置、不改激活态；预览响应带 noindex，避免被抓取。
 */
class ThemeController extends Controller
{
    public function index(): View
    {
        $themes = ThemeManager::all();

        // 不同目录但 display name 相同会让运营无法区分，只读告警（仍可按目录 slug 激活）。
        $nameCount = array_count_values(array_map(static fn ($t) => $t['name'], $themes));
        $duplicates = array_keys(array_filter($nameCount, static fn ($n) => $n > 1));

        return view('admin.themes.index', [
            'themes'     => $themes,
            'active'     => ThemeManager::active(),
            'invalid'    => ThemeManager::invalidThemes(),
            'duplicates' => $duplicates,
            'cross'      => $this->crossSiteMatrix(),
        ]);
    }

    public function activate(string $name): RedirectResponse
    {
        if (! ThemeManager::exists($name)) {
            abort(404);
        }

        if (! ThemeManager::activate($name)) {
            return redirect()->route('admin.themes.index')
                ->with('error', '主题激活失败：主题清单无效或已缺失，激活态未改变。');
        }

        AuditLog::record('theme.activate', "切换主题：{$name}", [], 'Setting');

        return redirect()->route('admin.themes.index')
            ->with('success', "已为当前站点切换主题：{$name}。");
    }

    /**
     * 在新窗口以指定主题渲染前台首页（请求级预览，不改变激活态）。
     */
    public function preview(string $name)
    {
        if (! ThemeManager::exists($name)) {
            abort(404);
        }

        $response = ThemeManager::preview(
            $name,
            static fn () => SubRequest::getOnCurrentSite('/', false)
        );

        $response->headers->set('X-Theme-Preview', $name);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * 超管只读「各站当前激活主题」总览；普通站点管理员返回 null（仅看本站）。
     * 写操作仍作用于顶部切站器选定的当前管理站点，不在这里跨站改设置。
     *
     * @return array<int,array{site:\App\Models\Site,theme:string}>|null
     */
    private function crossSiteMatrix(): ?array
    {
        if (! SystemAuthorization::canCrossSite()) {
            return null;
        }

        $rows = Setting::withoutSiteScope()
            ->where('key', 'theme_active')
            ->get(['site_id', 'value']);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->site_id] = (string) $row->value;
        }

        $matrix = [];
        foreach (Site::orderBy('id')->get() as $site) {
            $theme = $map[$site->id] ?? '';
            $matrix[] = [
                'site'  => $site,
                'theme' => $theme !== '' ? $theme : ThemeManager::DEFAULT_THEME,
            ];
        }

        return $matrix;
    }
}
