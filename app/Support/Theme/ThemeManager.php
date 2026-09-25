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

    /**
     * 请求级「预览主题」覆盖（P-STEP 17E）。
     * 非 null 时 active() 返回该主题，但**不写入站点设置、不改变激活态**，
     * 仅供后台「预览」在当前请求内临时以指定主题渲染；preview() 用 try/finally
     * 保证退出时复位，不向后续请求泄漏（与 RequestScopedState 同纪律）。
     */
    private static ?string $previewName = null;

    /** 当前激活主题名（缺省 / 未知 → default；预览请求内返回预览主题） */
    public static function active(): string
    {
        // 预览覆盖优先：直接返回、不写入激活 memo（避免预览名在复位后残留）。
        if (self::$previewName !== null) {
            return self::exists(self::$previewName) ? self::$previewName : self::DEFAULT_THEME;
        }

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
                'name'         => (string) $manifest['name'],
                'slug'         => $name,
                'version'      => (string) ($manifest['version'] ?? '0.0.0'),
                'description'  => (string) ($manifest['description'] ?? ''),
                'capabilities' => array_values(array_map('strval', (array) ($manifest['capabilities'] ?? []))),
            ];
        }

        return $themes;
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    /**
     * 激活主题自带的视觉种子（P-STEP 18I / 18G：主题只提供视觉 token，不再整页覆盖视图）。
     *
     * 读取激活主题 theme.json 的 `tokens`（键同 theme_* 种子），并按
     * {@see ThemePresets::allowedKeys()} 白名单过滤，主题无法注入非外观设置。
     * default 主题或无 tokens → []。主题 token 是**默认视觉层**，站点后台显式外观
     * 设置（Custom Brand / Preset 写入的 theme_*）在 ThemePalette 中覆盖之。
     *
     * @return array<string,string>
     */
    public static function activeTokens(): array
    {
        $active = self::active();
        if ($active === self::DEFAULT_THEME) {
            return [];
        }

        $manifestPath = self::basePath() . '/' . $active . '/theme.json';
        if (! is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $tokens = is_array($manifest) ? (array) ($manifest['tokens'] ?? []) : [];

        $allowed = array_flip(ThemePresets::allowedKeys());

        return array_map('strval', array_intersect_key($tokens, $allowed));
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

    /**
     * 在当前调用栈内临时以指定主题渲染（P-STEP 17E 后台预览）。
     *
     * 仅设置请求级 {@see $previewName} 并重注册查找器，**不写站点设置、不改激活态**；
     * 回调（通常是一次前台首页的内部子请求）返回后，try/finally 复位覆盖并重注册，
     * 因此预览不会泄漏到激活态或后续请求。主题不存在时由调用方先 {@see exists()} 拦截。
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function preview(string $name, callable $callback): mixed
    {
        $previous = self::$previewName;
        self::$previewName = $name;
        self::$activeMemo = null;
        self::register();

        try {
            return $callback();
        } finally {
            self::$previewName = $previous;
            self::$activeMemo = null;
            self::register();
        }
    }

    /**
     * 发现「已放置目录但清单无效」的主题（P-STEP 17E）。
     *
     * 这些目录不会进入 {@see all()}（因此不可激活），但后台需要可见地告警，
     * 避免运营以为主题已安装却找不到。返回 [目录名 => 原因]，全部只读、不可激活。
     *
     * @return array<string,string>
     */
    public static function invalidThemes(): array
    {
        $out = [];
        $base = self::basePath();
        if (! is_dir($base)) {
            return [];
        }

        $valid = self::all();
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (array_key_exists($slug, $valid)) {
                continue;
            }
            $manifestPath = $dir . '/theme.json';
            if (! is_file($manifestPath)) {
                $out[$slug] = '缺少 theme.json 清单文件';
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                $out[$slug] = 'theme.json 不是合法 JSON';
            } elseif (empty($manifest['name'])) {
                $out[$slug] = 'theme.json 缺少 name 字段';
            } else {
                $out[$slug] = '主题清单无效';
            }
        }

        return $out;
    }

    private static function basePath(): string
    {
        return resource_path('themes');
    }
}
