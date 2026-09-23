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
        $locale = $locale ?: LocaleRegistry::default();

        if (! LocaleRegistry::supports($locale)) {
            $locale = LocaleRegistry::default();
        }

        // 当前站点是否提供该语言（Site Configuration）。
        if (! in_array($locale, self::siteSupportedLocales(), true)) {
            abort(404);
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
