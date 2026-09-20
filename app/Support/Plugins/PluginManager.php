<?php

namespace App\Support\Plugins;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PluginManager（P-STEP 06：Core / Theme / Plugin / Site Data 边界）
 *
 * 插件 = plugins/{name}/ 目录：
 *   plugin.json        清单：name / version / description / provider（Laravel ServiceProvider 类名）
 *   其余文件           插件自有代码 / 视图 / 资产
 *
 * 契约：
 *   - 插件通过自有 ServiceProvider 注册路由 / 配置 / 视图 / 生命周期监听，
 *     全部限定在自身命名空间与自身路由前缀内，禁止修改 Core；
 *   - 启用状态存于站点设置 plugins_enabled（JSON 数组）；
 *   - 插件缺席 / 清单无效 / 类缺失 → 跳过并视为未启用（不致命）；
 *   - Core 代码不感知任何具体插件（静态扫描锁定）。
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
                'slug'        => $slug,
                'name'        => (string) $manifest['name'],
                'version'     => (string) ($manifest['version'] ?? '0.0.0'),
                'description' => (string) ($manifest['description'] ?? ''),
                'provider'    => (string) $manifest['provider'],
                'path'        => dirname($manifestPath),
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

    /** 启用：写设置并即时 boot（幂等） */
    public static function enable(string $slug): bool
    {
        if (! self::exists($slug) || self::isEnabled($slug)) {
            return self::isEnabled($slug);
        }

        $list = self::enabled();
        $list[] = $slug;
        Setting::set(self::SETTINGS_KEY, json_encode(array_values($list)));
        self::$enabledMemo = null;
        self::register();

        return true;
    }

    /** 停用：写设置，路由等随本次进程生命周期结束后不再注册 */
    public static function disable(string $slug): bool
    {
        $list = array_values(array_filter(self::enabled(), fn ($s) => $s !== $slug));
        Setting::set(self::SETTINGS_KEY, json_encode($list));
        self::$enabledMemo = null;

        return true;
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
