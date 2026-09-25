<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 单一语言解析中间件（别名 locale）。
 * ------------------------------------------------------------------
 * 从路由 action（locale:en / 默认 zh-CN）确定 requested locale，校验其既是
 * 官方支持语言、又在当前 Site 的 supported locales 内：
 *   - 站点未启用 en → /en/* 全部 404；
 *   - 通过后 App::setLocale() + LocaleContext::set()；
 *   - 请求结束清理 LocaleContext。
 *
 * 禁止 Controller / Blade 各自判断 $request->get('lang')。
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $routeLocale = $locale ?: LocaleRegistry::default();

        if (! LocaleRegistry::supports($routeLocale)) {
            $routeLocale = LocaleRegistry::default();
        }

        $siteLocales = self::siteSupportedLocales();
        $locale = $routeLocale;

        // 当前站点是否提供该语言（Site Configuration）。
        if (! in_array($locale, $siteLocales, true)) {
            // 仅「根级默认位置」（路由请求的是系统默认语言 zh-CN、但站点默认语言并非
            // zh-CN，如 en-only 站点）回退站点默认语言：站点根 / 与根级发现文件
            // sitemap.xml / llms.txt 必须在约定的无前缀默认位置可访问（TD-107 / TD-109）。
            // 显式请求非默认语言（/en/*，routeLocale=en）不被站点提供时仍严格 404；
            // 其余业务路径（/products 等）同样 404，不做语言回退。
            if ($routeLocale === LocaleRegistry::default()
                && $this->isRootDefaultResource($request)) {
                $siteDefault = self::siteDefaultLocale();
                if (LocaleRegistry::supports($siteDefault)
                    && in_array($siteDefault, $siteLocales, true)) {
                    $locale = $siteDefault;
                } else {
                    abort(404);
                }
            } else {
                abort(404);
            }
        }

        app()->setLocale($locale);
        LocaleContext::set($locale);

        try {
            return $next($request);
        } finally {
            LocaleContext::clear();
        }
    }

    /**
     * 是否为「根级默认位置资源」：站点根 / 或根级发现文件（sitemap.xml / llms.txt）。
     * 这些资源在约定的无语言前缀默认位置必须可访问，按站点默认语言解析。
     */
    private function isRootDefaultResource(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        if ($path === '') {
            return true; // 站点根 /
        }

        return in_array($path, ['sitemap.xml', 'llms.txt'], true);
    }

    /**
     * 当前站点默认语言（Setting site_default_locale）。
     * 未配置 / 非法时回退官方默认语言。
     */
    private static function siteDefaultLocale(): string
    {
        $default = (string) Setting::get('site_default_locale', LocaleRegistry::default());

        return LocaleRegistry::supports($default) ? $default : LocaleRegistry::default();
    }

    /**
     * 当前站点支持的语言（Setting site_supported_locales，JSON 数组）。
     * 未配置 / 为空时回退仅默认语言，保证空站与升级库行为安全。
     */
    private static function siteSupportedLocales(): array
    {
        $raw = Setting::get('site_supported_locales', [LocaleRegistry::default()]);

        if (is_array($raw)) {
            $locales = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? trim($v) : '',
                $raw
            )));
        } else {
            $locales = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
        }

        return $locales !== [] ? $locales : [LocaleRegistry::default()];
    }
}
