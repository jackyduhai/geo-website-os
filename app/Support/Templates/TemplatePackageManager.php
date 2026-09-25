<?php

namespace App\Support\Templates;

use App\Models\Setting;
use App\Support\PageCache;

/**
 * Template Package Manager（P-STEP 18L-3a）。
 * --------------------------------------------------
 * 模板包生命周期管理，与 {@see \App\Support\Theme\ThemeManager} 平行：
 *
 *   - 物理位置：resources/templates/{id}/（manifest.json + template.json + recipes/）
 *   - all() / exists()：发现并校验有效包（manifest 校验通过）
 *   - invalidPacks()：目录存在但清单无效的包（后台可见告警）
 *   - activate()：写入站点设置 template_active_pack、注册包模板、清页面缓存
 *   - register()：把激活包的包级模板注册进 TemplateRegistry（失败安全）
 *   - recipes()：读取包内配方
 *
 * 激活态是 Site-scoped（Setting），不写死系统；不同站点可激活不同模板包。
 * 包只声明结构与组合，不携带可执行代码，渲染仍走统一 Composition 管线。
 */
class TemplatePackageManager
{
    public const SETTINGS_KEY = 'template_active_pack';

    private static ?string $activeMemo = null;

    public static function basePath(): string
    {
        return resource_path('templates');
    }

    /**
     * 发现全部有效模板包。
     *
     * @return array<string,array> id => 规范化 manifest（含 path）
     */
    public static function all(): array
    {
        $packs = [];
        $base = self::basePath();
        if (! is_dir($base)) {
            return [];
        }

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (! TemplateManifestValidator::isValid($dir)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
            $manifest['path'] = $dir;
            $packs[$id] = $manifest;
        }

        return $packs;
    }

    public static function exists(string $id): bool
    {
        return array_key_exists($id, self::all());
    }

    /**
     * 发现「已放置目录但清单无效」的模板包。
     *
     * @return array<string,array<int,string>> id => 错误列表
     */
    public static function invalidPacks(): array
    {
        $out = [];
        $base = self::basePath();
        if (! is_dir($base)) {
            return [];
        }

        $valid = self::all();
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (array_key_exists($id, $valid)) {
                continue;
            }
            $out[$id] = TemplateManifestValidator::validate($dir);
        }

        return $out;
    }

    /** 当前站点激活的模板包 id（未激活 / 无效 → 空串）。 */
    public static function active(): string
    {
        if (self::$activeMemo !== null) {
            return self::$activeMemo;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            return '';
        }

        $active = trim((string) Setting::get(self::SETTINGS_KEY, ''));
        if ($active !== '' && ! self::exists($active)) {
            $active = '';
        }

        return self::$activeMemo = $active;
    }

    /** 激活模板包（写站点设置、注册包模板、清页面缓存）。 */
    public static function activate(string $id): bool
    {
        if (! self::exists($id)) {
            return false;
        }

        Setting::set(self::SETTINGS_KEY, $id);
        self::$activeMemo = $id;
        self::register();
        PageCache::flush();

        return true;
    }

    /** 停用当前模板包（保留文件，仅清除激活态与包模板注册）。 */
    public static function deactivate(): void
    {
        Setting::set(self::SETTINGS_KEY, '');
        self::$activeMemo = '';
        TemplateRegistry::flushPacks();
        PageCache::flush();
    }

    /**
     * 注册激活包的包级模板（幂等、失败安全）。
     * 环境未就绪 / 包无效时安全跳过，不阻塞应用引导。
     */
    public static function register(): void
    {
        try {
            TemplateRegistry::flushPacks();

            $active = self::active();
            if ($active === '') {
                return;
            }

            $templateFile = self::basePath() . '/' . $active . '/template.json';
            if (! is_file($templateFile)) {
                return;
            }

            $templatePack = json_decode((string) file_get_contents($templateFile), true);
            $definitions = is_array($templatePack)
                ? (array) ($templatePack['definitions'] ?? [])
                : [];

            if ($definitions !== []) {
                TemplateRegistry::registerPack($active, $definitions);
            }
        } catch (\Throwable) {
            self::$activeMemo = null;
        }
    }

    /**
     * 读取指定包的全部配方。
     *
     * @return array<string,array> recipeKey => recipe 数组
     */
    public static function recipes(string $id): array
    {
        $recipes = [];
        $dir = self::basePath() . '/' . $id;
        if (! is_dir($dir . '/recipes')) {
            return [];
        }

        foreach (glob($dir . '/recipes/*.json') ?: [] as $file) {
            $recipe = json_decode((string) file_get_contents($file), true);
            if (is_array($recipe) && ! empty($recipe['key'])) {
                $recipes[basename($file, '.json')] = $recipe;
            }
        }

        return $recipes;
    }

    /** 读取包内单个配方（不存在返回 null）。 */
    public static function recipe(string $id, string $key): ?array
    {
        return self::recipes($id)[$key] ?? null;
    }

    /** 每请求复位激活态记忆（AppServiceProvider::boot 调用）。 */
    public static function resetRequestMemo(): void
    {
        self::$activeMemo = null;
    }
}
