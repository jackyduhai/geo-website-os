<?php

namespace App\Support\Blocks;

/**
 * 组合策略（Composition Policy）。
 * --------------------------------------------------
 * Section Composer Lite（TD-133）的安全边界：在「允许管理员零代码调整页面
 * 结构」与「不破坏页面稳定 / SEO / GEO」之间设限。所有约束 fail-closed。
 *
 *   - 限制单个槽位最大区块数量（防止无限堆叠 / 页面失控）；
 *   - 声明 block → 组件映射与该 block 在 v1.0 可切换的 variant 白名单；
 *   - 声明每个渲染区块必须携带 Section 语义（由 SectionSemantic 保证）。
 *
 * 边界：只做策略裁决，不渲染、不保存、不复制业务事实。模板槽位是否允许
 * 某 block 仍由 TemplateDefinition::allows + BlockType::allows 决定。
 */
final class CompositionPolicy
{
    /** 单个槽位可容纳的最大区块数量（含隐藏区块）。 */
    public const MAX_BLOCKS_PER_SLOT = 20;

    /**
     * block type → 组件 key（消费 Component Registry 的 variant）。
     * v1.0 仅 Hero 打通「选 variant → 渲染」；其余组件保持现状。
     */
    private const COMPONENT_FOR_BLOCK = [
        'hero' => 'hero',
    ];

    /**
     * 各 block 在 v1.0 暴露、可由后台切换的 variant 白名单。
     * Hero 的 split（左右分栏）/ center（居中文字）布局 CSS 完整、数据结构相同；
     * default / image（轮播）/ product（产品主视觉）需额外结构，保留到 v1.1。
     */
    private const EXPOSED_VARIANTS = [
        'hero' => ['split', 'center'],
    ];

    /** 槽位现有区块数是否已达上限。 */
    public static function maxReached(int $current): bool
    {
        return $current >= self::MAX_BLOCKS_PER_SLOT;
    }

    /** block 对应的组件 key（无映射返回 null）。 */
    public static function componentForBlock(string $type): ?string
    {
        return self::COMPONENT_FOR_BLOCK[$type] ?? null;
    }

    /** block 在 v1.0 可切换的 variant 列表（无则空数组）。 */
    public static function allowedVariants(string $type): array
    {
        return self::EXPOSED_VARIANTS[$type] ?? [];
    }

    /** 某 variant 是否在该 block 的白名单内（fail-closed）。 */
    public static function isAllowedVariant(string $type, string $variant): bool
    {
        $allowed = self::EXPOSED_VARIANTS[$type] ?? null;

        return $allowed !== null && in_array($variant, $allowed, true);
    }

    /** 每个渲染区块都必须携带受控 Section 语义。 */
    public static function semanticRequired(): bool
    {
        return true;
    }
}
