<?php

/**
 * 行业视觉预设注册表（P-STEP 18D）。
 *
 * 重要边界：预设**只改变视觉语言**——品牌主色 / 辅色（CTA）/ 中性底色与文字色 /
 * 圆角 / 内容宽度 / 版面密度 / 阴影质感 / 字体。绝不包含任何导航结构（IA）、文案、
 * 区块、行业内容或示例数据；切换预设不会让站点“变成某行业官网”，行业内容只来自
 * 管理员可独立加载的 Example / Demo 数据层（Blank System ≠ Demo Site）。
 *
 * 每个预设的色值都经过 WCAG 对比度挑选：CTA 派生色上的白字、品牌色上的反白文字
 * 均满足可读性（见 tests/Unit/ThemePaletteTest）。
 *
 * tokens 仅允许写入已在 DefaultSettingSeeder 注册的 theme_* 键；theme_primary_dark
 * 一律置空，交由 ThemePalette 从新主色自动派生悬停 / 按下 / 浅底色。
 */

return [

    'professional' => [
        'label'       => '专业商务（默认）',
        'description' => '品牌蓝主色 + 同色系主行动，蓝灰小面积点缀，冷灰中性阶，舒展留白，通用 SaaS 基线。',
        'swatch'      => ['#2563EB', '#64748B', '#F8FAFC'],
        'tokens'      => [
            'theme_primary'      => '#2563EB',
            'theme_primary_dark' => '',
            'theme_accent'       => '#64748B',
            'theme_bg'           => '#F8FAFC',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#1F2937',
            'theme_text_muted'   => '#6B7280',
            'theme_radius'       => '10',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'flat',
        ],
    ],

    'industrial' => [
        'label'       => '工业制造',
        'description' => '钢蓝主色 + 同色系主行动，琥珀小面积点缀，紧凑版面、小圆角、平直描边，硬朗克制。仅视觉。',
        'swatch'      => ['#1D6FA5', '#D97706', '#F5F7F9'],
        'tokens'      => [
            'theme_primary'      => '#1D6FA5',
            'theme_primary_dark' => '',
            'theme_accent'       => '#D97706',
            'theme_bg'           => '#F5F7F9',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#1F2937',
            'theme_text_muted'   => '#5B6470',
            'theme_radius'       => '6',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'compact',
            'theme_shadow'       => 'flat',
        ],
    ],

    'commerce' => [
        'label'       => '消费者 / 零售电商（Consumer）',
        'description' => '玫红主色 + 同色系主行动，暖黄小面积点缀，暖调中性白，大圆角、柔和悬浮投影，活泼有亲和力。仅视觉。',
        'swatch'      => ['#E11D48', '#F59E0B', '#FAF8F6'],
        'tokens'      => [
            'theme_primary'      => '#E11D48',
            'theme_primary_dark' => '',
            'theme_accent'       => '#F59E0B',
            'theme_bg'           => '#FAF8F6',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#29201F',
            'theme_text_muted'   => '#7A6E6A',
            'theme_radius'       => '14',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'soft',
        ],
    ],

    'technology' => [
        'label'       => '科技软件',
        'description' => '靛蓝主色 + 同色系主行动，青色小面积点缀，冷色中性阶，清晰利落，适合数字产品与技术服务。仅视觉。',
        'swatch'      => ['#4F46E5', '#0891B2', '#F7F8FC'],
        'tokens'      => [
            'theme_primary'      => '#4F46E5',
            'theme_primary_dark' => '',
            'theme_accent'       => '#0891B2',
            'theme_bg'           => '#F7F8FC',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#182132',
            'theme_text_muted'   => '#5B6577',
            'theme_radius'       => '10',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'flat',
        ],
    ],

    'education' => [
        'label'       => '教育培训',
        'description' => '紫罗兰主色 + 同色系主行动，青色小面积点缀，柔和浅底、大圆角与轻柔投影，亲切可信赖。仅视觉。',
        'swatch'      => ['#7C3AED', '#06B6D4', '#FAF9FC'],
        'tokens'      => [
            'theme_primary'      => '#7C3AED',
            'theme_primary_dark' => '',
            'theme_accent'       => '#06B6D4',
            'theme_bg'           => '#FAF9FC',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#232029',
            'theme_text_muted'   => '#6B6675',
            'theme_radius'       => '14',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'soft',
        ],
    ],

    'lifestyle' => [
        'label'       => '生活服务',
        'description' => '青碧主色 + 同色系主行动，暖橙小面积点缀，温润中性白，最大圆角与柔和质感，轻松现代。仅视觉。',
        'swatch'      => ['#0D9488', '#F97316', '#FAFAF8'],
        'tokens'      => [
            'theme_primary'      => '#0D9488',
            'theme_primary_dark' => '',
            'theme_accent'       => '#F97316',
            'theme_bg'           => '#FAFAF8',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#212422',
            'theme_text_muted'   => '#66706B',
            'theme_radius'       => '16',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'soft',
        ],
    ],

    'finance' => [
        'label'       => '金融保险',
        'description' => '深海军蓝主色 + 同色系主行动，青绿小面积点缀，克制中性阶、中等圆角，稳重可信、留白严谨。仅视觉。',
        'swatch'      => ['#1E3A8A', '#0D9488', '#F7F8FA'],
        'tokens'      => [
            'theme_primary'      => '#1E3A8A',
            'theme_primary_dark' => '',
            'theme_accent'       => '#0D9488',
            'theme_bg'           => '#F7F8FA',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#1A2233',
            'theme_text_muted'   => '#5C6675',
            'theme_radius'       => '8',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'flat',
        ],
    ],

    'healthcare' => [
        'label'       => '医疗健康',
        'description' => '深青主色 + 同色系主行动，柔和青绿小面积点缀，洁净浅底、柔和圆角与轻投影，传递信任与专业护理。仅视觉。',
        'swatch'      => ['#0E7490', '#14B8A6', '#F5FAFB'],
        'tokens'      => [
            'theme_primary'      => '#0E7490',
            'theme_primary_dark' => '',
            'theme_accent'       => '#14B8A6',
            'theme_bg'           => '#F5FAFB',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#162833',
            'theme_text_muted'   => '#58707E',
            'theme_radius'       => '12',
            'theme_container'    => '1200',
            'theme_font'         => '',
            'theme_density'      => 'comfortable',
            'theme_shadow'       => 'soft',
        ],
    ],

];
