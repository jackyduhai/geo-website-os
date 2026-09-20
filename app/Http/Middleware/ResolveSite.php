<?php

namespace App\Http\Middleware;

use App\Support\RequestScopedState;
use App\Support\SiteContext;
use App\Support\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveSite 中间件
 *
 * 在请求开始时解析 Site 并设置到 SiteContext
 * 在请求结束时清理 SiteContext
 */
class ResolveSite
{
    /**
     * 处理传入请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 解析 Site 并设置 Context
        $site = SiteResolver::resolveAndSet($request);

        // 如果解析失败且启用了 fallback，使用 default site
        if (!$site && config('site.default_fallback', true)) {
            $default = SiteResolver::default();
            if ($default) {
                SiteContext::setSite($default);
            }
        }

        // 真实站点此刻才确定（服务 boot 阶段通常仍解析为 default）。按当前站复位请求级记忆并重放
        // 主题 / 插件，防止 default 站预热、或常驻进程上一请求残留的设置 / 导航 / 主题快照串站。
        RequestScopedState::reapply();

        try {
            $response = $next($request);
        } finally {
            // 请求结束后清理 Context，防止泄漏到下一个请求
            SiteContext::clear();
        }

        return $response;
    }
}
