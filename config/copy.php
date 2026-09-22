<?php

/**
 * GEO Website OS 内置 Example 全局文案（导航 / 页脚 / 表单 / 404 / 合规词表）。
 *
 * 虚构、行业中性的示例内容，仅用于开箱演示；部署者应通过后台「站点设置 / 菜单装修」
 * 或替换本文件写入自有内容。联系电话、地址等默认留空，前台整体隐藏对应区块。
 *
 * 本文件可由 scripts/compile_facts.php 从结构化数据源重新生成。
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
          0 => 
          array (
            'label' => '工业防护涂料',
            'href' => '/products/coatings/',
          ),
          1 => 
          array (
            'label' => '工业胶粘剂',
            'href' => '/products/adhesives/',
          ),
          2 => 
          array (
            'label' => '功能助剂',
            'href' => '/products/additives/',
          ),
        ),
      ),
      1 => 
      array (
        'label' => '应用场景',
        'href' => '/solutions/',
        'children' => 
        array (
          0 => 
          array (
            'label' => '装备制造',
            'href' => '/solutions/equipment-manufacturing/',
          ),
          1 => 
          array (
            'label' => '建筑工程',
            'href' => '/solutions/construction-infrastructure/',
          ),
          2 => 
          array (
            'label' => '汽车零部件',
            'href' => '/solutions/automotive-parts/',
          ),
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
          0 => 
          array (
            'label' => '选型指南',
            'href' => '/knowledge/selection/',
          ),
          1 => 
          array (
            'label' => '工艺与施工',
            'href' => '/knowledge/process/',
          ),
          2 => 
          array (
            'label' => '采购与合作',
            'href' => '/knowledge/business/',
          ),
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
    'cta' => '获取报价与样品',
    'ctaMobile' => '获取样品',
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
          0 => 
          array (
            'label' => '工业防护涂料',
            'href' => '/products/coatings/',
          ),
          1 => 
          array (
            'label' => '工业胶粘剂',
            'href' => '/products/adhesives/',
          ),
          2 => 
          array (
            'label' => '功能助剂',
            'href' => '/products/additives/',
          ),
        ),
      ),
      1 => 
      array (
        'title' => '应用场景',
        'items' => 
        array (
          0 => 
          array (
            'label' => '装备制造',
            'href' => '/solutions/equipment-manufacturing/',
          ),
          1 => 
          array (
            'label' => '建筑工程',
            'href' => '/solutions/construction-infrastructure/',
          ),
          2 => 
          array (
            'label' => '汽车零部件',
            'href' => '/solutions/automotive-parts/',
          ),
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
            'label' => '厂区地址',
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
      'companyName' => '示例制造有限公司',
      'slogan' => '以专业可靠，服务每一位客户',
      'logoVariant' => 'inverse',
    ),
    'factBlock' => 
    array (
      'companyName' => '示例制造有限公司',
      'founded' => '2014 年 6 月',
      'establishedProduction' => '2015 年 9 月（主要产线投产）',
      'address' => '示例省示例市示例区示例大道 1 号',
      'area' => '约 12,000 平方米',
      'capacity' => '约 1,200 吨成品',
      'workshops' => '原料处理 / 配料混合 / 成型加工 / 品控包装',
      'salesRegions' => '东北、华北、华东、华中、西北、西南、华南七大区域',
      'phone' => '',
    ),
    'legal' => 
    array (
      'copyright' => '© 2026 示例制造有限公司',
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
    'secondaryCta' => '看看产品',
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
      'company' => '示例制造有限公司',
      'industry' => '工业材料制造',
      'address' => '示例省示例市示例区示例大道 1 号',
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
