<?php

namespace App\Support\Blocks;

use App\Models\Entity;

/**
 * 区块（Section）面向 AI / GEO 的机器语义层（TD-132，吸收 TD-121）。
 * ------------------------------------------------------------------
 * 单一事实源：每个 block 根容器输出受控的 data-section / data-purpose /
 * data-entity / data-conversion，值全部来自本类受控词表（非自由文本）。
 *
 * 与 JSON-LD（实体级事实）、aria（无障碍）互补：data-* 只描述「这一区的意图」。
 * 默认映射见 docs/audit/geo-section-semantic-standard-18l4.md §4。
 */
final class SectionSemantic
{
    public const SECTIONS = [
        'hero', 'about', 'trust', 'product', 'solution', 'case',
        'certificate', 'faq', 'contact', 'conversion', 'navigation',
    ];

    public const PURPOSES = ['brand', 'trust', 'education', 'comparison', 'conversion'];

    public const ENTITIES = [
        'Organization', 'Product', 'Service', 'Factory', 'Content', 'Person', 'Location', 'Case',
    ];

    public const CONVERSIONS = [
        'contact', 'download', 'consult', 'purchase', 'demo', 'appointment', 'signup',
    ];

    /**
     * block type => [section, purpose, entity, conversion]。
     * null 表示该属性省略；entity=null 的详情块由 Render Context 细化。
     */
    private const DEFAULTS = [
        // 普通组合块
        'hero'           => ['hero',       'brand',      'Organization', null],
        'rich_text'      => ['about',      'education',  'Content',      null],
        'image'          => ['about',      'brand',      'Content',      null],
        'media_text'     => ['about',      'education',  'Content',      null],
        'feature_grid'   => ['solution',   'comparison', 'Product',      null],
        'stats'          => ['trust',      'trust',      'Organization', null],
        'logo_cloud'     => ['trust',      'trust',      'Organization', null],
        'faq'            => ['faq',        'education',  'Content',      null],
        'testimonial'    => ['case',       'trust',      'Case',         null],
        'cta'            => ['conversion', 'conversion', 'Organization', 'contact'],
        'contact_info'   => ['contact',    'conversion', 'Organization', 'contact'],
        'breadcrumb'     => ['navigation', null,         null,           null],
        'product_grid'   => ['product',    'comparison', 'Product',      'contact'],
        'service_grid'   => ['solution',   'comparison', 'Service',      'consult'],
        'content_grid'   => ['solution',   'education',  'Content',      null],
        'form_reference' => ['contact',    'conversion', 'Content',      'contact'],
        // Entity 详情系统块（entity 由 Context 提供 Product / Service）
        'entity_hero'           => ['hero',       'brand',      null, 'contact'],
        'entity_specifications' => ['product',    'education',  null, null],
        'entity_steps'          => ['solution',   'education',  null, null],
        'entity_relations'      => ['solution',   'comparison', null, null],
        'bottom_cta'            => ['conversion', 'conversion', 'Organization', 'contact'],
        // 固定系统页主体
        'sys_solutions'  => ['solution',   'education',  'Service',      'consult'],
        'sys_products'   => ['product',    'comparison', 'Product',      'contact'],
        'sys_knowledge'  => ['faq',        'education',  'Content',      null],
        'sys_about'      => ['about',      'brand',      'Organization', null],
        'sys_factory'    => ['trust',      'trust',      'Factory',      null],
        'sys_cooperation'=> ['conversion', 'conversion', 'Organization', 'contact'],
    ];

    /**
     * 解析 block 的语义四元组。
     * config/blocks.php 的显式 semantic 声明优先；否则用内置默认；
     * 详情块 entity 缺省时由 Render Context 细化（Product / Service）。
     *
     * @return array{section:?string,purpose:?string,entity:?string,conversion:?string}
     */
    public static function for(BlockType $type, array $context = []): array
    {
        if ($type->semantic !== []) {
            $section    = $type->semantic['section'] ?? null;
            $purpose    = $type->semantic['purpose'] ?? null;
            $entity     = $type->semantic['entity'] ?? null;
            $conversion = $type->semantic['conversion'] ?? null;
        } else {
            [$section, $purpose, $entity, $conversion] =
                self::DEFAULTS[$type->type] ?? [null, null, null, null];
        }

        if ($entity === null) {
            $entity = self::entityFromContext($context);
        }

        return compact('section', 'purpose', 'entity', 'conversion');
    }

