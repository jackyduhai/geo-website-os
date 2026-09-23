<?php

namespace App\Support\Localization;

/**
 * 语言注册表（config/localization.php 的只读访问口）。
 * 统一 supported / default / fallback / URL prefix，避免各处硬编码语言码。
 */
class LocaleRegistry
{
    /** 官方支持的前端语言（BCP-47）。 */
    public static function supported(): array
    {
        return (array) config('localization.supported', ['zh-CN', 'en']);
    }

    /** 默认语言（无前缀）。 */
    public static function default(): string
    {
        return (string) config('localization.default', 'zh-CN');
    }

    /** 系统 / UI 字符串回退语言。 */
    public static function fallback(): string
    {
        return (string) config('localization.fallback', 'zh-CN');
    }

    /** 是否为官方支持语言。 */
    public static function supports(string $locale): bool
    {
        return in_array($locale, self::supported(), true);
    }

    /** 该语言的 URL 前缀（默认语言为空）。 */
    public static function prefix(string $locale): string
    {
        $map = (array) config('localization.prefixes', []);

        return (string) ($map[$locale] ?? '');
    }

    /** 由 URL 前缀反查语言；默认（空前缀）返回 null（调用方按默认语言处理）。 */
    public static function fromPrefix(string $prefix): ?string
    {
        foreach ((array) config('localization.prefixes', []) as $locale => $p) {
            if ($p !== '' && $p === $prefix) {
                return $locale;
            }
        }

        return null;
    }
}
