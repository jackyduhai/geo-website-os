<?php

namespace App\Support\Templates;

use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\BlockType;
use App\Support\Localization\LocaleRegistry;
use App\Support\Render\SystemPageRenderContext;

/**
 * Template Recipe / Runtime 校验器（P-STEP 18L-3b）。
 * ------------------------------------------------------------------
 * 三层 Template Validator 的 Layer 2 / Layer 3：
 *
 *   Layer 2 — Recipe Validation
 *     - recipes/*.json 合法、key/target/blocks 结构完整
 *     - block type 已注册、slot 非空
 *     - block content 对照 {@see BlockType::$fields}：required 字段键存在
 *     - grid（dataSource）block 的 source 必须是 {mode:...} 对象
 *       （18L-3a 真实问题：source 写成字符串运行时才 TypeError 500）
 *     - locale map 缺语言 → WARNING
 *
 *   Layer 3 — Runtime Compatibility Validation
 *     - block 允许进入 target 模板的对应槽位（BlockType::allows）
 *     - target 模板存在
 *     - defaults 文件 JSON 合法
 *     - preview 图缺失 → WARNING
 *
 * 与 {@see TemplateManifestValidator}（Layer 1）组合成完整 template:validate。
 * 分级原则：ERROR 阻断、WARNING 放行，不对第三方生态过度严格。
 */
class TemplateRecipeValidator
{
    /** grid source 允许的 mode（与 BlockRegistry 数据源契约一致）。 */
    public const GRID_MODES = ['all', 'line', 'picked', 'current', 'related'];

    /**
     * 对整个包做三层校验。
     *
     * @return array{errors:array<int,string>,warnings:array<int,string>,stats:array}
     */
    public static function inspect(string $packPath): array
    {
        // Layer 1
        $layer1 = TemplateManifestValidator::inspect($packPath);
        $errors = $layer1['errors'];
        $warnings = $layer1['warnings'];

        $stats = ['recipes' => 0, 'blocks' => 0];

        if (is_file($packPath . '/manifest.json')) {
            $manifest = json_decode((string) file_get_contents($packPath . '/manifest.json'), true);
            $declaredLocales = array_map('strval', (array) ($manifest['locales'] ?? []));
        } else {
            $declaredLocales = [];
        }

        // Layer 2 / 3：recipes
        $recipesDir = $packPath . '/recipes';
        if (is_dir($recipesDir)) {
            foreach (glob($recipesDir . '/*.json') ?: [] as $recipeFile) {
                $stats['recipes']++;
                $recipeKey = basename($recipeFile, '.json');
                $recipe = json_decode((string) file_get_contents($recipeFile), true);

                if (! is_array($recipe)) {
                    $errors[] = "recipe「{$recipeKey}」不是合法 JSON";
                    continue;
                }

                self::validateRecipe($recipe, $recipeKey, $declaredLocales, $errors, $warnings, $stats);
            }
        }

        // defaults 文件 JSON 合法性（Layer 3）
        $defaultsDir = $packPath . '/defaults';
        if (is_dir($defaultsDir)) {
            foreach (glob($defaultsDir . '/*.json') ?: [] as $defaultsFile) {
                $d = json_decode((string) file_get_contents($defaultsFile), true);
                if (! is_array($d)) {
                    $errors[] = 'defaults「' . basename($defaultsFile) . '」不是合法 JSON';
                }
            }
        }

        // migration.json 规则兼容性（TD-131）：保护字段 / 目标 block 注册必须有效。
        foreach (TemplateMigrationRegistry::validate($packPath) as $migrationError) {
            $errors[] = $migrationError;
        }

        // preview 缺失（WARNING）
        if (! is_file($packPath . '/preview/desktop.webp')) {
            $warnings[] = '缺少 preview/desktop.webp 预览图';
        }
        if (! is_file($packPath . '/preview/mobile.webp')) {
            $warnings[] = '缺少 preview/mobile.webp 预览图';
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'stats' => $stats];
    }

    /**
     * 校验单个 recipe（Layer 2 + Layer 3）。
     *
     * @param  array<int,string>  $errors
     * @param  array<int,string>  $warnings
     * @param  array  $stats
     */
    private static function validateRecipe(
        array $recipe,
        string $recipeKey,
        array $declaredLocales,
        array &$errors,
        array &$warnings,
        array &$stats
    ): void {
        $key = trim((string) ($recipe['key'] ?? ''));
        if ($key === '') {
            $errors[] = "recipe「{$recipeKey}」缺少 key 字段";
            return;
        }

        $target = (array) ($recipe['target'] ?? []);
        $targetType = (string) ($target['type'] ?? '');
        if (! in_array($targetType, ['home', 'system', 'page'], true)) {
            $errors[] = "recipe「{$key}」target.type 非法（应为 home/system/page）";
            return;
        }

        if ($targetType === 'system' && trim((string) ($target['key'] ?? '')) === '') {
            $errors[] = "recipe「{$key}」system target 缺少 key";
        }
        if ($targetType === 'page' && trim((string) ($target['slug'] ?? '')) === '') {
            $errors[] = "recipe「{$key}」page target 缺少 slug";
        }

        $template = self::templateFor($recipe, $target);
        $allowedSlots = self::allowedSlots($target);

        $blocks = (array) ($recipe['blocks'] ?? []);
        foreach ($blocks as $i => $b) {
            if (! is_array($b)) {
                $errors[] = "recipe「{$key}」block #{$i} 不是对象";
                continue;
            }

            $stats['blocks']++;
            $type = (string) ($b['type'] ?? '');
            $slot = (string) ($b['slot'] ?? '');
            $where = "recipe「{$key}」block #{$i}（{$type}）";

            if ($type === '') {
                $errors[] = "{$where} 缺少 type";
                continue;
            }

            $blockType = BlockRegistry::get($type);
            if (! $blockType instanceof BlockType) {
                $errors[] = "{$where} type 未注册";
                continue;
            }

            if ($slot === '') {
                $errors[] = "{$where} 缺少 slot";
            } elseif (! in_array($slot, $allowedSlots, true)) {
                $errors[] = "{$where} slot「{$slot}」不允许用于该 target";
            } elseif ($template !== null && ! $blockType->allows($template, $slot)) {
                // Layer 3：block 不允许进入该模板的槽位
                $errors[] = "{$where} 不允许进入模板「{$template}」的槽位「{$slot}」";
            }

            self::validateContent($blockType, (array) ($b['content'] ?? []), $where, $declaredLocales, $errors, $warnings);
        }
    }

