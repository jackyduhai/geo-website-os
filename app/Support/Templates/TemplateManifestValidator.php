<?php

namespace App\Support\Templates;

use App\Support\Blocks\BlockRegistry;
use App\Support\Localization\LocaleRegistry;
use App\Support\Theme\ThemeManager;

/**
 * Template Package Manifest 校验器（P-STEP 18L-3a）。
 * --------------------------------------------------
 * 单一职责：读取模板包 manifest.json 并校验其元数据与依赖契约：
 *
 *   - id / name / version(SemVer)
 *   - industry（Business OS 枚举）
 *   - locales（已在 LocaleRegistry 注册）
 *   - theme（已安装主题存在）
 *   - requires.components（已在 BlockRegistry 注册）
 *   - pages（非空）
 *
 * 只返回可读错误列表（空数组 = 有效），不抛异常、不写状态，便于
 * TemplatePackageManager 在引导期失败安全地收集无效包。
 */
class TemplateManifestValidator
{
    /** Business OS Template Matrix 行业标识（8 类）。 */
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

    /**
     * 校验指定模板包目录。
     *
     * @param  string  $packPath  包目录绝对路径（.../resources/templates/{id}）
     * @return array<int,string> 错误信息列表；为空表示清单有效
     */
    public static function validate(string $packPath): array
    {
        $errors = [];
        $dirName = basename(rtrim($packPath, '/\\'));
        $manifestPath = $packPath . '/manifest.json';

        if (! is_file($manifestPath)) {
            return ['缺少 manifest.json 清单文件'];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return ['manifest.json 不是合法 JSON'];
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

        // industry
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

        return $errors;
    }

    /** 包清单是否有效。 */
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
