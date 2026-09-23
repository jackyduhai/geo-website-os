<?php

namespace App\Support\Templates;

/**
 * 页面模板定义（不可变值对象）。
 * --------------------------------------------------
 * Template 只负责"结构"：声明有哪些槽位（Slot）、每个槽位允许哪些 block、
 * 使用哪个 Blade 布局。模板不存任何真实内容 / 业务事实。
 *
 * 槽位结构：['slotName' => ['label' => '标签', 'blocks' => '*' | block type 数组]]。
 * 继承（extends）的槽位由 TemplateRegistry 在加载时合并，子类覆盖同名槽位。
 */
final class TemplateDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $extends,
        public readonly array $slots,
        public readonly string $layout,
    ) {}

    public static function fromConfig(string $key, array $cfg, string $defaultLayout, array $slots): self
    {
        return new self(
            key: $key,
            label: $cfg['label'] ?? $key,
            extends: $cfg['extends'] ?? null,
            slots: $slots,
            layout: $cfg['layout'] ?? $defaultLayout,
        );
    }

    /** @return array<int,string> */
    public function slotNames(): array
    {
        return array_keys($this->slots);
    }

    public function slot(string $name): ?array
    {
        return $this->slots[$name] ?? null;
    }

    /** 指定槽位是否允许某类型 block。 */
    public function allows(string $slot, string $blockType): bool
    {
        $s = $this->slots[$slot] ?? null;
        if ($s === null) {
            return false;
        }
        $allowed = $s['blocks'] ?? '*';
        if ($allowed === '*' || $allowed === []) {
            return true;
        }

        return in_array($blockType, (array) $allowed, true);
    }
}
