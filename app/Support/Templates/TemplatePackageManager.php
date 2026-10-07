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
     * 汇总模板包的结构特征（RC-11 F）。
     *
     * 存在的理由：manifest 只声明「这个包是什么行业」，不声明「它长什么样」。
     * 8 个出厂包的 `manifest.json` 差异集中在 industry / identity 等文案，
     * 单看 manifest 无法区分；而真正决定页面形态的是 `recipes/*.json` 里的
     * block 组合与顺序 —— 8 个包的 homepage 结构两两不同（5~7 个 block）。
     *
     * 把这份结构摘要提到 Manager 层而不是控制器里临时拼，
     * 是为了让「模板卡片」「对比页」「活站预览」共用同一口径。
     *
     * @return array{
     *   recipe_count:int, page_list:array<int,string>,
     *   block_counts:array<string,int>, block_total:int,
     *   homepage_blocks:array<int,array{0:string,1:string}>
     * }
     */
    public static function structure(string $id): array
    {
        $empty = [
            'recipe_count'    => 0,
            'page_list'       => [],
            'block_counts'    => [],
            'block_total'     => 0,
            'homepage_blocks' => [],
        ];

        if (! self::exists($id)) {
            return $empty;
        }

        $packPath = self::basePath() . '/' . $id;
        $recipes  = glob($packPath . '/recipes/*.json') ?: [];
        $labels   = self::blockLabels();
        $pages    = [];
        $counts   = [];
        $homepage = [];
        $total    = 0;

        foreach ($recipes as $file) {
            $name = basename($file, '.json');
            $pages[] = $name;

            $recipe = json_decode((string) file_get_contents($file), true);
            $blocks = is_array($recipe['blocks'] ?? null) ? $recipe['blocks'] : [];

            $seq = [];
            foreach ($blocks as $b) {
                $type = (string) ($b['type'] ?? '');
                if ($type === '') {
                    continue;
                }
                $seq[] = $type;
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $total++;
            }

            if ($name === 'homepage') {
                $homepage = $seq;
            }
        }

        return [
            'recipe_count'    => count($recipes),
            'page_list'       => $pages,
            'block_counts'    => $counts,
            'block_total'     => $total,
            // label 取注册表；未注册回落 key 本身（暴露问题而非隐藏）
            'homepage_blocks' => array_map(
                static fn (string $t): array => [$t, $labels[$t] ?? $t],
                $homepage
            ),
        ];
    }

    /**
     * block 类型 key → 中文label（来自 config/blocks.php 的唯一权威定义）。
     *
     * 结构摘要要展示给人看，必须用注册表的 label 而不是裸 key，
     * 否则卡片上会出现 "feature_grid" 这种只有作者懂的词。
     * 未注册的类型回落为 key 本身（暴露问题，而不是静默隐藏）。
     *
     * @return array<string,string>
     */
    public static function blockLabels(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach ((array) (config('blocks.types') ?: []) as $key => $def) {
                $label = (string) ($def['label'] ?? '');
                if ($label !== '') {
                    $cache[(string) $key] = $label;
                }
            }
        }

        return $cache;
    }

    /**
     * 一次性取出全部包的结构摘要（后台模板生态列表用）。
     *
     * @return array<string,array> id => structure()
     */
    public static function structureMatrix(): array
    {
        $out = [];
        foreach (array_keys(self::all()) as $id) {
            $out[$id] = self::structure($id);
        }

        return $out;
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
