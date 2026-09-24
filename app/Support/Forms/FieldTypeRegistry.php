<?php

namespace App\Support\Forms;

/**
 * 表单字段类型注册表（P-STEP 18H-2）。
 * --------------------------------------------------
 * 单一来源：类型从 config/forms.php 声明式加载；提供类型化访问、input 形态、
 * 是否需要选项与基础服务端规则。控制器 / Blade 不写 `if type==` 分支。
 *
 * required / 自定义 validation / 选项 Rule::in / checkbox accepted·array 等
 * 「按字段实例」的规则由 FormSubmissionService 叠加，本类只给类型基础。
 */
class FieldTypeRegistry
{
    /** @var array<string,array> */
    private static array $types = [];
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        foreach ((array) config('forms.field_types', []) as $key => $cfg) {
            self::$types[$key] = $cfg;
        }
        self::$booted = true;
    }

    public static function flush(): void
    {
        self::$types = [];
        self::$booted = false;
    }

    /** @return array<string,array> */
    public static function all(): array
    {
        self::boot();

        return self::$types;
    }

    public static function get(string $type): ?array
    {
        self::boot();

        return self::$types[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return self::get($type) !== null;
    }

    public static function label(string $type): string
    {
        return (string) (self::get($type)['label'] ?? $type);
    }

    public static function input(string $type): string
    {
        return (string) (self::get($type)['input'] ?? 'text');
    }

    public static function needsOptions(string $type): bool
    {
        return (bool) (self::get($type)['options'] ?? false);
    }

    /**
     * 字段类型的基础服务端规则（不含 required / 自定义 validation / 选项 in）。
     *
     * @return array<int,string>
     */
    public static function baseRules(string $type): array
    {
        return (array) (self::get($type)['rules'] ?? ['string', 'max:255']);
    }
}
