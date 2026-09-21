<?php

namespace App\Http\Middleware;

use App\Support\SystemAuthorization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 系统级（超级管理员）操作保护。
 *
 * 站点管理这类跨站资源仅允许 is_super_admin（geo:install 创建的首个管理员）访问：
 * - 未登录由本中间件跳转登录（与 EnsureAdmin 行为一致，避免资源枚举）；
 * - 已登录但非超管一律 403（Site Admin 不得新建 / 修改 / 删除站点）。
 *
 * 授权身份来自用户数据 is_super_admin，不按邮箱、环境变量或全局开关判定。
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('admin.login')->with('error', '请先登录后台');
        }

        if (! SystemAuthorization::canCrossSite($user)) {
            abort(403, '仅超级管理员可以执行该操作');
        }

        return $next($request);
    }
}