    /** 生成根标签 data-* 属性串（值受控，e() 转义双保险）。 */
    public static function attributes(BlockType $type, array $context = []): string
    {
        return self::buildAttributes(self::for($type, $context));
    }

    /**
     * 为 system block 内部的固定叙事区段显式生成语义属性（TD-132）。
     * 值必须落在受控词表内，非法值 fail-closed（抛 InvalidArgumentException）；
     * null 的属性不输出。
     */
    public static function forSection(
        string $section,
        string $purpose,
        ?string $entity = null,
        ?string $conversion = null
    ): string {
        $declared = array_filter(
            compact('section', 'purpose', 'entity', 'conversion'),
            static fn ($value) => $value !== null
        );
        $errors = self::validateDeclared($declared);
        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Invalid section semantic: ' . implode('; ', $errors));
        }

        return self::buildAttributes($declared);
    }

    /** @param array<string,?string> $sem 已校验的语义四元组。 */
    private static function buildAttributes(array $sem): string
    {
        $parts = [];
        if (! empty($sem['section'])) {
            $parts[] = 'data-section="' . e((string) $sem['section']) . '"';
        }
        if (! empty($sem['purpose'])) {
            $parts[] = 'data-purpose="' . e((string) $sem['purpose']) . '"';
        }
        if (! empty($sem['entity'])) {
            $parts[] = 'data-entity="' . e((string) $sem['entity']) . '"';
        }
        if (! empty($sem['conversion'])) {
            $parts[] = 'data-conversion="' . e((string) $sem['conversion']) . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * 校验一份显式 semantic 声明（config / manifest），返回错误信息数组。
     * 安全项 fail-closed：非法受控值 → ERROR。
     *
     * @return list<string>
     */
    public static function validateDeclared(?array $semantic): array
    {
        if ($semantic === null || $semantic === []) {
            return [];
        }

        $errors = [];
        if (isset($semantic['section']) && ! in_array($semantic['section'], self::SECTIONS, true)) {
            $errors[] = "非法 data-section：{$semantic['section']}";
        }
        if (isset($semantic['purpose']) && ! in_array($semantic['purpose'], self::PURPOSES, true)) {
            $errors[] = "非法 data-purpose：{$semantic['purpose']}";
        }
        if (isset($semantic['entity']) && ! in_array($semantic['entity'], self::ENTITIES, true)) {
            $errors[] = "非法 data-entity：{$semantic['entity']}";
        }
        if (isset($semantic['conversion']) && ! in_array($semantic['conversion'], self::CONVERSIONS, true)) {
            $errors[] = "非法 data-conversion：{$semantic['conversion']}";
        }

        return $errors;
    }

    /** 校验全部内置默认映射（启动 / 测试用），返回错误信息数组。 */
    public static function validateDefaults(): array
    {
        $errors = [];
        foreach (self::DEFAULTS as $type => $row) {
            [$section, $purpose, $entity, $conversion] = $row;
            foreach (self::validateDeclared([
                'section' => $section,
                'purpose' => $purpose,
                'entity' => $entity,
                'conversion' => $conversion,
            ]) as $message) {
                $errors[] = "{$type}: {$message}";
            }
        }

        return $errors;
    }

    /** 从 Render Context 推断当前 Entity 的 Schema 类型。 */
    private static function entityFromContext(array $context): ?string
    {
        if (! empty($context['entityType']) && is_string($context['entityType'])
            && in_array($context['entityType'], self::ENTITIES, true)) {
            return $context['entityType'];
        }

        $entity = $context['entity'] ?? $context['resource'] ?? null;
        if ($entity instanceof Entity) {
            return match ($entity->type) {
                Entity::TYPE_PRODUCT => 'Product',
                Entity::TYPE_SERVICE => 'Service',
                default             => null,
            };
        }

        return null;
    }
}
