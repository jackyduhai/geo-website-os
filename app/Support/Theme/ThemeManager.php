<?php

namespace App\Support\Theme;

use Illuminate\Support\Facades\View;

/**
 * ThemeManager（P-STEP 05：Engine ≠ Theme）
 *
 * 主题机制（覆盖式，带回退）：
 *   - 主题目录：resources/themes/{name}/theme.json（清单）+ views/（视图）
 *   - 激活主题的 views 目录被 prepend 到视图查找器最前：
 *     同名视图（如 layouts/site、site/home）由主题覆盖；
 *     主题未提供的视图自动回退到基础视图（优雅回退）。
 *   - 激活状态存于站点设置 theme_active（admin 可运营），默认 default。
 *
 * 主题契约：主题只能消费控制器/Composer 注入的公开数据
 * （$seo 归一化数组、$siteSettings、$navTree、路由变量等），
 * 不得查询 Engine 内部 Service / Repository / Facts / 内部缓存。
 */
class ThemeManager
{
    public const DEFAULT_THEME = 'default';
    private const SETTINGS_KEY = 'theme_active';

    private static ?string $activeMemo = null;

    /** 当前激活主题名（缺省 / 未知 → default） */
    public static function active(): string
    {
        if (self::$activeMemo !== null) {
            return self::$activeMemo;
        }

        // 早期启动阶段（如测试环境迁移前）settings 表尚不存在 → 走默认主题
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            return self::DEFAULT_THEME;
        }

        $active = trim((string) \App\Models\Setting::get(self::SETTINGS_KEY, ''));
        if ($active === '' || ! self::exists($active)) {
            $active = self::DEFAULT_THEME;
        }

        return self::$activeMemo = $active;
    }

    /** 发现全部已安装主题（theme.json 清单有效才算） */
    public static function all(): array
    {
        $themes = [];
        foreach (glob(self::basePath() . '/*/theme.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || empty($manifest['name'])) {
                continue;
            }
            $name = basename(dirname($manifestPath));
            $themes[$name] = [
                'name'        => (string) $manifest['name'],
                'version'     => (string) ($manifest['version'] ?? '0.0.0'),
                'description' => (string) ($manifest['description'] ?? ''),
            ];
        }

        return $themes;
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    /** 把激活主题的视图目录置于查找器最前（幂等、可重入；default = 纯基础视图） */
    public static function register(): void
    {
        try {
            self::doRegister();
        } catch (\Throwable) {
            // 环境未就绪时安全跳过（如 composer package:discover 阶段 DB 尚未创建）：
            // 主题解析失败不得阻塞应用引导，回退 default。
            self::$activeMemo = null;
        }
    }

    private static function doRegister(): void
    {
        $finder = View::getFinder();

        // 移除此前注册的主题目录，保留基础视图目录与其它既有路径
        $paths = array_values(array_filter(
            $finder->getPaths(),
            fn ($p) => ! str_starts_with((string) $p, self::basePath())
        ));

        $active = self::active();
        if ($active !== self::DEFAULT_THEME) {
            $themePath = self::basePath() . '/' . $active . '/views';
            if (is_dir($themePath)) {
                array_unshift($paths, $themePath);
            }
        }

        $finder->setPaths($paths);
        $finder->flush();
    }

    /** 激活主题（写入站点设置、重注册查找器并清整页缓存，避免旧主题 HTML 残留） */
    public static function activate(string $name): bool
    {
        if (! self::exists($name)) {
            return false;
        }
        \App\Models\Setting::set(self::SETTINGS_KEY, $name);
        self::$activeMemo = null;
        self::register();
        \App\Support\PageCache::flush();

        return true;
    }

    /** 每请求复位激活态记忆化（AppServiceProvider::boot 调用） */
    public static function resetRequestMemo(): void
    {
        self::$activeMemo = null;
    }

    private static function basePath(): string
    {
        return resource_path('themes');
    }
}
