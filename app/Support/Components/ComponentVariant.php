<?php

namespace App\Support\Components;

/**
 * 组件变体定义（不可变值对象）。
 * --------------------------------------------------
 * 描述一个组件变体：变体 key / 标签 / 渲染为哪些已注册 CSS 类 / 消费哪些 token。
 *
 * 边界：变体只做「token + 已注册 CSS 类」的声明式映射，不复制组件 DOM、
 * 不内嵌业务事实、不重新生成样式。
 */
final class ComponentVariant
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $classes,
        public readonly array $tokens = [],
    ) {}

    /** 从 config/components.php 的单个变体声明构造。 */
    public static function fromConfig(string $key, array $cfg): self
    {
        return new self(
            key: $key,
            label: $cfg['label'] ?? $key,
            classes: $cfg['classes'] ?? '',
            tokens: (array) ($cfg['tokens'] ?? []),
        );
    }
}
