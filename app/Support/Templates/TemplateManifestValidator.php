<?php

namespace App\Support\Templates;

use App\Support\Blocks\BlockRegistry;
use App\Support\Localization\LocaleRegistry;
use App\Support\Theme\ThemeManager;

/**
 * Template Package Manifest 校验器（P-STEP 18L-3a；18L-3b 升级 AI/GEO metadata）。
 * ------------------------------------------------------------------
 * 单一职责：读取模板包 manifest.json 并校验其元数据与依赖契约。
 *
 * 18L-3b 三层校验中的 **Layer 1（Manifest Schema Validation）**：
 *   - id / name / version(SemVer)
 *   - industry（Business OS 枚举）
 *   - locales（已在 LocaleRegistry 注册）
 *   - theme（已安装主题存在）
 *   - requires.components（已在 BlockRegistry 注册）
 *   - pages（非空）
 *   - identity / purpose / entities / conversion（AI/GEO 语义，受控白名单）
 *   - seo.recommended_schema / identity.audience（WARNING，建议但不阻断）
 *
 * 返回分级：
 *   - errors（ERROR）：阻断，包不可应用
 *   - warnings（WARNING）：可应用但有风险（第三方生态不过度严格）
 *
 * Recipe / Runtime 层校验由 {@see TemplateRecipeValidator} 负责（Layer 2/3）。
 */
class TemplateManifestValidator
{
    /** Business OS Template Matrix 行业标识（8 类，目录 / industry 字段用）。 */
    public const INDUSTRIES = [
        'manufacturing',
        'saas-technology',
        'brand-commerce',
        'professional-service',
        'healthcare',
        'education',
        'construction-realestate',
        'international-export',
    ];

    /** 业务目标（purpose.primary / secondary，受控）。 */
    public const PURPOSES = [
        'lead_generation',
        'brand_authority',
        'product_showcase',
        'ecommerce_sales',
        'booking',
        'signups',
        'information',
    ];

    /** 业务实体类型（entities，大写、受控）。 */
    public const ENTITIES = [
        'Organization',
        'Product',
        'Service',
        'Factory',
        'Content',
        'Person',
        'Location',
        'Case',
    ];

    /** 转化动作（conversion.primary / secondary，受控）。 */
    public const CONVERSIONS = [
        'inquiry',
        'download',
        'contact',
        'demo',
        'appointment',
        'purchase',
        'signup',
    ];

    /** 推荐 Schema 类型（seo.recommended_schema，受控）。 */
    public const SCHEMA_TYPES = [
        'Organization',
        'WebSite',
        'WebPage',
        'Product',
        'Service',
        'FAQPage',
        'Article',
        'BreadcrumbList',
        'Person',
        'Place',
    ];

