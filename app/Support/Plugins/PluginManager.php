<?php

namespace App\Support\Plugins;

use App\Http\Middleware\EnsurePluginEnabled;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PluginManager（P-STEP 06：Core / Theme / Plugin / Site Data 边界；
 * P-STEP 14 / D.3：注册 / 授权分离）
 *
 * 插件 = plugins/{name}/ 目录：
 *   plugin.json        清单：name / version / description / provider（Laravel ServiceProvider 类名）
 *   routes/web.php     可选：插件路由声明（自身前缀内），由 Core 在路由加载期统一注册
 *   其余文件           插件自有代码 / 视图 / 资产
 *
 * 契约：
 *   - 注册与授权分离（D.3）：路由加载期为“所有已安装插件”一次性注册路由，每条路由挂
 *     per-site EnsurePluginEnabled:{slug} 守卫；运行时按当前站点启用态决定 200 / 404，
 *     不再在 enable() 时动态 app()->register provider（动态注册进全局路由单例不可逆、
 *     也无法按站隔离）；
 *   - ServiceProvider 仅承载视图 / 配置等非路由资源，随每请求 register() 重放；
 *   - 启用状态存于站点设置 plugins_enabled（JSON 数组），按站点隔离；
 *   - 插件缺席 / 清单无效 / 类缺失 → 跳过并视为未启用（不致命）；
 *   - Core 代码不感知任何具体插件（静态扫描锁定）：slug 一律来自清单发现，不写死。
 */
class PluginManager
{
    public const SETTINGS_KEY = 'plugins_enabled';

    /** @var array<string,bool> 本进程内已 boot 的插件 */
    private static array $booted = [];

    private static ?array $enabledMemo = null;

    /** 插件根目录 */
    public static function basePath(): string
    {
        return base_path('plugins');
    }

    /** 发现全部插件（清单有效才算） */
    public static function all(): array
    {
        $plugins = [];
        foreach (glob(self::basePath() . '/*/plugin.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || empty($manifest['name']) || empty($manifest['provider'])) {
                continue;
            }
            $slug = basename(dirname($manifestPath));
            $plugins[$slug] = [
                'slug'         => $slug,
                'name'         => (string) $manifest['name'],
                'version'      => (string) ($manifest['version'] ?? '0.0.0'),
                'description'  => (string) ($manifest['description'] ?? ''),
                'provider'     => (string) $manifest['provider'],
                'path'         => dirname($manifestPath),
                'dependencies' => array_values(array_map('strval', (array) ($manifest['dependencies'] ?? []))),
                'capabilities' => array_values(array_map('strval', (array) ($manifest['capabilities'] ?? []))),
            ];
        }
        ksort($plugins);

        return $plugins;
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    /** @return string[] 启用中的插件 slug */
    public static function enabled(): array
    {
        if (self::$enabledMemo !== null) {
            return self::$enabledMemo;
        }
        if (! Schema::hasTable('settings')) {
            return self::$enabledMemo = [];
        }

        $raw = (string) Setting::get(self::SETTINGS_KEY, '[]');
        $list = json_decode($raw === '' ? '[]' : $raw, true);

        return self::$enabledMemo = is_array($list) ? array_values($list) : [];
    }

    public static function isEnabled(string $slug): bool
    {
        return in_array($slug, self::enabled(), true);
    }

    /**
     * 启用：写当前站点设置（幂等）。
     *
     * D.3 起不再动态 app()->register provider：路由已在路由加载期为所有已安装插件注册，
     * 由 EnsurePluginEnabled 守卫按当前站点启用态即时判定，故启用后下一个请求即生效；
     * Provider 的非路由资源随每请求 register() 重放，无需在此引导。
     */
    public static function enable(string $slug): bool
    {
        if (! self::exists($slug)) {
            return false;
        }
        if (self::isEnabled($slug)) {
            return true;
        }

        // 依赖闸门（P-STEP 17E）：依赖插件未安装或未在本站启用时拒绝启用，直接返回
        // false、不写设置，因此不会留下「半启用」状态；UI 据此回友好提示。
        if (self::dependencyBlockers($slug) !== []) {
            return false;
        }

        $list = self::enabled();
        $list[] = $slug;
        Setting::set(self::SETTINGS_KEY, json_encode(array_values(array_unique($list))));
        self::$enabledMemo = null;

        return true;
    }

    /** 停用：写当前站点设置；守卫在下一请求即返回 404（无需等到进程结束） */
    public static function disable(string $slug): bool
    {
        $list = array_values(array_filter(self::enabled(), fn ($s) => $s !== $slug));
        Setting::set(self::SETTINGS_KEY, json_encode($list));
        self::$enabledMemo = null;

        return true;
    }

    /**
     * 计算某插件在「当前站点」启用前尚未满足的依赖（P-STEP 17E）。
     *
     * 依赖声明在 plugin.json 的 `dependencies`（插件 slug 数组，默认空）。
     * 返回 [依赖 slug => 原因]；为空表示依赖已满足（已安装且在本站启用）。
     * 运行时闸门仍是 {@see \App\Http\Middleware\EnsurePluginEnabled}，这里只做
     * 启用前的前置校验与 UI 提示，不替代运行时守卫。
     *
     * @return array<string,string>
     */
    public static function dependencyBlockers(string $slug): array
    {
        $plugins = self::all();
        if (! isset($plugins[$slug])) {
            return [$slug => '插件未安装'];
        }

        $blockers = [];
        foreach ($plugins[$slug]['dependencies'] as $dep) {
            if (! self::exists($dep)) {
                $blockers[$dep] = "依赖插件「{$dep}」未安装";
            } elseif (! self::isEnabled($dep)) {
                $blockers[$dep] = "依赖插件「{$dep}」尚未在当前站点启用";
            }
        }

        return $blockers;
    }

    /**
     * 当前站点已启用、且依赖于 $slug 的插件（停用前置保护，P-STEP 17E）。
     * 返回 [插件 slug => 显示名]；非空时调用方应拒绝停用 $slug，避免把依赖方留在失效态。
     *
     * @return array<string,string>
     */
    public static function enabledDependents(string $slug): array
    {
        $plugins = self::all();
        $out = [];
        foreach (self::enabled() as $enabled) {
            if ($enabled === $slug || ! isset($plugins[$enabled])) {
                continue;
            }
            if (in_array($slug, $plugins[$enabled]['dependencies'], true)) {
                $out[$enabled] = $plugins[$enabled]['name'];
            }
        }

        return $out;
    }

    /**
     * 发现「已放置目录但清单无效」的插件（P-STEP 17E）。
     *
     * 这些目录不会进入 {@see all()}（因此不会注册、不可启用），后台只读告警，
     * 避免运营以为插件已安装。返回 [目录名 => 原因]。
     *
     * @return array<string,string>
     */
    public static function invalidPlugins(): array
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
            $manifestPath = $dir . '/plugin.json';
            if (! is_file($manifestPath)) {
                $out[$slug] = '缺少 plugin.json 清单文件';
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                $out[$slug] = 'plugin.json 不是合法 JSON';
            } elseif (empty($manifest['name'])) {
                $out[$slug] = 'plugin.json 缺少 name 字段';
            } elseif (empty($manifest['provider'])) {
                $out[$slug] = 'plugin.json 缺少 provider 字段';
            } else {
                $out[$slug] = '插件清单无效';
            }
        }

