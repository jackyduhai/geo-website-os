<?php

namespace App\Support\Components;

/**
 * 组件定义（不可变值对象）。
 * --------------------------------------------------
 * 描述一个可复用视觉组件：组件 key / 标签 / 允许的语义根标签 / 语义元数据 /
 * 默认变体 / 变体集合 / 可选尺寸。
 *
 * 语义硬约束（GEO 不可破坏）：roots 声明该组件允许的根标签，renderer 必须使用，
 * 禁止为视觉把 section / article / nav / h 退化为纯 <div>。meta 仅为预留的
 * GEO / AI 元数据（type / purpose / entity_support），不扩展业务逻辑。
 */
final class ComponentDefinition
{
    /**
     * @param  array<string,ComponentVariant>  $variants
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $roots,
        public readonly array $meta,
        public readonly string $defaultVariant,
        public readonly array $variants,
        public readonly array $sizes = [],
    ) {}

    /** 从 config/components.php 的单个组件声明构造。 */
    public static function fromConfig(string $key, array $cfg): self
    {
        $semantic = (array) ($cfg['semantic'] ?? []);
        $variants = [];
        foreach ((array) ($cfg['variants'] ?? []) as $vKey => $vCfg) {
            $variants[$vKey] = ComponentVariant::fromConfig($vKey, $vCfg);
        }

        return new self(
            key: $key,
            label: $cfg['label'] ?? $key,
            roots: (array) ($semantic['roots'] ?? []),
            meta: (array) ($semantic['meta'] ?? []),
            defaultVariant: $cfg['default'] ?? (string) array_key_first($variants),
            variants: $variants,
            sizes: (array) ($cfg['sizes'] ?? []),
        );
    }

    /** 取指定变体；未给 key 时取默认变体；不存在返回 null。 */
    public function variant(?string $key = null): ?ComponentVariant
    {
        $key = $key ?: $this->defaultVariant;

        return $this->variants[$key] ?? null;
    }

    /** 是否声明了指定变体。 */
    public function hasVariant(string $key): bool
    {
        return isset($this->variants[$key]);
    }

    /** 该组件是否允许指定语义根标签。 */
    public function allowsRoot(string $tag): bool
    {
        return \in_array(strtolower($tag), array_map('strtolower', $this->roots), true);
    }
}
