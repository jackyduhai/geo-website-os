<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 首次访问归因（first-touch）：
 * - landing_url：访客进入网站的第一个页面
 * - referer：站外来源（搜索引擎/外链等），仅记录首个外部来源
 * - utm_*：落地 URL 上的投放参数，后续点击带新 UTM 时覆盖（记录最终带来转化的活动）
 * 仅处理前台 GET 的 HTML 请求，不影响后台、API 与表单提交。
 */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldCapture($request)) {
            $session = $request->session();

            if (! $session->has('attr.landing_url')) {
                $session->put('attr.landing_url', mb_substr($request->fullUrl(), 0, 255));

                $referer = (string) $request->headers->get('referer');
                if ($referer !== '' && ! $this->isInternal($request, $referer)) {
                    $session->put('attr.referer', mb_substr($referer, 0, 255));
                }
            }

            foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
                $value = $request->query('utm_' . $key);
                if (is_string($value) && $value !== '') {
                    $session->put('attr.utm_' . $key, mb_substr($value, 0, 96));
                }
            }
        }

        return $next($request);
    }

    private function shouldCapture(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }
        // 后台与接口不做前台归因
        return ! $request->is('admin', 'admin/*', 'api/*', 'up');
    }

    private function isInternal(Request $request, string $referer): bool
    {
        $host = parse_url($referer, PHP_URL_HOST);
        return $host !== null && strcasecmp($host, (string) $request->getHost()) === 0;
    }
}