        return $out;
    }

    /**
     * 路由加载期：为“所有已安装插件”一次性注册路由（注册 / 授权分离，D.3）。
     *
     * 仅扫描插件目录中的 routes/web.php（文件系统操作，不查数据库、不看启用态），
     * 为每个插件路由文件套入 web 组（含 ResolveSite，先解析当前站点）并追加 per-site
     * EnsurePluginEnabled:{slug} 守卫。启用 / 停用的运行时判定全部交给守卫，从而：
     *   - 由 bootstrap withRouting 的 then 回调在“每个应用实例”路由注册阶段调用一次，
     *     调用方天然保证单次注册，故此处不使用跨应用的静态防重入标志（测试 / 队列等会
     *     在同一进程重建应用，静态标志会让后续应用实例漏注册插件路由）；
     *   - 同一插件在 A 站启用、B 站停用时，A 200 / B 404 由守卫按当前站点保证；
     *   - Core 不出现任何具体插件 slug 字面量（slug 来自 all() 发现结果）。
     */
    public static function registerInstalledRoutes(): void
    {
        foreach (self::all() as $slug => $plugin) {
            $routesFile = $plugin['path'] . '/routes/web.php';
            if (! is_file($routesFile)) {
                continue;
            }

            Route::middleware(['web', EnsurePluginEnabled::class . ':' . $slug])
                ->group($routesFile);
        }
    }

    /** boot 全部启用插件（AppServiceProvider::boot 调用；enable 后可重入，仅新增生效） */
    public static function register(): void
    {
        try {
            self::doRegister();
        } catch (\Throwable) {
            // 环境未就绪时安全跳过（如 composer package:discover 阶段 DB 尚未创建）：
            // 插件解析失败不得阻塞应用引导。
            self::$booted = [];
        }
    }

    private static function doRegister(): void
    {
        foreach (self::enabled() as $slug) {
            if (isset(self::$booted[$slug])) {
                continue;
            }
            self::$booted[$slug] = true;

            $plugin = self::all()[$slug] ?? null;
            if ($plugin === null) {
                continue;
            }

            $providerClass = $plugin['provider'];
            if (! class_exists($providerClass)) {
                // 插件未进 composer autoload：按 provider 相对路径手工装载
                $candidate = $plugin['path'] . '/providers/' . class_basename($providerClass) . '.php';
                if (is_file($candidate)) {
                    require_once $candidate;
                }
            }

            if (class_exists($providerClass)) {
                app()->register($providerClass);
            }
        }
    }

    /** 每请求复位启用态记忆化与 boot 标记（boot 复位链；与既有 memo 同约定） */
    public static function resetRequestMemo(): void
    {
        self::$enabledMemo = null;
        self::$booted = [];
    }
}
