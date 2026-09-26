<?php

namespace App\Support\Templates;

use App\Support\Blocks\BlockRegistry;

/**
 * Template Migration Registry（P-STEP 18L-4b-3，TD-131 Lite）。
 * --------------------------------------------------
 * 模板生态升级兼容的「声明式规则」入口。迁移规则全部放在模板包内的
 * migration.json（JSON 驱动），**不允许** PHP / Blade / JS migration code：
 *
 *   {
 *     "id": "{pack}",
 *     "paths": [{
 *       "from": "1.0.0", "to": "1.1.0",
 *       "rules": {
 *         "rename_fields":   [{ "block": "hero", "from": "subtitle", "to": "description" }],
 *         "replace_blocks":  [{ "from": "feature_list", "to": "feature_grid" }],
 *         "rename_tokens":   [{ "from": "primary_color", "to": "brand_primary" }],
 *         "rename_variants": [{ "block": "hero", "from": "full", "to": "image" }]
 *       }
 *     }]
 *   }
 *
 * 四类规则：
 *   - rename_fields：某 block content 的字段键重命名（结构，不改文本值）；
 *   - replace_blocks：旧 block type 整体替换为新 block（content 保留可复用部分）；
 *   - rename_tokens：配置中引用的设计 token 标识符重命名（精确字符串匹配）；
 *   - rename_variants：某 block 的 component variant 取值重命名。
 *
 * 安全边界（fail-closed）：
 *   - 字段重命名不得触及保护字段（URL / canonical / SEO / Schema）；
 *   - replace_blocks 的目标 block 必须当前已注册，否则迁移后 type 无效；
 *   - 规则只改结构键 / type / variant / token 引用，不改业务文本与公开契约。
 */
final class TemplateMigrationRegistry
{
    public const MIGRATION_FILE = 'migration.json';

    public const RENAME_FIELDS = 'rename_fields';
    public const REPLACE_BLOCKS = 'replace_blocks';
    public const RENAME_TOKENS = 'rename_tokens';
    public const RENAME_VARIANTS = 'rename_variants';

    public const RULE_KEYS = [
        self::RENAME_FIELDS,
        self::REPLACE_BLOCKS,
        self::RENAME_VARIANTS,
        self::RENAME_TOKENS,
    ];

    /**
     * 字段重命名禁区：迁移不得重命名这些键（公开契约 / SEO / URL / Schema）。
     * 命中精确键名或前缀即判规则非法。
     */
    private const PROTECTED_FIELD_KEYS = ['url', 'href', 'canonical'];
    private const PROTECTED_FIELD_PREFIXES = ['seo_', 'og_', 'schema_'];

    /** 读取包内 migration.json 全部路径（文件缺失返回空数组）。 */
    public static function paths(string $packPath): array
    {
        $file = $packPath . '/' . self::MIGRATION_FILE;
        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        $paths = is_array($data) ? (array) ($data['paths'] ?? []) : [];

        return array_values(array_filter($paths, 'is_array'));
    }

    /** 包是否声明了任意迁移路径。 */
    public static function hasRules(string $packPath): bool
    {
        return self::paths($packPath) !== [];
    }

    /**
     * 取指定版本区间的规则；不指定 from/to 时返回第一条路径的规则。
     * 无匹配返回 null。
     *
     * @return array{rules:array,from:string,to:string}|null
     */
    public static function rulesFor(string $packPath, ?string $from = null, ?string $to = null): ?array
    {
        foreach (self::paths($packPath) as $path) {
            $pathFrom = trim((string) ($path['from'] ?? ''));
            $pathTo = trim((string) ($path['to'] ?? ''));
            if ($from === null && $to === null) {
                return ['rules' => (array) ($path['rules'] ?? []), 'from' => $pathFrom, 'to' => $pathTo];
            }
            if ($from === $pathFrom && $to === $pathTo) {
                return ['rules' => (array) ($path['rules'] ?? []), 'from' => $pathFrom, 'to' => $pathTo];
            }
        }

        return null;
    }