    /** 校验 block content 对照 BlockType.fields（required / source / locale map）。 */
    private static function validateContent(
        BlockType $blockType,
        array $content,
        string $where,
        array $declaredLocales,
        array &$errors,
        array &$warnings
    ): void {
        $fieldKeys = [];
        foreach ($blockType->fields as $f) {
            $fkey = (string) ($f['key'] ?? '');
            $fieldKeys[$fkey] = $f;

            if (! empty($f['required']) && ! array_key_exists($fkey, $content)) {
                $errors[] = "{$where} 缺少必填字段「{$fkey}」";
            }
        }

        // 未声明字段（WARNING，忽略系统注入键）
        foreach (array_keys($content) as $ck) {
            if (in_array($ck, ['_recipe', '_recipe_index'], true)) {
                continue;
            }
            if (! array_key_exists($ck, $fieldKeys)) {
                $warnings[] = "{$where} 含未声明字段「{$ck}」";
            }
        }

        // grid source 必须是 {mode:...} 对象（18L-3a 真实问题）
        if ($blockType->dataSource && array_key_exists('source', $content)) {
            $source = $content['source'];
            $sourceModes = self::sourceModes($blockType);
            if (! is_array($source)) {
                $errors[] = "{$where} source 必须是对象，如 {\"mode\":\"all\"}";
            } else {
                $mode = (string) ($source['mode'] ?? '');
                if ($mode === '' || ($sourceModes !== [] && ! in_array($mode, $sourceModes, true))) {
                    $errors[] = "{$where} source.mode「{$mode}」非法（应为 " . implode('/', $sourceModes) . '）';
                }
            }
        }

        // URL 安全（TD-135）：不可信模板中的链接字段必须过 scheme 白名单。
        //   - buttons 字段：逐行检查 url
        //   - 键名含 url/href/link 的文本字段：检查值
        foreach ($blockType->fields as $f) {
            $fkey = (string) ($f['key'] ?? '');
            $ftype = (string) ($f['type'] ?? '');
            if ($ftype === 'buttons') {
                foreach ((array) ($content[$fkey] ?? []) as $row) {
                    $u = trim((string) (is_array($row) ? ($row['url'] ?? '') : ''));
                    if ($u !== '' && ! \App\Support\SafeUrl::isSafe($u)) {
                        $errors[] = "{$where} 按钮链接含不被允许的协议：{$u}";
                    }
                }
            } elseif ($ftype === 'text' && preg_match('/url|href|link/i', $fkey)) {
                $u = trim((string) ($content[$fkey] ?? ''));
                if ($u !== '' && ! \App\Support\SafeUrl::isSafe($u)) {
                    $errors[] = "{$where} 链接字段「{$fkey}」含不被允许的协议：{$u}";
                }
            }
        }

        // locale map 缺语言（WARNING）
        if ($declaredLocales !== []) {
            foreach ($content as $ck => $cv) {
                if (self::isLocaleMap($cv)) {
                    foreach ($declaredLocales as $loc) {
                        if (! array_key_exists($loc, $cv)) {
                            $warnings[] = "{$where} 字段「{$ck}」缺 locale「{$loc}」";
                        }
                    }
                }
            }
        }
    }

    /** recipe target 对应模板（Layer 3 上下文）。 */
    private static function templateFor(array $recipe, array $target): ?string
    {
        if ($target['type'] === 'system') {
            $key = (string) ($target['key'] ?? '');
            return SystemPageRenderContext::DEFINITIONS[$key][0] ?? null;
        }

        if ($target['type'] === 'home') {
            return (string) ($recipe['template'] ?? 'home');
        }

        return (string) ($recipe['template'] ?? 'landing');
    }

    /** target 允许写入的槽位（与 RecipeApplier 同口径）。 */
    private static function allowedSlots(array $target): array
    {
        if (($target['type'] ?? '') !== 'system') {
            return ['main'];
        }

        return match ((string) ($target['key'] ?? '')) {
            'contact' => ['header', 'main'],
            'products', 'solutions', 'knowledge' => ['sidebar'],
            default => ['related'],
        };
    }

    /** 读取 block 数据源字段声明的允许 mode（product/service/content grid 各不同）。 */
    private static function sourceModes(BlockType $blockType): array
    {
        foreach ($blockType->fields as $field) {
            if (($field['type'] ?? '') === 'source') {
                return array_map('strval', (array) ($field['modes'] ?? []));
            }
        }

        return [];
    }
    /** 是否为 locale map（所有键均为已注册语言码）。 */
    private static function isLocaleMap(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach (array_keys($value) as $k) {
            if (! is_string($k) || ! LocaleRegistry::supports($k)) {
                return false;
            }
        }

        return true;
    }
}
