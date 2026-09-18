<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * SiteResolver — HTTP 请求 → Site 解析器
 *
 * 解析顺序：
 * 1. 显式 slug（如 admin / CLI 指定）
 * 2. Domain 匹配（sites.domain）
 * 3. Default Site（sites.is_default = true）
 *
 * 安全原则：
 * - 未知 domain 不会静默落到 default site（多站点安全）
 * - 仅当配置允许时才 fallback 到 default site
 * - inactive site 会被拒绝
 */
class SiteResolver
{
    /**
     * 从 HTTP Request 解析当前 Site
     */
    public static function resolveFromRequest(Request $request): ?Site
    {
        // 1. 检查是否有显式 slug（如 /admin/{slug}）
        $slug = $request->route('site_slug') ?? $request->query('site_slug');
        if ($slug) {
            $site = self::findBySlug($slug);
            if ($site) {
                return $site;
            }
        }

        // 2. 检查 domain 匹配
        $domain = $request->getHost();
        $site = self::findByDomain($domain);
        if ($site) {
            return $site;
        }

        // 3. Fallback 到 default site（仅当启用了 fallback）
        // 多站点安全：默认不 fallback，未知 domain 返回 null
        if (config('site.default_fallback', false)) {
            return self::default();
        }

        return null;
    }

    /**
     * 按 slug 查找（仅 active）
     */
    public static function findBySlug(string $slug): ?Site
    {
        if (!Schema::hasTable('sites')) {
            return null;
        }

        return Site::where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    /**
     * 按 domain 查找（仅 active）
     */
    public static function findByDomain(string $domain): ?Site
    {
        if (!Schema::hasTable('sites')) {
            return null;
        }

        return Site::where('domain', $domain)
            ->where('status', 'active')
            ->first();
    }

    /**
     * 获取 default site
     */
    public static function default(): ?Site
    {
        if (!Schema::hasTable('sites')) {
            return null;
        }

        return Site::where('is_default', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * 解析并设置到 SiteContext
     */
    public static function resolveAndSet(Request $request): ?Site
    {
        $site = self::resolveFromRequest($request);

        if ($site) {
            SiteContext::setSite($site);
        }

        return $site;
    }
}