    /**
     * 校验包内全部迁移规则，返回错误信息列表（为空表示规则安全可用）。
     *
     * @return array<int,string>
     */
    public static function validate(string $packPath): array
    {
        $errors = [];
        $file = $packPath . '/' . self::MIGRATION_FILE;

        if (! is_file($file)) {
            return []; // 未声明迁移规则：合法
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return ['migration.json 不是合法 JSON'];
        }

        $paths = self::paths($packPath);
        if ($paths === []) {
            $errors[] = 'migration.json 缺少合法 paths 数组';
        }

        foreach ($paths as $i => $path) {
            $where = "migration path #{$i}（" . ($path['from'] ?? '?') . ' → ' . ($path['to'] ?? '?') . '）';
            $errors = array_merge($errors, self::validatePath($path, $where));
        }

        return $errors;
    }

    /**
     * 校验单条版本路径。
     *
     * @return array<int,string>
     */
    private static function validatePath(array $path, string $where): array
    {
        $errors = [];

        if (! self::isSemVer((string) ($path['from'] ?? ''))) {
            $errors[] = "{$where} from 版本缺失或非 SemVer";
        }
        if (! self::isSemVer((string) ($path['to'] ?? ''))) {
            $errors[] = "{$where} to 版本缺失或非 SemVer";
        }

        $rules = (array) ($path['rules'] ?? []);

        foreach ((array) ($rules[self::RENAME_FIELDS] ?? []) as $j => $rule) {
            $block = trim((string) ($rule['block'] ?? ''));
            $fromKey = trim((string) ($rule['from'] ?? ''));
            $toKey = trim((string) ($rule['to'] ?? ''));
            $at = "{$where} rename_fields #{$j}";
            if ($block === '' || $fromKey === '' || $toKey === '') {
                $errors[] = "{$at} 缺少 block/from/to";

                continue;
            }
            if (self::isProtectedField($fromKey) || self::isProtectedField($toKey)) {
                $errors[] = "{$at} 不得重命名保护字段（URL / canonical / SEO / Schema）：{$fromKey} → {$toKey}";
            }
        }

        foreach ((array) ($rules[self::REPLACE_BLOCKS] ?? []) as $j => $rule) {
            $fromType = trim((string) ($rule['from'] ?? ''));
            $toType = trim((string) ($rule['to'] ?? ''));
            $at = "{$where} replace_blocks #{$j}";
            if ($fromType === '' || $toType === '') {
                $errors[] = "{$at} 缺少 from/to";

                continue;
            }
            if (! BlockRegistry::has($toType)) {
                $errors[] = "{$at} 目标 block「{$toType}」当前未注册，迁移后类型无效";
            }
        }

        foreach ((array) ($rules[self::RENAME_TOKENS] ?? []) as $j => $rule) {
            if (trim((string) ($rule['from'] ?? '')) === '' || trim((string) ($rule['to'] ?? '')) === '') {
                $errors[] = "{$where} rename_tokens #{$j} 缺少 from/to";
            }
        }

        foreach ((array) ($rules[self::RENAME_VARIANTS] ?? []) as $j => $rule) {
            $block = trim((string) ($rule['block'] ?? ''));
            if ($block === ''
                || trim((string) ($rule['from'] ?? '')) === ''
                || trim((string) ($rule['to'] ?? '')) === '') {
                $errors[] = "{$where} rename_variants #{$j} 缺少 block/from/to";
            }
        }

        return $errors;
    }

    /** 字段键是否在迁移禁区。 */
    private static function isProtectedField(string $key): bool
    {
        if (in_array($key, self::PROTECTED_FIELD_KEYS, true)) {
            return true;
        }
        foreach (self::PROTECTED_FIELD_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** 宽松 SemVer 校验（与 ManifestValidator 同口径）。 */
    private static function isSemVer(string $version): bool
    {
        return (bool) preg_match(
            '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z-.]+)?(?:\+[0-9A-Za-z-.]+)?$/',
            $version
        );
    }
}
