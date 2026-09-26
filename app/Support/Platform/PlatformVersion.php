<?php

namespace App\Support\Platform;

/**
 * 平台与对外契约 API 版本单一来源（TD-130 Template Compatibility Contract）。
 * ------------------------------------------------------------------
 * 模板包 manifest.requires 声明其构建所针对的各 API 版本；
 * TemplateManifestValidator 据此做兼容性校验：同 major、且当前版本 >= 要求版本 → 通过，
 * 主版本不匹配或平台版本过低 → ERROR 阻断。
 *
 * 这是模板生态（官方 / 第三方 / 企业 / AI 生成）的前置兼容契约，避免系统升级后旧包失效。
 */
final class PlatformVersion
{
    /** 契约版本键（manifest.requires 中除 components 外的版本字段）。 */
    public const CONTRACT_KEYS = [
        'platform',
        'theme_api',
        'component_api',
        'geo_contract',
        'seo_contract',
    ];

    /** 当前平台 / 各契约 API 版本（v1 统一 1.0.0）。 */
    public const CURRENT = [
        'platform'      => '1.0.0',
        'theme_api'     => '1.0.0',
        'component_api' => '1.0.0',
        'geo_contract'  => '1.0.0',
        'seo_contract'  => '1.0.0',
    ];

    /** 取指定契约的当前版本；未知键返回 null。 */
    public static function current(string $key): ?string
    {
        return self::CURRENT[$key] ?? null;
    }
}
