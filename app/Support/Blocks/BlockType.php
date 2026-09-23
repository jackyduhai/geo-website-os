<?php

namespace App\Support\Blocks;

use Illuminate\Contracts\View\View;

/**
 * 通用页面组合 Block 类型定义（不可变值对象）。
 *
 * 描述一个 block 的：key / 标签 / 分组 / 图标 / 编辑字段 schema / 数据源 /
 * 渲染器视图 / 允许的槽位与模板 / 是否按语言独立 / 是否产出结构化数据 /
 * 新建时的默认内容。
 *
 * 安全边界（用户裁定 ⑫，强制）：block 只存结构化 JSON，由注册渲染器生成 HTML，
 * 不在 DB 存任意 Blade / HTML / PHP。富文本统一存 Markdown，渲染时转换为安全 HTML。
 */
final class BlockType
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $category,      // section | source | media | form
        public readonly string $icon,
        public readonly array $fields,         // 编辑字段 schema
        public readonly string $view,          // 渲染器 blade
        public readonly array $allowedSlots,   // ['*'] 或 ['template/slot']
        public readonly bool $perLocale,
        public readonly bool $dataSource,      // 是否需解析数据源（grid）
        public readonly bool $hasSchema,       // 是否产出结构化数据（FAQ 等）
        public readonly bool $system,          // 系统块（Entity 直驱），不进“自由添加”
        public readonly array $defaultContent,
        public readonly string $help = '',
    ) {}

    /**
     * 从 config/blocks.php 的声明构造。
     */
    public static function fromConfig(string $type, array $cfg): self
    {
        return new self(
            type: $type,
            label: $cfg['label'] ?? $type,
            category: $cfg['category'] ?? 'section',
            icon: $cfg['icon'] ?? 'grid',
            fields: $cfg['fields'] ?? [],
            view: $cfg['view'] ?? 'site.blocks.' . $type,
            allowedSlots: $cfg['allowed'] ?? ['*'],
            perLocale: (bool) ($cfg['per_locale'] ?? true),
            dataSource: (bool) ($cfg['data_source'] ?? false),
            hasSchema: (bool) ($cfg['schema'] ?? false),
            system: (bool) ($cfg['system'] ?? false),
            defaultContent: $cfg['default'] ?? [],
            help: $cfg['help'] ?? '',
        );
    }

    /** 按 key 取编辑字段定义，不存在返回 null。 */
    public function field(string $key): ?array
    {
        foreach ($this->fields as $f) {
            if (($f['key'] ?? '') === $key) {
                return $f;
            }
        }

        return null;
    }

    /**
     * 该 block 是否允许放入指定模板的指定槽位。
     * allowed 写法：星号（任意）、"模板名"（该模板任意槽）、"模板名/槽位"、"星号/槽位"。
     */
    public function allows(string $templateKey, string $slot): bool
    {
        foreach ($this->allowedSlots as $a) {
            if ($a === '*') {
                return true;
            }
            [$t, $s] = str_contains($a, '/') ? explode('/', $a, 2) : [$a, '*'];
            if (($t === $templateKey || $t === '*') && ($s === $slot || $s === '*')) {
                return true;
            }
        }

        return false;
    }

    /** 构造渲染视图。 */
    public function renderView(array $viewData): View
    {
        return view($this->view, $viewData);
    }
}
