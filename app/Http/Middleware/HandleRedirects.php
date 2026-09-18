<?php

namespace App\Http\Middleware;

use App\Models\Redirect;
use App\Support\SiteCacheKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * 旧站 301 / 302 跳转执行层（后台「治理 → 301 跳转」维护的规则在此统一生效）。
 * ------------------------------------------------------------------
 * 规则在后台增删改后通过模型事件失效缓存（见 AppServiceProvider）。
 * 设计要点：
 *   - 只处理前台文档型 GET/HEAD；放过后台、接口、带扩展名的静态文件、XHR/JSON。
 *   - 命中后用查询构造器自增 hits（不触发模型 saved 事件，避免每次跳转都清空整页缓存）。
 *   - 目标与当前地址相同则不跳（防自跳死循环）。
 *   - 支持内部路径、外链；目标未自带查询串时透传原请求查询串（旧站 ?id= 类入口）。
 *   - 放在 CanonicalizeSlash / CachePage 之前：旧 URL 先跳转，不做尾斜杠规范化、不入整页缓存。
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($response = $this->matchRedirect($request)) {
            return $response;
        }

        return $next($request);
    }

    private function matchRedirect(Request $request): ?Response
    {
        if (! $request->isMethodSafe() || $request->ajax() || $request->wantsJson()) {
            return null;
        }

        $path = '/'.ltrim(rawurldecode($request->getPathInfo()), '/');
        if ($path === '/' || str_starts_with($path, '/admin') || str_starts_with($path, '/api')) {
            return null;
        }

        // 带扩展名的真实文件（图片 / sitemap.xml 等）不参与旧链跳转
        $lastSeg = (string) substr(strrchr($path, '/'), 1);
        if ($lastSeg !== '' && str_contains($lastSeg, '.')) {
            return null;
        }

        $rule = $this->rules()->get($this->matchKey($request, $path));
        if (! $rule) {
            return null;
        }

        $target = $this->buildTarget($rule['to_path'], $request);
        if ($target === null) {
            return null; // 自跳保护
        }

        // 查询构造器自增，不触发 Redirect 模型事件
        Redirect::where('id', $rule['id'])->increment('hits');

        return new Response('', (int) $rule['code'], [
            'Location' => $target,
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 计算当前请求应命中哪条规则：优先「路径 + 查询串」精确匹配，再退回纯路径；
     * 纯路径同时兼容带 / 不带尾斜杠两种写法。
     */
    private function matchKey(Request $request, string $path): string
    {
        $qs = (string) $request->getQueryString();

        if ($qs !== '') {
            foreach ([$path, $this->toggleSlash($path)] as $candidate) {
                if ($candidate !== null && $this->rules()->has($candidate.'?'.$qs)) {
                    return $candidate.'?'.$qs;
                }
            }
        }

        foreach ([$path, $this->toggleSlash($path)] as $candidate) {
            if ($candidate !== null && $this->rules()->has($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function toggleSlash(string $path): ?string
    {
        if (str_ends_with($path, '/')) {
            return rtrim($path, '/') ?: null;
        }

        return $path.'/';
    }

    private function buildTarget(string $to, Request $request): ?string
    {
        $to = trim($to);
        if ($to === '' || $to === '#') {
            return null;
        }

        // 外链 / 协议相对地址原样使用
        if (preg_match('~^https?://~i', $to) || str_starts_with($to, '//')) {
            $url = $to;
        } else {
            $url = url('/'.ltrim($to, '/'));
        }

        // 自跳保护：目标路径与当前路径一致（且未额外带查询串）时不跳
        $targetPath = (string) parse_url($url, PHP_URL_PATH);
        $targetQuery = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $currentPath = $request->getPathInfo();
        if (rtrim($targetPath, '/') === rtrim($currentPath, '/') && $targetQuery === '') {
            return null;
        }

        // 目标未自带查询串时，透传原始查询串（旧站 ?cb-13-1.html 等）
        if ($targetQuery === '' && $qs = $request->getQueryString()) {
            $url .= '?'.$qs;
        }

        return $url;
    }

    /**
     * 启用规则映射（永久缓存，后台保存即失效）：
     * key 为规范化后的来源（路径，或 路径?查询串），value 为 id / to_path / code。
     *
     * @return \Illuminate\Support\Collection<string, array{id:int,to_path:string,code:int}>
     */
    private function rules()
    {
        return Cache::rememberForever(SiteCacheKey::redirectsActive(), function () {
            $map = collect();
            foreach (Redirect::active()->orderBy('id')->get(['id', 'from_path', 'to_path', 'code']) as $r) {
                $from = trim((string) $r->from_path);
                if ($from === '') {
                    continue;
                }
                // 仅取路径部分作为 key；若规则自带查询串则拼成「路径?查询」
                $qs = '';
                if (str_contains($from, '?')) {
                    [$fromPath, $qs] = explode('?', $from, 2);
                } else {
                    $fromPath = $from;
                }
                $fromPath = $this->normalizeFromPath($fromPath);
                if ($fromPath === null) {
                    continue;
                }
                $key = $qs !== '' ? $fromPath.'?'.$qs : $fromPath;
                // 同 key 时让后建的规则（id 更大）生效，避免重复来源行为不确定
                $map->put($key, ['id' => (int) $r->id, 'to_path' => (string) $r->to_path, 'code' => (int) $r->code]);
            }

            return $map;
        });
    }

    private function normalizeFromPath(string $from): ?string
    {
        $from = trim($from);
        if ($from === '') {
            return null;
        }
        // 允许填写完整本站 URL，只取路径
        if (preg_match('~^https?://~i', $from)) {
            $from = (string) parse_url($from, PHP_URL_PATH);
        }
        $from = '/'.ltrim($from, '/');
        if ($from === '/') {
            return null;
        }

        return $from;
    }

    public static function flushRules(): void
    {
        Cache::forget(SiteCacheKey::redirectsActive());
    }
}
