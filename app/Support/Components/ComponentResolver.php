<?php

namespace App\Support\Components;

/**
 * 组件解析器（Component Resolver）。
 * --------------------------------------------------
 * 视图层唯一入口：把「组件 + 变体 + 尺寸」解析为已注册 CSS 类名字符串，
 * Blade 不硬编码变体类名、不写 `if variant==` 分支。
 *
 * 边界：只做声明式映射，不生成样式、不输出业务事实；未知组件 / 变体
 * 回退到组件默认变体，保证渲染不报错。
 */
class ComponentResolver
{
    /**
     * 解析组件变体的 CSS 类名。
     *
     * @param  string  $component  组件 key（button / card / hero / section）
     * @param  string|null  $variant  变体 key，null 取默认变体
     * @param  string|null  $size  尺寸（button：lg / md / sm）
     * @param  string|array  $extra  额外追加的类名
     */
    public static function classes(
        string $component,
        ?string $variant = null,
        ?string $size = null,
        string|array $extra = [],
    ): string {
        $def = ComponentRegistry::get($component);
        if ($def === null) {
            return self::toClassString($extra);
        }

        $v = $def->variant($variant) ?? $def->variant();
        $classes = $v?->classes ?? '';

        if ($size !== null && $size !== '' && $size !== 'md') {
            $classes .= ' ' . self::sizeClass($component, $size);
        }

        $extraString = self::toClassString($extra);
        if ($extraString !== '') {
            $classes .= ' ' . $extraString;
        }

        return trim($classes);
    }

    /** 取组件的语义元数据（type / purpose / entity_support）。 */
    public static function meta(string $component): array
    {
        return ComponentRegistry::get($component)?->meta ?? [];
    }

    /** 取组件允许的语义根标签。 */
    public static function roots(string $component): array
    {
        return ComponentRegistry::get($component)?->roots ?? [];
    }

    /** 尺寸 → CSS 类（当前仅 button 提供 lg / sm）。 */
    private static function sizeClass(string $component, string $size): string
    {
        return match ($component) {
            'button' => 'btn-' . $size,
            default => $component . '-' . $size,
        };
    }

    private static function toClassString(string|array $extra): string
    {
        if (is_string($extra)) {
            return trim($extra);
        }

        return trim(implode(' ', array_map('trim', $extra)));
    }
}
