<?php

/**
 * GEO Website OS · 页面模板注册表（声明式）。
 * --------------------------------------------------
 * 模板只定义结构（槽位 Slot + 每槽允许的 block）与 Blade 布局，不存内容。
 * 层级：base → home / landing / listing / detail / contact，子类继承并覆盖槽位。
 *
 * 槽位 blocks：'*' 任意 block；否则为允许的 block type 数组。
 * 注意：18G-1 渲染管线仅落地 main 单槽（Home / Landing）；listing / detail /
 * contact 的多槽在 18G-2 系统页迁移时启用，但注册表此阶段一次定义完整（TD-54）。
 */

return [

    'layout' => 'layouts.site',

    'definitions' => [

        'base' => [
            'label' => '基础模板（抽象）',
            'slots' => [
                'main' => ['label' => '主体', 'blocks' => '*'],
            ],
        ],

        'home' => [
            'label' => '首页',
            'extends' => 'base',
            'slots' => [
                'main' => ['label' => '主体', 'blocks' => [
                    'hero', 'rich_text', 'image', 'media_text', 'feature_grid', 'stats',
                    'product_grid', 'service_grid', 'content_grid', 'logo_cloud',
                    'faq', 'testimonial', 'cta', 'contact_info', 'form_reference',
                ]],
            ],
        ],

        'landing' => [
            'label' => '落地页',
            'extends' => 'base',
            'slots' => [
                'main' => ['label' => '主体', 'blocks' => '*'],
            ],
        ],

        'listing' => [
            'label' => '列表页',
            'extends' => 'base',
            'slots' => [
                'header' => ['label' => '页头', 'blocks' => ['breadcrumb', 'rich_text']],
                'main' => ['label' => '列表', 'blocks' => ['product_grid', 'service_grid', 'content_grid', 'faq']],
                'sidebar' => ['label' => '侧栏', 'blocks' => ['contact_info', 'form_reference', 'rich_text']],
            ],
        ],

        'detail' => [
            'label' => '详情页',
            'extends' => 'base',
            'slots' => [
                'header' => ['label' => '页头', 'blocks' => ['breadcrumb']],
                'main' => ['label' => '主体', 'blocks' => [
                    'rich_text', 'media_text', 'image', 'feature_grid', 'stats',
                    'content_grid', 'faq', 'testimonial', 'contact_info', 'form_reference', 'cta',
                ]],
                'related' => ['label' => '相关', 'blocks' => ['product_grid', 'service_grid', 'content_grid', 'cta']],
            ],
        ],

        'contact' => [
            'label' => '联系页',
            'extends' => 'base',
            'slots' => [
                'header' => ['label' => '页头', 'blocks' => ['breadcrumb', 'rich_text']],
                'main' => ['label' => '主体', 'blocks' => ['contact_info', 'form_reference', 'rich_text']],
            ],
        ],

    ],

];