    /**
     * Layer 1 完整检查（errors + warnings）。
     *
     * @param  string  $packPath  包目录绝对路径（.../resources/templates/{id}）
     * @return array{errors:array<int,string>,warnings:array<int,string>}
     */
    public static function inspect(string $packPath): array
    {
        $errors = [];
        $warnings = [];
        $dirName = basename(rtrim($packPath, '/\\'));
        $manifestPath = $packPath . '/manifest.json';

        if (! is_file($manifestPath)) {
            return ['errors' => ['缺少 manifest.json 清单文件'], 'warnings' => []];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return ['errors' => ['manifest.json 不是合法 JSON'], 'warnings' => []];
        }

        // id
        $id = trim((string) ($manifest['id'] ?? ''));
        if ($id === '') {
            $errors[] = 'manifest 缺少 id 字段';
        } elseif ($id !== $dirName) {
            $errors[] = "manifest id「{$id}」与目录名「{$dirName}」不一致";
        }

        // name
        if (trim((string) ($manifest['name'] ?? '')) === '') {
            $errors[] = 'manifest 缺少 name 字段';
        }

        // version（SemVer：主.次.补丁，均为数字，可选预发布 / build 后缀）
        $version = trim((string) ($manifest['version'] ?? ''));
        if ($version === '') {
            $errors[] = 'manifest 缺少 version 字段';
        } elseif (! self::isSemVer($version)) {
            $errors[] = "version「{$version}」不符合语义化版本（如 1.0.0）";
        }

        // industry（顶层，向后兼容）
        $industries = array_values(array_map('strval', (array) ($manifest['industry'] ?? [])));
        if ($industries === []) {
            $errors[] = 'manifest 缺少 industry 字段';
        } else {
            foreach ($industries as $ind) {
                if (! in_array($ind, self::INDUSTRIES, true)) {
                    $errors[] = "industry「{$ind}」不在 Business OS 枚举内";
                }
            }
        }

        // locales
        $locales = array_values(array_map('strval', (array) ($manifest['locales'] ?? [])));
        if ($locales === []) {
            $errors[] = 'manifest 缺少 locales 字段';
        } else {
            foreach ($locales as $loc) {
                if (! LocaleRegistry::supports($loc)) {
                    $errors[] = "locale「{$loc}」未在系统注册";
                }
            }
        }

        // theme
        $theme = trim((string) ($manifest['theme'] ?? ''));
        if ($theme === '') {
            $errors[] = 'manifest 缺少 theme 字段';
        } else {
            try {
                if (! ThemeManager::exists($theme)) {
                    $errors[] = "theme「{$theme}」未安装或无效";
                }
            } catch (\Throwable) {
                $errors[] = "theme「{$theme}」无法校验（主题环境未就绪）";
            }
        }

        // requires.components
        $components = array_values(array_map(
            'strval',
            (array) ($manifest['requires']['components'] ?? [])
        ));
        if ($components === []) {
            $errors[] = 'manifest 缺少 requires.components 字段';
        } else {
            foreach ($components as $component) {
                if (! BlockRegistry::has($component)) {
                    $errors[] = "依赖 block「{$component}」未注册";
                }
            }
        }

        // pages
        $pages = array_values(array_map('strval', (array) ($manifest['pages'] ?? [])));
        if ($pages === []) {
            $errors[] = 'manifest 缺少 pages 字段';
        }

        // identity（AI/GEO）
        $identity = (array) ($manifest['identity'] ?? []);
        if ($identity === []) {
            $errors[] = 'manifest 缺少 identity 字段';
        } else {
            if (trim((string) ($identity['name'] ?? '')) === '') {
                $errors[] = 'identity 缺少 name 字段';
            }
            $idIndustry = trim((string) ($identity['industry'] ?? ''));
            if ($idIndustry === '') {
                $errors[] = 'identity 缺少 industry 字段';
            } elseif (! in_array($idIndustry, self::INDUSTRIES, true)) {
                $errors[] = "identity.industry「{$idIndustry}」不在 Business OS 枚举内";
            }
            $audience = array_values(array_filter(array_map(
                'strval',
                (array) ($identity['audience'] ?? [])
            )));
            if ($audience === []) {
                $warnings[] = 'identity 未声明 audience（建议补充目标受众）';
            }
        }

        // purpose（AI/GEO）
        $purpose = (array) ($manifest['purpose'] ?? []);
        $primaryPurpose = trim((string) ($purpose['primary'] ?? ''));
        if ($primaryPurpose === '') {
            $errors[] = 'manifest 缺少 purpose.primary 字段';
        } elseif (! in_array($primaryPurpose, self::PURPOSES, true)) {
            $errors[] = "purpose.primary「{$primaryPurpose}」不在受控业务目标内";
        }
        foreach (array_map('strval', (array) ($purpose['secondary'] ?? [])) as $sp) {
            if ($sp !== '' && ! in_array($sp, self::PURPOSES, true)) {
                $errors[] = "purpose.secondary「{$sp}」不在受控业务目标内";
            }
        }

        // entities（AI/GEO，大写受控）
        $entityList = array_values(array_map('strval', (array) ($manifest['entities'] ?? [])));
        if ($entityList === []) {
            $errors[] = 'manifest 缺少 entities 字段';
        } else {
            foreach ($entityList as $ent) {
                if (! in_array($ent, self::ENTITIES, true)) {
                    $errors[] = "entity「{$ent}」不在受控实体类型内（如 Organization/Product）";
                }
            }
        }

        // conversion（AI/GEO）
        $conversion = (array) ($manifest['conversion'] ?? []);
        $primaryConversion = trim((string) ($conversion['primary'] ?? ''));
        if ($primaryConversion === '') {
            $errors[] = 'manifest 缺少 conversion.primary 字段';
        } elseif (! in_array($primaryConversion, self::CONVERSIONS, true)) {
            $errors[] = "conversion.primary「{$primaryConversion}」不在受控转化动作内";
        }
        foreach (array_map('strval', (array) ($conversion['secondary'] ?? [])) as $sc) {
            if ($sc !== '' && ! in_array($sc, self::CONVERSIONS, true)) {
                $errors[] = "conversion.secondary「{$sc}」不在受控转化动作内";
            }
        }

        // seo.recommended_schema（WARNING）
        $recommended = array_values(array_map(
            'strval',
            (array) ($manifest['seo']['recommended_schema'] ?? [])
        ));
        if ($recommended === []) {
            $warnings[] = 'seo 未声明 recommended_schema（建议补充推荐 Schema）';
        } else {
            foreach ($recommended as $schema) {
                if (! in_array($schema, self::SCHEMA_TYPES, true)) {
                    $errors[] = "seo.recommended_schema「{$schema}」不在受控 Schema 类型内";
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * 校验指定模板包目录（仅返回 ERROR；保持 18L-3a 签名）。
     *
     * @param  string  $packPath  包目录绝对路径
     * @return array<int,string> 错误信息列表；为空表示清单有效
     */
    public static function validate(string $packPath): array
    {
        return self::inspect($packPath)['errors'];
    }

    /** 包清单是否有效（无 ERROR）。 */
    public static function isValid(string $packPath): bool
    {
        return self::validate($packPath) === [];
    }

    /** 宽松 SemVer 校验：MAJOR.MINOR.PATCH（数字），可选 -prerelease 与 +build。 */
    private static function isSemVer(string $version): bool
    {
        return (bool) preg_match(
            '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9A-Za-z-.]+)?(?:\+[0-9A-Za-z-.]+)?$/',
            $version
        );
    }
}
