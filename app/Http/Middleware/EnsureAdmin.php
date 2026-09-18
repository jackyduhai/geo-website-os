<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台访问保护：未登录一律跳到后台登录页。
 * 单管理员模型，不做角色体系。
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('admin.login')->with('error', '请先登录后台');
        }

        return $next($request);
    }
}
