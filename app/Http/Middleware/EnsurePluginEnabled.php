<?php

namespace App\Http\Middleware;

use App\Support\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePluginEnabled（P-STEP 14 / D.3：插件注册 / 授权分离）
 *
 * 插件路由在路由加载期一次性注册（覆盖所有“已安装”插件），每条插件路由统一挂
 * 本守卫；是否真正可访问按“当前请求解析出的站点 + 该站启用设置”在运行时判定：
 *   - 插件未安装（清单缺失）→ 404
 *   - 插件已安装但当前站点未启用 → 404（不得落到其他站点的启用状态）
 *   - 插件已安装且当前站点启用 → 放行
 *
 * 守卫不感知任何具体插件：slug 一律来自路由参数（路由加载期由 PluginManager 注入），
 * 因此 Core 不包含任何具体插件目录 / 命名空间字面量（静态扫描边界）。
 */
class EnsurePluginEnabled
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        if (! PluginManager::exists($slug) || ! PluginManager::isEnabled($slug)) {
            abort(404);
        }

        return $next($request);
    }
}
