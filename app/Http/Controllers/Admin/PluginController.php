<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Http\SubRequest;
use App\Support\PageCache;
use App\Support\Plugins\PluginManager;
use App\Support\SystemAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 插件管理后台（P-STEP 17E）。
 *
 * 插件是**文件资源**（plugins/{slug}/plugin.json），本控制器只暴露「发现 / 启用 / 停用」
 * per-site 生命周期，不提供上传安装、不做运行时动态注册（注册在路由加载期由
 * PluginManager::registerInstalledRoutes() 统一完成，注册 ≠ 授权）。
 *
 * 启用态是 per-site 设置 plugins_enabled；插件路由能否访问，运行时唯一闸门是
 * {@see \App\Http\Middleware\EnsurePluginEnabled}。本控制器只改启用设置，绝不绕过守卫；
 * 列表对每条注册路由发起「本站上下文」真实子请求探测，把「本站启用应 200 / 他站或停用
 * 应 404」从测试变成运营可见。启用做依赖闸门、停用做反向依赖保护，失败均不写设置。
 */
class PluginController extends Controller
{
    public function index(): View
    {
        $rows = [];
        foreach (PluginManager::all() as $slug => $plugin) {
            $routes = $this->pluginRoutes($slug);

            // 对每条 GET 路由发起本站上下文真实探测（JSON，整页缓存 BYPASS）。
            $probe = [];
            foreach ($routes as $route) {
                if (in_array('GET', $route['methods'], true)) {
                    $probe[$route['uri']] = SubRequest::getOnCurrentSite($route['uri'], true)->getStatusCode();
                }
            }

            $rows[$slug] = $plugin + [
                'is_enabled' => PluginManager::isEnabled($slug),
                'routes'     => $routes,
                'probe'      => $probe,
                'blockers'   => PluginManager::dependencyBlockers($slug),
            ];
        }

        return view('admin.plugins.index', [
            'rows'    => $rows,
            'invalid' => PluginManager::invalidPlugins(),
            'cross'   => $this->crossSiteMatrix(),
        ]);
    }

    public function enable(string $slug): RedirectResponse
    {
        if (! PluginManager::exists($slug)) {
            abort(404);
        }

        $blockers = PluginManager::dependencyBlockers($slug);
        if ($blockers !== []) {
            return redirect()->route('admin.plugins.index')
                ->with('error', '无法启用插件：' . implode('；', $blockers) . '。启用态未改变。');
        }

        if (! PluginManager::enable($slug)) {
            return redirect()->route('admin.plugins.index')
                ->with('error', '插件启用失败，启用态未改变。');
        }

        AuditLog::record('plugin.enable', "启用插件：{$slug}", [], 'Setting');

        PageCache::flush();

        return redirect()->route('admin.plugins.index')
            ->with('success', "插件已在当前站点启用：{$slug}。");
    }

    public function disable(string $slug): RedirectResponse
    {
        if (! PluginManager::exists($slug)) {
            abort(404);
        }

        $dependents = PluginManager::enabledDependents($slug);
        if ($dependents !== []) {
            return redirect()->route('admin.plugins.index')
                ->with('error', '无法停用：以下当前站点已启用的插件依赖它，请先停用它们：' . implode('、', $dependents) . '。');
        }

        PluginManager::disable($slug);
        AuditLog::record('plugin.disable', "停用插件：{$slug}", [], 'Setting');
        PageCache::flush();

        return redirect()->route('admin.plugins.index')
            ->with('success', "插件已在当前站点停用：{$slug}。");
    }

    /**
     * 收集某插件在路由加载期注册的全部路由（URI / 方法 / 名称）。
     *
     * @return array<int,array{uri:string,methods:array<int,string>,name:?string}>
     */
    private function pluginRoutes(string $slug): array
    {
        $out = [];
        $prefix = '/plugins/' . $slug;
        foreach (app('router')->getRoutes() as $route) {
            $uri = '/' . ltrim($route->uri(), '/');
            if ($uri !== $prefix && ! str_starts_with($uri, $prefix . '/')) {
                continue;
            }
            $methods = array_values(array_filter(
                $route->methods(),
                static fn ($m) => $m !== 'HEAD'
            ));
            $out[] = ['uri' => $uri, 'methods' => $methods, 'name' => $route->getName()];
        }

        return $out;
    }

    /**
     * 超管只读「各站插件启用态」矩阵；普通站点管理员返回 null。
     * 写操作仍作用于当前管理站点（顶部切站器），不在这里跨站改设置。
     *
     * @return array{sites:array<int,\App\Models\Site>,enabled:array<int,array<int,string>>}|null
     */
    private function crossSiteMatrix(): ?array
    {
        if (! SystemAuthorization::canCrossSite()) {
            return null;
        }

        $sites = Site::orderBy('id')->get()->all();

        $rows = Setting::withoutSiteScope()
            ->where('key', 'plugins_enabled')
            ->get(['site_id', 'value']);
        $map = [];
        foreach ($rows as $row) {
            // PluginManager 以 json_encode 字符串写入、读取时再 decode（Setting 本身又是 json cast），
            // 因此模型 ->value 可能是数组，也可能是尚待 decode 的 JSON 字符串，这里统一容错。
            $raw = $row->value;
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            $list = is_array($raw) ? $raw : [];
            $map[(int) $row->site_id] = array_values(array_map('strval', $list));
        }

        $enabled = [];
        foreach ($sites as $site) {
            $enabled[] = $map[$site->id] ?? [];
        }

        return ['sites' => $sites, 'enabled' => $enabled];
    }
}
