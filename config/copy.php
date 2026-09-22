<?php

/**
 * GEO Website OS 全局文案默认值（导航 / 页脚 / 表单 / 404 / 合规词表）。
 *
 * 行业中立的出厂默认：产品中心、应用场景等目录型导航与页脚条目不内置任何行业内容，
 * 由各站点自己的 Catalog（Entity 投影）在运行时内容驱动，空目录站自动隐藏对应链接 /
 * 列；联系电话、地址、公司名等默认留空，前台整体隐藏对应区块，站点显示名统一取站点
 * 事实源。部署者可通过后台「站点设置 / 菜单装修」覆盖；完整行业示例仅在 Example Demo
 * （db:seed）中装载，不属于出厂默认。
 */
return array (
  'nav' =>
  array (
    'menu' =>
    array (
      0 =>
      array (
        'label' => '产品中心',
        'href' => '/products/',
        'children' =>
        array (
        ),
      ),
      1 =>
      array (
        'label' => '应用场景',
        'href' => '/solutions/',
        'children' =>
        array (
        ),
      ),
      2 =>
      array (
        'label' => '工厂与资质',
        'href' => '/factory/',
        'children' =>
        array (
          0 =>
          array (
            'label' => '生产车间',
            'href' => '/factory/#workshops',
          ),
          1 =>
          array (
            'label' => '产能与设备',
            'href' => '/factory/#capacity',
          ),
          2 =>
          array (
            'label' => '资质与标准',
            'href' => '/factory/#certifications',
          ),
        ),
      ),
      3 =>
      array (
        'label' => '知识中心',
        'href' => '/knowledge/',
        'children' =>
        array (
        ),
      ),
      4 =>
      array (
        'label' => '关于我们',
        'href' => '/about/profile/',
        'children' =>
        array (
          0 =>
          array (
            'label' => '企业简介',
            'href' => '/about/profile/',
          ),
          1 =>
          array (
            'label' => '发展历程',
            'href' => '/about/history/',
          ),
          2 =>
          array (
            'label' => '企业文化',
            'href' => '/about/culture/',
          ),
          3 =>
          array (
            'label' => '联系我们',
            'href' => '/contact/',
          ),
        ),
      ),
    ),
    'cta' => '联系我们',
    'ctaMobile' => '联系我们',
    'phone' => '',
    'phoneTel' => '',
    'ariaLabels' =>
    array (
      'primaryNav' => '主导航',
      'mobileNav' => '导航菜单',
      'openMenu' => '打开导航菜单',
      'closeMenu' => '关闭导航菜单',
      'phone' => '拨打合作热线',
    ),
  ),
  'footer' =>
  array (
    'columns' =>
    array (
      0 =>
      array (
        'title' => '产品中心',
        'items' =>
        array (
        ),
      ),
      1 =>
      array (
        'title' => '应用场景',
        'items' =>
        array (
        ),
      ),
      2 =>
      array (
        'title' => '关于我们',
        'items' =>
        array (
          0 =>
          array (
            'label' => '工厂与资质',
            'href' => '/factory/',
          ),
          1 =>
          array (
            'label' => '客户案例',
            'href' => '/cases/',
          ),
          2 =>
          array (
            'label' => '知识中心',
            'href' => '/knowledge/',
          ),
          3 =>
          array (
            'label' => '关于我们',
            'href' => '/about/profile/',
          ),
          4 =>
          array (
            'label' => '合作方式',
            'href' => '/cooperation/',
          ),
          5 =>
          array (
            'label' => '联系我们',
            'href' => '/contact/',
          ),
        ),
      ),
      3 =>
      array (
        'title' => '联系我们',
        'items' =>
        array (
          0 =>
          array (
            'type' => 'text',
            'label' => '合作热线',
            'value' => '',
            'href' => '',
          ),
          1 =>
          array (
            'type' => 'text',
            'label' => '地址',
            'value' => '',
          ),
          2 =>
          array (
            'type' => 'qr',
            'label' => '扫码联系',
          ),
        ),
      ),
    ),
    'brandColumn' =>
    array (
      'companyName' => '',
      'slogan' => '以专业可靠，服务每一位客户',
      'logoVariant' => 'inverse',
    ),
    'factBlock' =>
    array (
      'companyName' => '',
      'founded' => '',
      'establishedProduction' => '',
      'address' => '',
      'area' => '',
      'capacity' => '',
      'workshops' => '',
      'salesRegions' => '',
      'phone' => '',
    ),
    'legal' =>
    array (
      'copyright' => NULL,
      'icp' => NULL,
      'scLicense' => NULL,
      'standardCode' => NULL,
    ),
    'ariaLabels' =>
    array (
      'footerNav' => '页脚导航',
    ),
  ),
  'bottomCta' =>
  array (
    'title' => '需要进一步了解？',
    'desc' => '告诉我们你的需求，我们会尽快安排专人与你联系。',
    'primaryCta' => '获取方案',
    'secondaryCta' => '联系我们',
    'phone' => '',
    'overrides' =>
    array (
      'factory' =>
      array (
        'primaryCta' => '预约到访',
        'secondaryCta' => '获取方案',
        'note' => '到访客户意向更明确，实地沟通往往比线上咨询更能推进合作。',
      ),
    ),
  ),
  'form' =>
  array (
    'fields' =>
    array (
      'name' =>
      array (
        'label' => '称呼',
        'placeholder' => '怎么称呼您',
        'required' => true,
        'error' => '请填写称呼',
      ),
      'phone' =>
      array (
        'label' => '联系电话',
        'placeholder' => '手机号或微信号',
        'required' => true,
        'inputmode' => 'tel',
        'error' => '请填写联系电话',
      ),
      'customerType' =>
      array (
        'label' => '我是哪一类客户',
        'placeholder' => '请选择',
        'required' => true,
        'error' => '请选择客户类型',
        'options' =>
        array (
          0 => '产品采购',
          1 => '解决方案',
          2 => '渠道合作',
          3 => '技术合作',
          4 => '媒体咨询',
          5 => '其他',
        ),
      ),
      'note' =>
      array (
        'label' => '需求简述（选填）',
        'placeholder' => '简单描述你的需求，我们会尽快联系你。',
        'required' => false,
      ),
    ),
    'submit' => '提交需求',
    'submitting' => '提交中…',
    'privacy' => '我们只用你的信息联系你，不会用于其他用途。',
    'success' => '已收到，我们会尽快联系你。',
    'error' => '提交没成功，请稍后重试，或通过页面上的其他方式联系我们。',
  ),
  'states' =>
  array (
    'empty' => '暂无内容',
    'loading' => '加载中…',
    'disabledReason' =>
    array (
      'formIncomplete' => '请先填写带 * 的必填项',
    ),
  ),
  'error404' =>
  array (
    'code' => '404',
    'title' => '这个页面找不到了',
    'desc' => '可能是链接变了，或者地址打错了。',
    'primaryCta' => '回到首页',
    'secondaryCta' => '浏览内容',
    'phoneLabel' => '或者直接联系我们：',
  ),
  'compliance' =>
  array (
    'banned_terms' =>
    array (
      0 => '全国销量第一',
      1 => '全国销量领先',
      2 => '销量领先',
      3 => '第一',
      4 => '最',
      5 => '领导者',
      6 => '领先',
      7 => '首创',
      8 => '国家级',
      9 => '独家',
      10 => '顶级',
      11 => '极致',
      12 => '唯一',
    ),
    'banned_comparisons' =>
    array (
    ),
    'vague_terms' =>
    array (
      0 => '多年',
      1 => '大量',
      2 => '众多客户',
    ),
    'canonical_values' =>
    array (
      'company' => '',
      'industry' => '',
      'address' => '',
      'phone' => '',
    ),
    'forbidden_practices' =>
    array (
      0 => '不虚构资质与认证',
      1 => '不使用未授权的第三方品牌',
      2 => '不宣称未经证实的性能指标',
    ),
  ),
);
