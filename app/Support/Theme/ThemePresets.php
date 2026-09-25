<?php

namespace App\Support\Theme;

/**
 * ThemePresets —— 行业视觉预设注册表访问层（P-STEP 18D）。
 *
 * 预设是一组**纯视觉种子**（见 config/theme-presets.php），一键写入当前站点的
 * theme_* 设置；不含任何 IA / 文案 / 内容。写入后由 {@see ThemePalette} 在渲染层
 * 派生完整语义令牌。所有方法只读配置或做纯映射，真正的持久化由 SettingController
 * 在站点作用域内通过 Setting::set 完成（保证多站点隔离与权限门禁）。
 */
class ThemePresets
{
    /**
     * 全部预设（slug => 定义）。
     *
     * @return array<string,array{label:string,description:string,swatch:array<int,string>,tokens:array<string,string>}>
     */
    public static function all(): array
    {
        /** @var array<string,array<string,mixed>> $presets */
        $presets = config('theme-presets', []);

        return $presets;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * 某预设要写入的 theme_* 键值映射；非法预设抛异常（控制器层先 exists() 拦截）。
     *
     * @return array<string,string>
     */
    public static function tokens(string $key): array
    {
        if (! self::exists($key)) {
            throw new \InvalidArgumentException("未知的视觉预设：{$key}");
        }

        return self::all()[$key]['tokens'];
    }

    /**
     * 预设允许写入的全部键白名单（防止配置误写非外观键）。
     *
     * @return string[]
     */
    public static function allowedKeys(): array
    {
        return [
            'theme_primary', 'theme_primary_dark', 'theme_accent',
            'theme_bg', 'theme_surface', 'theme_text', 'theme_text_muted',
            'theme_radius', 'theme_container', 'theme_font',
            'theme_density', 'theme_shadow', 'theme_typography',
        ];
    }
}
