<?php

namespace App\Support\Components;

/**
 * 组件注册表（Component Registry）。
 * --------------------------------------------------
 * 单一来源：组件从 config/components.php 声明式加载；本类负责类型化访问、
 * 变体 / 语义根标签查询。控制器 / Blade 不写 `if variant==` 分支，统一经
 * ComponentResolver 解析渲染类名。
 *
 * 边界：本注册表是契约与单一事实源，不重新生成 CSS、不内嵌业务事实，
 * 视觉仍由 layouts/site.blade.php 的注册 CSS 实现。
 */
class ComponentRegistry
{
    /** @var array<string,ComponentDefinition> */
    private static array $components = [];
    private static bool $booted = false;

    /** 从配置加载全部组件（仅一次）。 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        foreach ((array) config('components.components', []) as $key => $cfg) {
            self::$components[$key] = ComponentDefinition::fromConfig($key, $cfg);
        }
        self::$booted = true;
    }

    /** 强制重新加载（测试 / 配置变更场景）。 */
    public static function flush(): void
    {
        self::$components = [];
        self::$booted = false;
    }

    /** @return array<string,ComponentDefinition> */
    public static function all(): array
    {
        self::boot();

        return self::$components;
    }

    public static function get(string $component): ?ComponentDefinition
    {
        self::boot();

        return self::$components[$component] ?? null;
    }

    public static function has(string $component): bool
    {
        return self::get($component) !== null;
    }

    /** 标准交互状态集（config/components.php states）。 */
    public static function states(): array
    {
        return (array) config('components.states', []);
    }
}
