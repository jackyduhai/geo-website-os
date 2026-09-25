<?php

/**
 * GEO Website OS · 通用页面组合 Block Registry（声明式）。
 * --------------------------------------------------
 * 每个 block 类型在此声明：标签 / 分组 / 图标 / 编辑字段 schema / 数据源 /
 * 渲染器视图 / 允许槽位 / 是否按语言 / 是否产出结构化数据 / 新建默认内容。
 *
 * 字段 type（供 Admin 动态编辑器）：
 *   text 单行 | textarea 多行 | markdown Markdown 正文 | number 数字 |
 *   select 下拉（options） | checkbox 布尔 | media 媒体 ID（配媒体库） |
 *   items 可增删条目（item_fields 定义每行） | buttons 按钮列表 |
 *   source 数据源（entity + modes，grid 类）。
 *
 * 安全边界：block 只存结构化 JSON，由注册渲染器 site/blocks/{type}.blade.php
 * 生成 HTML；不在 DB 存任意 Blade / HTML / PHP。
 */

return [

    'types' => [

        // ---------------- 首屏 / 内容 ----------------

        'hero' => [
            'label' => 'Hero 首屏',
            'category' => 'section',
            'icon' => 'sparkle',
            'per_locale' => true,
            'fields' => [
                ['key' => 'eyebrow', 'label' => '眉标（顶部小字）', 'type' => 'text'],
                ['key' => 'title', 'label' => '主标题（H1）', 'type' => 'text', 'required' => true],
                ['key' => 'subtitle', 'label' => '副标题 / 引导语', 'type' => 'textarea'],
                ['key' => 'buttons', 'label' => '按钮', 'type' => 'buttons'],
                ['key' => 'image_id', 'label' => '配图（媒体 ID，留空不显示）', 'type' => 'media'],
            ],
            'default' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'buttons' => [], 'image_id' => null],
        ],

        'rich_text' => [
            'label' => '富文本',
            'category' => 'section',
            'icon' => 'doc',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'body', 'label' => '正文（Markdown）', 'type' => 'markdown'],
            ],
            'default' => ['title' => '', 'body' => ''],
        ],

        'image' => [
            'label' => '单图',
            'category' => 'media',
            'icon' => 'grid',
            'per_locale' => true,
            'fields' => [
                ['key' => 'media_id', 'label' => '图片（媒体 ID）', 'type' => 'media', 'required' => true],
                ['key' => 'alt', 'label' => '替代文本（alt）', 'type' => 'text'],
                ['key' => 'caption', 'label' => '图片说明（留空不显示）', 'type' => 'text'],
            ],
            'default' => ['media_id' => null, 'alt' => '', 'caption' => ''],
        ],

        'media_text' => [
            'label' => '图文混排',
            'category' => 'section',
            'icon' => 'grid',
            'per_locale' => true,
            'fields' => [
                ['key' => 'media_id', 'label' => '图片（媒体 ID）', 'type' => 'media'],
                ['key' => 'alt', 'label' => '图片替代文本', 'type' => 'text'],
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'body', 'label' => '正文（Markdown）', 'type' => 'markdown'],
                ['key' => 'button_label', 'label' => '按钮文案（留空不显示按钮）', 'type' => 'text'],
                ['key' => 'button_url', 'label' => '按钮链接', 'type' => 'text'],
                ['key' => 'reverse', 'label' => '图片在右（默认在左）', 'type' => 'checkbox'],
            ],
            'default' => ['media_id' => null, 'alt' => '', 'title' => '', 'body' => '',
                'button_label' => '', 'button_url' => '', 'reverse' => false],
        ],

        // ---------------- 网格（静态内容） ----------------

        'feature_grid' => [
            'label' => '特性网格',
            'category' => 'section',
            'icon' => 'sparkle',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text'],
                ['key' => 'columns', 'label' => '每行数量（2-4）', 'type' => 'number'],
                ['key' => 'items', 'label' => '特性条目', 'type' => 'items', 'item_fields' => [
                    ['key' => 'icon', 'label' => '图标', 'type' => 'select', 'options' => [
                        'sparkle', 'shield', 'sliders', 'package', 'gear', 'check', 'award',
                        'clock', 'users', 'truck', 'leaf', 'flask', 'beaker', 'chart', 'star', 'doc', 'default',
                    ]],
                    ['key' => 'title', 'label' => '标题', 'type' => 'text'],
                    ['key' => 'text', 'label' => '说明', 'type' => 'text'],
                ]],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'columns' => 3, 'items' => []],
        ],

        'stats' => [
            'label' => '数据指标',
            'category' => 'section',
            'icon' => 'chart',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（留空仅显示数字行）', 'type' => 'text'],
                ['key' => 'items', 'label' => '指标条目', 'type' => 'items', 'item_fields' => [
                    ['key' => 'value', 'label' => '数值', 'type' => 'text'],
                    ['key' => 'unit', 'label' => '单位', 'type' => 'text'],
                    ['key' => 'label', 'label' => '含义', 'type' => 'text'],
                ]],
            ],
            'default' => ['title' => '', 'items' => []],
        ],

        'logo_cloud' => [
            'label' => 'Logo 墙',
            'category' => 'section',
            'icon' => 'star',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（留空不显示）', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Logo 条目', 'type' => 'items', 'item_fields' => [
                    ['key' => 'media_id', 'label' => 'Logo（媒体 ID）', 'type' => 'media'],
                    ['key' => 'name', 'label' => '名称（alt）', 'type' => 'text'],
                ]],
            ],
            'default' => ['title' => '', 'items' => []],
        ],

        'faq' => [
            'label' => 'FAQ 常见问题',
            'category' => 'section',
            'icon' => 'shield',
            'per_locale' => true,
            'schema' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text'],
                ['key' => 'items', 'label' => '问答条目', 'type' => 'items', 'item_fields' => [
                    ['key' => 'q', 'label' => '问题', 'type' => 'text'],
                    ['key' => 'a', 'label' => '答案', 'type' => 'textarea'],
                ]],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'items' => []],
        ],

        'testimonial' => [
            'label' => '客户评价',
            'category' => 'section',
            'icon' => 'star',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'items', 'label' => '评价条目', 'type' => 'items', 'item_fields' => [
                    ['key' => 'quote', 'label' => '评价内容', 'type' => 'textarea'],
                    ['key' => 'name', 'label' => '客户姓名', 'type' => 'text'],
                    ['key' => 'role', 'label' => '职务（留空不显示）', 'type' => 'text'],
                    ['key' => 'company', 'label' => '公司（留空不显示）', 'type' => 'text'],
                ]],
            ],
            'default' => ['title' => '', 'items' => []],
        ],

        'cta' => [
            'label' => 'CTA 转化区',
            'category' => 'section',
            'icon' => 'arrow',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'textarea'],
                ['key' => 'buttons', 'label' => '按钮', 'type' => 'buttons'],
                ['key' => 'tint', 'label' => '浅色底（默认勾选）', 'type' => 'checkbox'],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'buttons' => [], 'tint' => true],
        ],

        'contact_info' => [
            'label' => '联系信息',
            'category' => 'section',
            'icon' => 'phone',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'show_phone', 'label' => '显示电话', 'type' => 'checkbox'],
                ['key' => 'show_email', 'label' => '显示邮箱', 'type' => 'checkbox'],
                ['key' => 'show_address', 'label' => '显示地址', 'type' => 'checkbox'],
                ['key' => 'show_social', 'label' => '显示社交媒体', 'type' => 'checkbox'],
            ],
            'default' => ['title' => '', 'show_phone' => true, 'show_email' => true,
                'show_address' => true, 'show_social' => true],
        ],

        'breadcrumb' => [
            'label' => '面包屑',
            'category' => 'section',
            'icon' => 'arrow',
            'per_locale' => false,
            'fields' => [
                ['key' => 'show_current', 'label' => '显示当前页', 'type' => 'checkbox'],
            ],
            'default' => ['show_current' => true],
        ],

        // ---------------- 数据源网格 ----------------

        'product_grid' => [
            'label' => '产品网格（数据源）',
            'category' => 'source',
            'icon' => 'package',
            'per_locale' => true,
            'data_source' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text'],
                ['key' => 'limit', 'label' => '最多显示（0=不限）', 'type' => 'number'],
                ['key' => 'source', 'label' => '数据来源', 'type' => 'source',
                    'entity' => 'product', 'modes' => ['all', 'line', 'picked', 'current', 'related']],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'limit' => 6,
                'source' => ['mode' => 'all', 'line' => '', 'ids' => []]],
        ],

        'service_grid' => [
            'label' => '服务网格（数据源）',
            'category' => 'source',
            'icon' => 'sliders',
            'per_locale' => true,
            'data_source' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text'],
                ['key' => 'limit', 'label' => '最多显示（0=不限）', 'type' => 'number'],
                ['key' => 'source', 'label' => '数据来源', 'type' => 'source',
                    'entity' => 'service', 'modes' => ['all', 'picked', 'related']],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'limit' => 6,
                'source' => ['mode' => 'all', 'ids' => []]],
        ],

        'content_grid' => [
            'label' => '内容网格（数据源）',
            'category' => 'source',
            'icon' => 'doc',
            'per_locale' => true,
            'data_source' => true,
            'fields' => [
                ['key' => 'title', 'label' => '标题（H2）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '副标题', 'type' => 'text'],
                ['key' => 'category_id', 'label' => '栏目 ID（留空=全部栏目）', 'type' => 'number'],
                ['key' => 'limit', 'label' => '最多显示（0=不限）', 'type' => 'number'],
                ['key' => 'source', 'label' => '数据来源', 'type' => 'source',
                    'entity' => 'content', 'modes' => ['latest', 'picked', 'current']],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'category_id' => null, 'limit' => 6,
                'source' => ['mode' => 'latest', 'ids' => []]],
        ],

        // ---------------- 表单 ----------------

        'form_reference' => [
            'label' => '咨询表单',
            'category' => 'form',
            'icon' => 'phone',
            'per_locale' => true,
            'fields' => [
                ['key' => 'title', 'label' => '表单上方标题（留空不显示）', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => '表单上方说明', 'type' => 'textarea'],
                ['key' => 'form_id', 'label' => '引用的表单 ID（留空使用站点默认联系表单）', 'type' => 'number'],
            ],
            'default' => ['title' => '', 'subtitle' => '', 'form_id' => null],
        ],

        // ---------------- Entity Detail 系统块（Entity 直驱，不可手动添加） ----------------
        // 通用、不按行业建块：由当前 Entity（Product / Service）+ Catalog 读模型直驱，
        // 仅存在于 detail 模板固定槽；无数据不渲染。数据归属 Entity，不复制进 Page。

        'entity_hero' => [
            'label' => 'Entity 详情头（系统）',
            'category' => 'system',
            'icon' => 'sparkle',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/header'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：由当前 Entity 直驱呈现详情头（Product 含关键参数卡，Service 为问句式），不可手动添加。',
        ],

        'entity_specifications' => [
            'label' => 'Entity 规格 / 参数表（系统）',
            'category' => 'system',
            'icon' => 'grid',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：由当前 Entity 规格交付表 / 关键参数直驱，无数据不渲染。',
        ],

        'entity_steps' => [
            'label' => 'Entity 步骤 / 流程（系统）',
            'category' => 'system',
            'icon' => 'arrow',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：由当前 Entity 使用步骤 / 流程直驱，无数据不渲染。',
        ],

        'entity_relations' => [
            'label' => 'Entity 关联实体（系统）',
            'category' => 'system',
            'icon' => 'sliders',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main', 'detail/related'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：由当前 Entity 关系直驱（Product 场景 / 相关产品，Service 痛点·组合 / 相邻场景），按 part 渲染。',
        ],

        'bottom_cta' => [
            'label' => '底部统一 CTA（系统）',
            'category' => 'system',
            'icon' => 'arrow',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/related'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：详情页底部统一 CTA（相关 / FAQ / 相邻之后），复用 _bottom_cta，不可手动添加。',
        ],

        // ---------------- 固定系统页主体系统块（is_system Page，不可手动添加） ----------------
        // 强结构、数据驱动主体由对应 Site 控制器从 Catalog / Pages / siteSettings 准备后
        // 经 SystemPageRenderContext 注入；block 自身不查库、不存业务事实、不可手动添加。
        // 页头（page-hero）一并由系统块按真实事实渲染，换品牌 / 行业数据即自动变化。

        'sys_solutions' => [
            'label' => '场景总览主体（系统）',
            'category' => 'system',
            'icon' => 'grid',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['listing/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：应用场景总览场景网格，数据来自 Catalog，不可手动添加。',
        ],

        'sys_products' => [
            'label' => '产品总览主体（系统）',
            'category' => 'system',
            'icon' => 'package',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['listing/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：产品总览按系列分组 + 系列锚点，数据来自 Catalog，不可手动添加。',
        ],

        'sys_knowledge' => [
            'label' => '知识总览主体（系统）',
            'category' => 'system',
            'icon' => 'doc',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['listing/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：知识总览频道导航 + 最新文章，数据来自 Catalog / Pages，不可手动添加。',
        ],

        'sys_about' => [
            'label' => '关于我们主体（系统）',
            'category' => 'system',
            'icon' => 'doc',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：关于我们 profile / history / culture 三页主体，按系统页 key 渲染，不可手动添加。',
        ],

        'sys_factory' => [
            'label' => '工厂实力主体（系统）',
            'category' => 'system',
            'icon' => 'factory',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：工厂实力数据条 / 车间 / 流程 / 资质 / 覆盖，数据来自 Catalog，不可手动添加。',
        ],

        'sys_cooperation' => [
            'label' => '合作方式主体（系统）',
            'category' => 'system',
            'icon' => 'check',
            'system' => true,
            'per_locale' => true,
            'data_source' => false,
            'allowed' => ['detail/main'],
            'fields' => [],
            'default' => [],
            'help' => '系统块：合作方式 / 流程 / FAQ，数据来自 Catalog / Pages，不可手动添加。',
        ],

    ],

];
