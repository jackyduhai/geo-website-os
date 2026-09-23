<?php

namespace App\Support\Localization;

/**
 * 请求级语言上下文（仿 SiteContext）。
 * 由 SetLocale 中间件在请求开始设置、结束清理；任何需要当前语言的代码
 * （PublicUrl / PageCache / Catalog / Feed）统一从这里读取，不自行判断。
 */
class LocaleContext
{
    private static ?string $locale = null;

    /** 当前语言；未显式设置时回退默认语言。 */
    public static function current(): string
    {
        return self::$locale ?? LocaleRegistry::default();
    }

    public static function set(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function has(): bool
    {
        return self::$locale !== null;
    }

    public static function clear(): void
    {
        self::$locale = null;
    }
}
