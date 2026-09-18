<?php

/**
 * 首页装修区块注册表（首页整体可装修的唯一事实源）
 *
 * kind：
 *   hero       首屏（A 参数卡 / C 一体化图文 / B 全幅图片轮播，区块控制模式与开关）
 *   midbanner  首页中部横幅（读 Banner·首页中部位，区块仅控制排序/开关，无图不渲染）
 *   simple     仅标题/副标题（信任带、事实、CTA）
 *   items      可增删的条目（icon/title/text），如能力点、车间、合作方式
 *   steps      有序步骤条目（title/text，序号自动生成）
 *   source     数据来源型：可选来源栏目、条数，并可手动指定具体内容
 *
 * front：该区块在「前台首页」的对应位置 / 锚点 / 路径，供后台装修时一眼对上前端，
 *        避免“不知道这块改的是哪里”。锚点以首页各 blade 真实 id 为准。
 *
 * 可选展示开关（后台装修表单据此隐藏“填了也不生效”的死控件）：
 *   'heading'  => false  该区块不渲染标题/副标题（如纯数字带、纯横幅），后台不显示输入框
 *   'subtitle' => false  渲染标题但不渲染副标题，后台只显示标题
 *
 * 前台按 page_blocks.sort 动态渲染，关闭即不显示，因此首页不是写死的。
 */
return [
    'types' => [
        'hero'         => [
            'label' => '首屏主视觉', 'kind' => 'hero',
            'front' => '首页第 1 屏首屏（锚点 #top，路径 /）：A 参数卡 / B 全幅轮播 / C 一体化主视觉，眉题与 A 文案在此改，B/C 每张图各自带主题文案',
        ],
        'scenes'       => [
            'label' => '应用场景自选（六类客户）', 'kind' => 'items', 'fields' => ['icon', 'title', 'text', 'link'],
            'front' => '首页第 2 屏·应用场景（锚点 #s02，路径 /）：六张客户场景卡，可改文案、换图/图标、改跳转链接',
        ],
        'capabilities' => [
            'label' => '价值主张 / 能力点', 'kind' => 'items', 'fields' => ['icon', 'title', 'text'],
            'front' => '首页·应用场景(#s02)与产品体系(#s03)之间的能力点三卡（路径 /）',
        ],
        'products'     => [
            'label' => '产品体系', 'kind' => 'simple', 'fields' => ['title', 'subtitle'],
            'front' => '首页·五大产品体系卡片（锚点 #s03，路径 /）。产品线、产品数与链接来自核定的结构化产品数据（产品中心单一事实源），不做逐条选稿；此处仅维护区块标题、副标题、排序与显隐。',
        ],
        'params'       => [
            'label' => '参数级交付（配比工艺表）', 'kind' => 'simple',
            'front' => '首页·参数级交付反白配比工艺表（锚点 #s04，路径 /）：六行配比数据取自核定事实，此处改区块标题',
        ],
        'stats'        => [
            'label' => '工厂与产能数据条', 'kind' => 'simple', 'heading' => false,
            'front' => '首页·参数表下方的通栏信任数字条（路径 /）：数字来自单一事实库，仅控制排序与显隐，不单独填标题',
        ],
        'workshops'    => [
            'label' => '四大车间 / 工厂实力', 'kind' => 'items', 'fields' => ['icon', 'title', 'text'],
            'front' => '首页·四大车间（锚点 #s05，路径 /）：可增删条目、改文案、换图/图标',
        ],
        'mid_banner'   => [
            'label' => '首页中部横幅', 'kind' => 'midbanner', 'heading' => false,
            'front' => '首页·四大车间(#s05)与合作方式(#s06)之间的通栏横幅（路径 /）：在此直接传图、改图上文案，无图不渲染',
        ],
        'cooperation'  => [
            'label' => '合作方式（三种模式）', 'kind' => 'items', 'fields' => ['icon', 'title', 'text'],
            'front' => '首页·三种合作方式（锚点 #s06，路径 /）',
        ],
        'steps'        => [
            'label' => '合作流程', 'kind' => 'steps', 'fields' => ['title', 'text'],
            'front' => '首页·五步合作流程（紧跟合作方式 #s06，路径 /）：序号自动生成，可增删步骤',
        ],
        'cases'        => [
            'label' => '客户合作剪影（匿名）', 'kind' => 'items', 'fields' => ['icon', 'title', 'text'],
            'front' => '首页·匿名合作剪影（锚点 #s07，路径 /）：匿名呈现业态与产品组合，可改文案/换图',
        ],
        'knowledge'    => [
            'label' => '知识中心', 'kind' => 'source',
            'front' => '首页·知识中心精选卡（路径 /，点“查看更多”进 /knowledge/）：选知识栏目或手动指定文章',
        ],
        'news'         => [
            'label' => '新闻动态', 'kind' => 'source',
            'front' => '首页·新闻动态卡（路径 /）：选新闻栏目或手动指定文章',
        ],
        'cta'          => [
            'label' => '行动召唤（转化区）', 'kind' => 'simple',
            'front' => '首页·行动召唤转化区（锚点 #s08，路径 /）：标题/副标题即转化主张',
        ],
        'faqs'         => [
            'label' => '首页常见问题 FAQ', 'kind' => 'items', 'fields' => ['title', 'text'],
            'front' => '首页·常见问题 FAQ（锚点 #s09，路径 /）：问题=标题、回答=说明，同时输出 FAQPage 结构化数据',
        ],
        'facts'        => [
            'label' => '主体事实（资质与产能）', 'kind' => 'simple', 'subtitle' => false,
            'front' => '首页底部·资质与产能事实条（路径 /，全量见关于页）：此处只改区块标题；具体条目来自“治理 → 事实库”单一事实源，不在此重复编辑',
        ],
    ],

    // 建议展示顺序（迁移按此初始化 sort，后台可改）——对应策略书 S01–S10
    'sort' => [
        'hero' => 10, 'scenes' => 20, 'capabilities' => 26, 'products' => 34,
        'params' => 44, 'stats' => 50, 'workshops' => 54, 'mid_banner' => 57,
        'cooperation' => 60, 'steps' => 64,
        'cases' => 68,
        'knowledge' => 72, 'news' => 78, 'cta' => 84, 'faqs' => 90, 'facts' => 94,
    ],
];
