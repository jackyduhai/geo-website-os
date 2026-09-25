<?php

/**
 * GEO Website OS · Component Design System Registry（声明式）。
 * --------------------------------------------------
 * 本文件是「可复用视觉组件」的单一事实源：描述每个组件支持哪些变体、
 * 变体消费哪些 token / 渲染为哪些已注册 CSS 类、允许的语义根标签，以及
 * 供 GEO / AI 使用的语义元数据（type / purpose / entity_support）。
 *
 * 分层边界（用户裁定，强制）：
 *   组件描述「我支持什么」；主题描述「我长什么样」；Block 描述「我在哪里使用」。
 *   变体只映射到现有 token + 已注册 CSS 类，不在此重写样式、不内嵌业务事实、
 *   不产生第二套样式管线。视觉仍由 layouts/site.blade.php 的注册 CSS 实现。
 *
 * 安全 / 语义：semantic.roots 声明该组件允许的根标签，renderer 必须使用，
 *   禁止为视觉退化为纯 <div>；semantic.meta 仅为预留元数据，不扩展业务逻辑。
 */

return [

    /*
     * 组件交互状态（所有可交互组件共享的标准状态集）。
     * hover / active / focus / disabled 由注册 CSS 实现；loading 走 .is-loading
     * + aria-busy（TD-36，完整表单链路在 18L-2b 验证）。
     */
    'states' => ['default', 'hover', 'active', 'focus', 'disabled', 'loading'],

    'components' => [

        // ---------------- 行动元素 ----------------

        'button' => [
            'label' => '按钮',
            'semantic' => [
                'roots' => ['button', 'a'],
                'meta' => ['type' => 'cta', 'purpose' => 'conversion', 'entity_support' => false],
            ],
            'default' => 'primary',
            'sizes' => ['lg', 'md', 'sm'],
            'variants' => [
                'primary' => [
                    'label' => '主要（实心 CTA）',
                    'classes' => 'btn btn-primary',
                    'tokens' => ['--cta', '--cta-on', '--cta-dark'],
                ],
                'secondary' => [
                    'label' => '次要（中性面）',
                    'classes' => 'btn-o btn-secondary',
                    'tokens' => ['--surface', '--surface-2', '--ink', '--line'],
                ],
                'outline' => [
                    'label' => '描边（透明底）',
                    'classes' => 'btn btn-outline',
                    'tokens' => ['--line', '--ink', '--brand'],
                ],
                'ghost' => [
                    'label' => '幽灵（反白区）',
                    'classes' => 'btn btn-ghost',
                    'tokens' => ['--inverse-hover', '--on-inverse', '--inverse-line-strong'],
                ],
                'text' => [
                    'label' => '文字按钮',
                    'classes' => 'btn-text',
                    'tokens' => ['--brand', '--brand-dark'],
                ],
            ],
        ],

        // ---------------- 卡片 ----------------

        'card' => [
            'label' => '卡片',
            'semantic' => [
                'roots' => ['article', 'div', 'a', 'li'],
                'meta' => ['type' => 'card', 'purpose' => 'presentation', 'entity_support' => true],
            ],
            'default' => 'default',
            'variants' => [
                'default' => [
                    'label' => '默认（面 + 发丝边）',
                    'classes' => 'card',
                    'tokens' => ['--surface', '--line-soft', '--radius'],
                ],
                'border' => [
                    'label' => '明显描边',
                    'classes' => 'card card-border',
                    'tokens' => ['--surface', '--line', '--radius'],
                ],
                'shadow' => [
                    'label' => '常显阴影',
                    'classes' => 'card card-shadow',
                    'tokens' => ['--surface', '--shadow-sm', '--radius'],
                ],
                'glass' => [
                    'label' => '毛玻璃',
                    'classes' => 'card card-glass',
                    'tokens' => ['--glass-bg', '--glass-line', '--glass-blur'],
                ],
                'minimal' => [
                    'label' => '极简（无面无边）',
                    'classes' => 'card card-minimal',
                    'tokens' => ['--ink', '--ink-muted'],
                ],
            ],
        ],

        // ---------------- 首屏 Hero ----------------

        'hero' => [
            'label' => 'Hero 首屏',
            'semantic' => [
                'roots' => ['section', 'header'],
                'meta' => ['type' => 'hero', 'purpose' => 'conversion', 'entity_support' => true],
            ],
            'default' => 'default',
            'variants' => [
                'default' => [
                    'label' => '轮播首屏',
                    'classes' => 'hero',
                    'tokens' => ['--surface-2', '--brand'],
                ],
                'split' => [
                    'label' => '左右分栏',
                    'classes' => 'hero-split',
                    'tokens' => ['--bg', '--brand', '--ink'],
                ],
                'center' => [
                    'label' => '居中文字',
                    'classes' => 'hero-center',
                    'tokens' => ['--bg', '--brand', '--ink'],
                ],
                'image' => [
                    'label' => '图片轮播',
                    'classes' => 'hb',
                    'tokens' => ['--brand', '--scrim'],
                ],
                'product' => [
                    'label' => '一体化产品主视觉',
                    'classes' => 'hero-int',
                    'tokens' => ['--bg', '--brand', '--surface'],
                ],
            ],
        ],

        // ---------------- 区块 Section ----------------

        'section' => [
            'label' => '区块',
            'semantic' => [
                'roots' => ['section'],
                'meta' => ['type' => 'section', 'purpose' => 'presentation', 'entity_support' => true],
            ],
            'default' => 'default',
            'variants' => [
                'default' => ['label' => '默认', 'classes' => 'sec', 'tokens' => ['--sec-y']],
                'wide' => [
                    'label' => '宽内容',
                    'classes' => 'sec sec-wide',
                    'tokens' => ['--sec-y', '--wrap-wide'],
                ],
                'compact' => [
                    'label' => '紧凑纵向节奏',
                    'classes' => 'sec sec-compact',
                    'tokens' => ['--sec-y-compact'],
                ],
            ],
        ],
    ],
];
