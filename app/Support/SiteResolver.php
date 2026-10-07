<?php

namespace App\Support;

use App\Models\Site;
use App\Support\Templates\TemplatePreviewSite;
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
     *
     * 安全原则：
     * - Domain 是生产环境唯一的 Site 识别方式
     * - site_slug 仅用于 admin 路径或 CLI，不允许在普通 HTTP 请求中通过 Query 任意切站
     */
    public static function resolveFromRequest(Request $request): ?Site
    {
        // 1. Admin 路径显式 slug（仅 admin 前缀下生效，防止任意切站）
        //    admin 路径优先于 domain，因为后台管理员需要跨站点管理
        if ($request->is('admin/*') || $request->is('admin')) {
            $slug = $request->route('site_slug') ?? $request->query('site_slug');
            if ($slug) {
                $site = self::findBySlug($slug);
                if ($site) {
                    return $site;
                }
            }
        }

        // 1b. 模板预览站（RC-11 G）：前台允许 ?site_slug=tpl-preview-<pack>。
        //     **只认预览前缀** —— 绝不允许用它切到任意真实站点，
        //     否则任何人都能通过改 query 参数访问别的站点。
        $previewSlug = (string) $request->query('site_slug', '');
        if ($previewSlug !== '' && TemplatePreviewSite::isPreviewSlug($previewSlug)) {
            $previewSite = self::findBySlug($previewSlug);
            if ($previewSite) {
                return $previewSite;
            }
        }

        // 2. Domain 匹配（生产环境唯一入口）
        $domain = $request->getHost();
        $site = self::findByDomain($domain);
        if ($site) {
            return $site;
        }

        // 3. Fallback 到 default site（仅当启用了 fallback）
        // 多站点安全：multi-site 模式默认不 fallback
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
