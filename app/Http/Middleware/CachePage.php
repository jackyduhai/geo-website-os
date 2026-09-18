<?php

namespace App\Http\Middleware;

use App\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 前台整页静态化缓存（仅作用于匿名 GET 的 HTML 页面）。
 * ------------------------------------------------------------------
 * 命中：直接返回文件中的 SSR HTML（回填当前会话 CSRF/归因），跳过控制器/渲染/查库。
 * 未命中：正常执行业务，把 200 HTML 的「匿名外壳」落盘，供后续访客命中。
 *
 * 不缓存：后台 / API / 健康检查、搜索（随查询词变化）、留言提交（POST）、
 * 非 GET、AJAX/JSON、带非白名单查询参数、登录态、以及留言提交后 PRG 的
 * 成功/校验错误/旧输入页面（这些是一次性个性化内容）。
 *
 * 响应标记 X-Page-Cache: HIT / MISS / BYPASS 便于核验；
 * HTML 含按会话回填的 CSRF，故对外只允许私有缓存并以 ETag 协商，不交给共享 CDN。
 */
class CachePage
{
    /** 允许出现在 URL 上、但不影响页面内容的投放追踪参数（其余查询参数一律旁路）。 */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'msclkid', 'yclid', 'from', 'spm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cacheable($request)) {
            $response = $next($request);
            // 前台 HTML GET 因个性化内容（留言成功/错误/旧输入）旁路时也打标，便于核验；
            // 后台 / API / 搜索等本就不属于整页缓存范围，不打标。
            if ($this->isFrontHtmlGet($request, $response)) {
                $response->headers->set('X-Page-Cache', 'BYPASS');
            }

            return $response;
        }

        // 命中：读匿名外壳并按当前会话回填
        $personalized = PageCache::get($request);
        if ($personalized !== null) {
            return $this->finalize($request, response($personalized, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ])->header('X-Page-Cache', 'HIT'));
        }

        // 未命中：正常执行业务
        $response = $next($request);

        if ($this->shouldStore($request, $response)) {
            PageCache::put($request, (string) $response->getContent());
            $response->headers->set('X-Page-Cache', 'MISS');

            return $this->finalize($request, $response);
        }

        // 前台 HTML 但本次不宜缓存（如 PRG 个性化页）
        if (str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            $response->headers->set('X-Page-Cache', 'BYPASS');
        }

        return $response;
    }

    private function isFrontHtmlGet(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }
        if ($request->is('admin', 'admin/*', 'api/*', 'up', 'search', 'search/*')) {
            return false;
        }

        return str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html');
    }

    private function cacheable(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson() || $request->wantsJson()) {
            return false;
        }

        // 仅前台页面：排除后台、接口、健康检查、搜索、留言端点
        if ($request->is('admin', 'admin/*', 'api/*', 'up', 'search', 'search/*', 'inquiry')) {
            return false;
        }

        // 登录态不缓存（前台虽无登录，兜底）
        try {
            if ($request->user() !== null) {
                return false;
            }
        } catch (\Throwable $e) {
            // 守卫未就绪时按匿名处理
        }

        // 仅允许白名单追踪参数；出现其它查询参数（如搜索 q、分页等）不缓存
        $query = array_keys($request->query());
        foreach ($query as $key) {
            if (! in_array($key, self::TRACKING_PARAMS, true)) {
                return false;
            }
        }

        // 留言提交后的 PRG 个性化内容（成功提示 / 校验错误 / 旧输入）不缓存
        $session = $request->session();
        if ($session === null) {
            return false;
        }
        if ($session->has('lead_success') || $session->has('errors') || $session->has('_old_input')) {
            return false;
        }

        return true;
    }

    private function shouldStore(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        if (! str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return false;
        }
        if (stripos((string) $response->headers->get('Cache-Control', ''), 'no-store') !== false) {
            return false;
        }

        return trim((string) $response->getContent()) !== '';
    }

    /**
     * 统一 HTML 协商缓存：私有（含按会话回填的 CSRF）、每次协商、弱 ETag。
     * 同一会话内容未变时返回 304，节省带宽；不同会话/内容更新后 ETag 自动变化。
     */
    private function finalize(Request $request, Response $response): Response
    {
        $body = (string) $response->getContent();
        $etag = 'W/"'.substr(md5($body), 0, 16).'"';
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('ETag', $etag);

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'Cache-Control' => 'private, no-cache, must-revalidate',
                'ETag'          => $etag,
                'X-Page-Cache'  => $response->headers->get('X-Page-Cache', 'HIT'),
            ]);
        }

        return $response;
    }
}
