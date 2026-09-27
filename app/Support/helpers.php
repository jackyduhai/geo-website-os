<?php

/**
 * 全局 helper 函数（composer.json autoload.files 加载）。
 *
 * 这些函数原先定义在 routes/web.php 中，但生产模式启用 route:cache 后
 * Laravel 不再 require routes/web.php，导致函数未定义、渲染 dynamic_form
 * 的页面（/contact 等）500。移入 autoload.files 确保任何模式下均可用。
 *
 * 约束：仅做「移动加载位置」，函数体逻辑一字不改；不复制/重写 PublicUrl、
 * UrlResolver、PublicIndex 的裁决规则，不产生第二套 URL/helper 逻辑。
 */

if (! function_exists('PublicUrlLocalized')) {
    /**
     * 本地化路径的小 helper（供旧 /scenarios 兼容重定向使用）：
     * en 组内给路径加 /en 前缀，使重定向留在当前语言。
     */
    function PublicUrlLocalized(string $path): string
    {
        $locale = App\Support\Localization\LocaleContext::current();
        $prefix = App\Support\Localization\LocaleRegistry::prefix($locale);

        return url(($prefix !== '' ? '/' . $prefix : '') . $path);
    }
}

if (! function_exists('localized_route')) {
    /**
     * 按当前 locale 取对应路由（zh 组无后缀，en 组后缀 .en，与注册时 nameSuffix 一致）。
     * 供动态表单等需要 locale 正确 action 的视图使用，避免在 Blade 内判断语言或硬编码 URL。
     */
    function localized_route(string $base, $parameters = []): string
    {
        $locale = App\Support\Localization\LocaleContext::current();
        $prefix = App\Support\Localization\LocaleRegistry::prefix($locale);
        $name = $base . ($prefix !== '' ? '.' . $prefix : '');

        return route($name, $parameters);
    }
}
