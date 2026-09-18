<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全站统一安全响应头（系统级唯一一处，前台与后台都经过 web 组，统一生效）
 * ------------------------------------------------------------------
 * 固定安全头：
 *   - X-Content-Type-Options: nosniff   禁止 MIME 嗅探
 *   - X-Frame-Options: SAMEORIGIN       页面不允许被任意第三方站点 iframe 嵌套（防点击劫持）
 *   - Referrer-Policy                   外跳只带 origin，不全量泄露路径与参数
 *   - Permissions-Policy                默认关闭摄像头/麦克风/定位（本站不需要）
 *
 * Content-Security-Policy（按场景区分，均不破坏现有功能）：
 *   - 前台：脚本严格 nonce（前台零内联事件、零外链脚本），样式允许内联（含 style 属性），
 *     锁死 object/base/form-action/frame-ancestors；nonce 每请求生成，经整页缓存外壳
 *     占位回填（见 App\Support\PageCache），因此缓存命中也不会钉死 nonce。
 *   - 后台：历史模板存在 17 处内联事件（onclick 等），脚本保留 'unsafe-inline' 以免破坏
 *     管理功能，其余导航类指令（object/base/frame/form-action）同样锁死。
 *
 * 不在这里下发 HSTS：仅 HTTPS 反向代理（宝塔/Nginx）启用后才有意义，避免本地 http 误伤。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // 每请求生成 CSP nonce：先于 $next 生成，供内层整页缓存命中回填与 Blade 渲染共用
        $nonce = bin2hex(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        // 只对真实响应补头；不覆盖上游（如下载流）已显式设置的同名头
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => $this->csp($request, $nonce),
        ];
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // 收敛服务端指纹：不下发 PHP 版本（生产建议同时在 php.ini 设 expose_php=Off）
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    /**
     * 组装 CSP。前台脚本走 nonce 白名单；后台因内联事件保留 unsafe-inline。
     */
    private function csp(Request $request, string $nonce): string
    {
        $isAdmin = $request->is('admin', 'admin/*', 'api/*');

        $script = $isAdmin
            ? "script-src 'self' 'unsafe-inline'"
            : "script-src 'nonce-".$nonce."'";

        return implode('; ', [
            "default-src 'self'",
            $script,
            "style-src 'self' 'unsafe-inline'",
            // 图片允许同源与任意 http(s)/data/blob：CMS 可外链图片，图片为被动资源、风险低
            "img-src 'self' data: blob: http: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
