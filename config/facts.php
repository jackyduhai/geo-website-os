<?php

/**
 * GEO Website OS 内置 Example 数据集（事实库）。
 *
 * 这是一份虚构、行业中性的示例数据（通用工业材料制造），与任何真实企业无关，
 * 仅用于开箱演示与测试；部署者应通过后台「事实库」或替换本文件写入自有数据。
 *
 * 联系方式、地址、资质等字段默认留空，前台会整体隐藏对应区块，不输出占位符。
 * 本文件可由 scripts/compile_facts.php 从结构化数据源重新生成。
 */

return array (
  'meta' =>
  array (
    'version' => '1.0',
    'updated' => '2026-09-01',
    'source' => 'GEO Website OS Example Dataset',
    'reviewed' =>
    array (
      'confirmed' => 0,
      'degraded' => 0,
      'gaps' => 0,
    ),
  ),
  'company' =>
  array (
    'name' => '示例制造有限公司',
    'name_en' => 'Example Manufacturing Co., Ltd.',
    'brand' => '示例制造',
    'brand_en' => 'EXAMPLEMFG',
    'short_name' => '示例制造',
    'founded' => '2014-06',
    'founded_display' => '2014 年 6 月',
    'established_production' => '2015-09',
    'established_production_display' => '2015 年 9 月',
    'address' =>
    array (
      'full' => '示例省示例市示例区示例大道 1 号',
      'country' => 'CN',
      'province' => '示例省',
      'city' => '示例市',
      'district' => '示例区',
      'street' => '示例大道 1 号',
      'lat' => NULL,
      'lng' => NULL,
    ),
    'area_sqm' => 12000,
    'area_display' => '约 12,000 平方米',
    'annual_capacity_tons' => 1200,
    'annual_capacity_display' => '约 1,200 吨成品',
    'total_investment_wan' => 800,
    'total_investment_display' => '约 800 万元',
    'tech_experience_years' => 10,
    'tech_experience_display' => '十余年',
    'phone' => '',
    'phone_tel' => '',
    'website' => 'https://example.com',
    'domain' => 'example.com',
    'industry' => '工业材料制造',
    'business_model' =>
    array (
      0 => '配方定制研发',
      1 => '品牌 OEM / ODM 代工',
      2 => '原料供应',
      3 => '经销合作',
    ),
    'target_customers' =>
    array (
      0 => '装备制造企业',
      1 => '工程承包商',
      2 => '工业品牌方',
      3 => '渠道经销商',
    ),
    'served_stores' => NULL,
    'served_stores_display' => '服务全国制造企业与工程客户',
    'industry_en' => 'Industrial Materials Manufacturing',
    'business_model_en' =>
    array (
      0 => 'Custom formulation R&D',
      1 => 'Brand OEM / ODM manufacturing',
      2 => 'Raw material supply',
      3 => 'Distribution partnership',
    ),
    'target_customers_en' =>
    array (
      0 => 'Equipment manufacturers',
      1 => 'Engineering contractors',
      2 => 'Industrial brand owners',
      3 => 'Distributors',
    ),
    'served_stores_display_en' => 'Serving manufacturers and engineering customers nationwide',
    'founded_display_en' => 'June 2014',
    'established_production_display_en' => 'September 2015',
    'area_display_en' => 'About 12,000 m2',
    'annual_capacity_display_en' => 'About 1,200 tons of finished products',
    'total_investment_display_en' => 'About 8 million CNY',
    'tech_experience_display_en' => 'Over 10 years',
    'address_en' => '1 Example Avenue, Example District, Example City, Example Province',
  ),
  'time_convention' =>
  array (
    'experience' => '十余年',
    'company' => '2014 年',
    'production' => '2015 年',
    'canonical_sentence' => '示例制造由深耕工业材料领域十余年的团队创立，公司主体于 2014 年注册成立，2015 年主要产线投产。',
  ),
  'product_lines' =>
  array (
    0 =>
    array (
      'id' => 'coatings',
      'name' => '工业防护涂料',
      'name_en' => 'Industrial Protective Coatings',
      'slug' => 'coatings',
      'desc' => '面向金属与混凝土基材的底漆、面漆与功能涂料。',
      'desc_en' => 'Primers, topcoats and functional coatings for metal and concrete substrates.',
      'order' => 1,
      'featured' => true,
    ),
    1 =>
    array (
      'id' => 'adhesives',
      'name' => '工业胶粘剂',
      'name_en' => 'Industrial Adhesives',
      'slug' => 'adhesives',
      'desc' => '结构粘接、密封与填缝用胶粘剂系列。',
      'desc_en' => 'Adhesives for structural bonding, sealing and gap filling.',
      'order' => 2,
      'featured' => true,
    ),
    2 =>
    array (
      'id' => 'additives',
      'name' => '功能助剂',
      'name_en' => 'Functional Additives',
      'slug' => 'additives',
      'desc' => '改善涂料与胶粘剂施工与成膜性能的助剂。',
      'desc_en' => 'Additives that improve application and film-forming performance.',
      'order' => 3,
      'featured' => true,
    ),
  ),
  'products' =>
  array (
    0 =>
    array (
      'id' => 'epoxy-primer-100',
      'name' => '环氧富锌底漆 ZP-100',
      'short_name' => '环氧富锌底漆',
      'slug' => 'epoxy-primer-100',
      'line' => 'coatings',
      'core' => true,
      'tag' => '工业防护涂料',
      'tagline' => '钢结构防腐底漆。推荐干膜厚度 60–80 μm，配套聚氨酯面漆使用。',
      'mains' =>
      array (
        0 => '钢材',
        1 => '钢结构件',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用基材',
          'value' => '喷砂处理钢材',
        ),
        1 =>
        array (
          'label' => '推荐配比',
          'value' => '主剂∶固化剂 = 9∶1',
        ),
        2 =>
        array (
          'label' => '表干时间',
          'value' => '约 30 分钟',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '表面处理',
          'value' => '喷砂至 Sa 2.5 级',
          'note' => '去除油污与氧化皮',
        ),
        1 =>
        array (
          'step' => '配料混合',
          'value' => '主剂∶固化剂 9∶1',
          'note' => '按重量比配制，熟化 10 分钟',
        ),
        2 =>
        array (
          'step' => '施工',
          'value' => '干膜 60–80 μm',
          'note' => '喷涂或刷涂',
        ),
        3 =>
        array (
          'step' => '固化',
          'value' => '25 ℃ 表干 30 分钟',
          'note' => '复涂间隔按工艺要求',
        ),
        4 =>
        array (
          'step' => '复检',
          'value' => '测干膜厚度',
          'note' => '合格后施作面漆',
        ),
      ),
      'scenes' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'construction-infrastructure',
      ),
      'related' =>
      array (
        0 => 'polyurethane-topcoat-200',
        1 => 'structural-adhesive-a10',
        2 => 'leveling-agent-l01',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    1 =>
    array (
      'id' => 'polyurethane-topcoat-200',
      'name' => '聚氨酯面漆 PC-200',
      'short_name' => '聚氨酯面漆',
      'slug' => 'polyurethane-topcoat-200',
      'line' => 'coatings',
      'core' => true,
      'tag' => '工业防护涂料',
      'tagline' => '耐候保光面漆，配套环氧底漆构成复合涂层体系。',
      'mains' =>
      array (
        0 => '钢材',
        1 => '铝合金',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用基材',
          'value' => '已施底漆的金属面',
        ),
        1 =>
        array (
          'label' => '推荐配比',
          'value' => '主剂∶固化剂 = 6∶1',
        ),
        2 =>
        array (
          'label' => '表干时间',
          'value' => '约 40 分钟',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '基面确认',
          'value' => '底漆已固化',
          'note' => '清洁、无油污',
        ),
        1 =>
        array (
          'step' => '配料混合',
          'value' => '主剂∶固化剂 6∶1',
          'note' => '熟化 10 分钟',
        ),
        2 =>
        array (
          'step' => '施工',
          'value' => '干膜 40–60 μm',
          'note' => '喷涂为宜',
        ),
        3 =>
        array (
          'step' => '固化',
          'value' => '25 ℃ 表干 40 分钟',
          'note' => '完全固化 7 天',
        ),
      ),
      'scenes' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'construction-infrastructure',
      ),
      'related' =>
      array (
        0 => 'epoxy-primer-100',
        1 => 'leveling-agent-l01',
        2 => 'thickener-t02',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    2 =>
    array (
      'id' => 'heat-resistant-coating-300',
      'name' => '有机硅耐高温涂料 HR-300',
      'short_name' => '耐高温涂料',
      'slug' => 'heat-resistant-coating-300',
      'line' => 'coatings',
      'core' => false,
      'tag' => '功能涂料',
      'tagline' => '用于持续受热金属构件的防护涂装。',
      'mains' =>
      array (
        0 => '碳钢',
        1 => '耐热构件',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用基材',
          'value' => '喷砂碳钢',
        ),
        1 =>
        array (
          'label' => '耐温等级',
          'value' => '按型号选择',
        ),
        2 =>
        array (
          'label' => '固化方式',
          'value' => '加温固化',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '表面处理',
          'value' => '喷砂 Sa 2.5 级',
          'note' => '',
        ),
        1 =>
        array (
          'step' => '施工',
          'value' => '干膜 30–50 μm',
          'note' => '薄涂多道',
        ),
        2 =>
        array (
          'step' => '固化',
          'value' => '按工艺加温',
          'note' => '逐步升温',
        ),
      ),
      'scenes' =>
      array (
        0 => 'automotive-parts',
      ),
      'related' =>
      array (
        0 => 'epoxy-primer-100',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    3 =>
    array (
      'id' => 'structural-adhesive-a10',
      'name' => '双组份结构胶 SA-A10',
      'short_name' => '结构胶 SA-A10',
      'slug' => 'structural-adhesive-a10',
      'line' => 'adhesives',
      'core' => true,
      'tag' => '工业胶粘剂',
      'tagline' => '金属与复合材料结构粘接，推荐按 1∶1 混合后施胶。',
      'mains' =>
      array (
        0 => '金属',
        1 => '复合材料',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用基材',
          'value' => '金属 / 复合材料',
        ),
        1 =>
        array (
          'label' => '推荐配比',
          'value' => 'A∶B = 1∶1',
        ),
        2 =>
        array (
          'label' => '操作时间',
          'value' => '约 30 分钟',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '表面处理',
          'value' => '清洁、粗化、除油',
          'note' => '保证粘接面干燥',
        ),
        1 =>
        array (
          'step' => '配料混合',
          'value' => 'A∶B = 1∶1',
          'note' => '搅拌至颜色均匀',
        ),
        2 =>
        array (
          'step' => '施胶',
          'value' => '均匀涂布',
          'note' => '控制胶层厚度',
        ),
        3 =>
        array (
          'step' => '固定固化',
          'value' => '夹持至初固',
          'note' => '25 ℃ 完全固化 24 小时',
        ),
      ),
      'scenes' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'automotive-parts',
      ),
      'related' =>
      array (
        0 => 'silicone-sealant-s20',
        1 => 'curing-agent-c03',
        2 => 'epoxy-primer-100',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    4 =>
    array (
      'id' => 'silicone-sealant-s20',
      'name' => '中性硅酮密封胶 SS-S20',
      'short_name' => '硅酮密封胶',
      'slug' => 'silicone-sealant-s20',
      'line' => 'adhesives',
      'core' => false,
      'tag' => '工业胶粘剂',
      'tagline' => '通用密封填缝，固化后形成弹性密封层。',
      'mains' =>
      array (
        0 => '混凝土',
        1 => '金属',
        2 => '玻璃',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用基材',
          'value' => '混凝土 / 金属 / 玻璃',
        ),
        1 =>
        array (
          'label' => '表干时间',
          'value' => '约 30 分钟',
        ),
        2 =>
        array (
          'label' => '完全固化',
          'value' => '约 24 小时',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '表面处理',
          'value' => '清洁干燥',
          'note' => '',
        ),
        1 =>
        array (
          'step' => '施胶',
          'value' => '连续均匀挤出',
          'note' => '贴美纹纸保护',
        ),
        2 =>
        array (
          'step' => '修整',
          'value' => '刮平、撕除胶带',
          'note' => '表干前完成',
        ),
        3 =>
        array (
          'step' => '固化',
          'value' => '自然固化',
          'note' => '24 小时完全固化',
        ),
      ),
      'scenes' =>
      array (
        0 => 'construction-infrastructure',
      ),
      'related' =>
      array (
        0 => 'structural-adhesive-a10',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    5 =>
    array (
      'id' => 'leveling-agent-l01',
      'name' => '丙烯酸流平剂 LA-L01',
      'short_name' => '流平剂 LA-L01',
      'slug' => 'leveling-agent-l01',
      'line' => 'additives',
      'core' => true,
      'tag' => '功能助剂',
      'tagline' => '改善涂料流平与成膜，减少刷痕与缩孔。',
      'mains' =>
      array (
        0 => '涂料体系',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用体系',
          'value' => '溶剂型涂料',
        ),
        1 =>
        array (
          'label' => '推荐添加量',
          'value' => '总量的 0.1%–0.5%',
        ),
        2 =>
        array (
          'label' => '加入阶段',
          'value' => '分散阶段',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '配料',
          'value' => '按配方称量',
          'note' => '',
        ),
        1 =>
        array (
          'step' => '加入',
          'value' => '分散阶段投入',
          'note' => '与基料充分混合',
        ),
        2 =>
        array (
          'step' => '分散',
          'value' => '中速分散均匀',
          'note' => '避免局部过量',
        ),
      ),
      'scenes' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'construction-infrastructure',
      ),
      'related' =>
      array (
        0 => 'thickener-t02',
        1 => 'curing-agent-c03',
        2 => 'polyurethane-topcoat-200',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    6 =>
    array (
      'id' => 'thickener-t02',
      'name' => '缔合型增稠剂 TH-T02',
      'short_name' => '增稠剂 TH-T02',
      'slug' => 'thickener-t02',
      'line' => 'additives',
      'core' => false,
      'tag' => '功能助剂',
      'tagline' => '调节涂料黏度与抗流挂性能。',
      'mains' =>
      array (
        0 => '涂料体系',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用体系',
          'value' => '水性涂料',
        ),
        1 =>
        array (
          'label' => '推荐添加量',
          'value' => '总量的 0.2%–1.0%',
        ),
        2 =>
        array (
          'label' => '加入阶段',
          'value' => '调漆阶段',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '预稀释',
          'value' => '按建议比例稀释',
          'note' => '',
        ),
        1 =>
        array (
          'step' => '加入',
          'value' => '调漆阶段缓慢加入',
          'note' => '边搅拌边加入',
        ),
        2 =>
        array (
          'step' => '检测',
          'value' => '测黏度',
          'note' => '达到目标黏度',
        ),
      ),
      'scenes' =>
      array (
        0 => 'automotive-parts',
        1 => 'construction-infrastructure',
      ),
      'related' =>
      array (
        0 => 'leveling-agent-l01',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    7 =>
    array (
      'id' => 'curing-agent-c03',
      'name' => '通用固化剂 CA-C03',
      'short_name' => '固化剂 CA-C03',
      'slug' => 'curing-agent-c03',
      'line' => 'additives',
      'core' => false,
      'tag' => '功能助剂',
      'tagline' => '配套树脂使用，按配比混合后促进交联固化。',
      'mains' =>
      array (
        0 => '树脂体系',
      ),
      'key_params' =>
      array (
        0 =>
        array (
          'label' => '适用体系',
          'value' => '环氧 / 聚氨酯',
        ),
        1 =>
        array (
          'label' => '推荐配比',
          'value' => '随主剂要求',
        ),
        2 =>
        array (
          'label' => '适用期',
          'value' => '按工艺要求',
        ),
      ),
      'params' =>
      array (
        0 =>
        array (
          'step' => '配料',
          'value' => '严格按配比',
          'note' => '',
        ),
        1 =>
        array (
          'step' => '混合',
          'value' => '搅拌均匀并熟化',
          'note' => '',
        ),
        2 =>
        array (
          'step' => '施工',
          'value' => '在适用期内用完',
          'note' => '避免超时',
        ),
      ),
      'scenes' =>
      array (
        0 => 'equipment-manufacturing',
      ),
      'related' =>
      array (
        0 => 'epoxy-primer-100',
        1 => 'structural-adhesive-a10',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
  ),
  'scenes' =>
  array (
    0 =>
    array (
      'id' => 'equipment-manufacturing',
      'name' => '装备制造',
      'slug' => 'equipment-manufacturing',
      'title_q' => '装备制造如何选防护与粘接材料？',
      'desc' => '关注涂层耐久、结构粘接强度与批次稳定',
      'pain_points' =>
      array (
        0 =>
        array (
          'title' => '涂层与粘接质量不稳定',
          'desc' => '批次间性能波动，影响整机可靠性与售后。',
        ),
        1 =>
        array (
          'title' => '材料体系搭配不清',
          'desc' => '底漆、面漆、胶粘剂与助剂如何配套，缺乏统一建议。',
        ),
        2 =>
        array (
          'title' => '量产供货一致性',
          'desc' => '上量后材料的交期与一致性成为瓶颈。',
        ),
      ),
      'combo' =>
      array (
        0 => 'epoxy-primer-100',
        1 => 'polyurethane-topcoat-200',
        2 => 'structural-adhesive-a10',
        3 => 'leveling-agent-l01',
      ),
      'combo_reason' => '环氧底漆加聚氨酯面漆构成防腐复合涂层，结构胶承担结构件粘接，流平剂改善面漆外观，覆盖装备制造的涂装与粘接主流程。',
      'key_param_product' => 'epoxy-primer-100',
      'key_param_display' => '主剂∶固化剂 9∶1，干膜 60–80 μm，25 ℃ 表干约 30 分钟',
      'hover_reveal' => '主固比 9∶1｜干膜 60–80 μm｜表干约 30 分钟',
      'title_q_en' => 'How to choose protective coating and bonding materials for equipment manufacturing?',
      'desc_en' => 'Focus on coating durability, structural bonding strength and batch consistency',
      'pain_points_en' =>
      array (
        0 =>
        array (
          'title' => 'Inconsistent coating and bonding quality',
          'desc' => 'Batch-to-batch variation affects equipment reliability and after-sales.',
        ),
        1 =>
        array (
          'title' => 'Unclear material system pairing',
          'desc' => 'No unified guidance on how primer, topcoat, adhesive and additives work together.',
        ),
        2 =>
        array (
          'title' => 'Mass-production supply consistency',
          'desc' => 'Lead time and consistency become bottlenecks as volumes grow.',
        ),
      ),
      'combo_reason_en' => 'Epoxy primer plus polyurethane topcoat forms the anti-corrosion composite system, structural adhesive handles structural bonding, and leveling agent improves topcoat appearance, covering the main coating and bonding process for equipment manufacturing.',
      'key_param_display_en' => 'Base:hardener 9:1, DFT 60-80 um, touch-dry about 30 min at 25 C',
      'hover_reveal_en' => 'Base:hardener 9:1 | DFT 60-80 um | touch-dry about 30 min',      'adjacent' =>
      array (
        0 => 'construction-infrastructure',
        1 => 'automotive-parts',
      ),
      'order' => 1,
      'priority' => 'P0',
    ),
    1 =>
    array (
      'id' => 'construction-infrastructure',
      'name' => '建筑工程',
      'slug' => 'construction-infrastructure',
      'title_q' => '建筑工程如何选防护涂料与密封材料？',
      'desc' => '关注耐候、防水密封与现场施工性',
      'pain_points' =>
      array (
        0 =>
        array (
          'title' => '户外耐候与防水要求高',
          'desc' => '长期暴露环境下，涂层失光、密封开裂风险突出。',
        ),
        1 =>
        array (
          'title' => '现场施工条件复杂',
          'desc' => '温湿度与基面状态变化大，需要可重复的施工参数。',
        ),
        2 =>
        array (
          'title' => '材料配套与供货',
          'desc' => '多品类材料需要统一配套与稳定供货。',
        ),
      ),
      'combo' =>
      array (
        0 => 'silicone-sealant-s20',
        1 => 'epoxy-primer-100',
        2 => 'polyurethane-topcoat-200',
        3 => 'leveling-agent-l01',
      ),
      'combo_reason' => '硅酮密封胶处理接缝防水，环氧底漆与聚氨酯面漆承担钢结构防腐耐候，流平剂改善外观，覆盖建筑现场的密封与涂装需求。',
      'key_param_product' => 'silicone-sealant-s20',
      'key_param_display' => '清洁干燥基面施胶，表干约 30 分钟，约 24 小时完全固化',
      'hover_reveal' => '表干约 30 分钟｜约 24 小时完全固化',
      'title_q_en' => 'How to choose protective coating and sealing materials for construction?',
      'desc_en' => 'Focus on weather resistance, waterproof sealing and on-site workability',
      'pain_points_en' =>
      array (
        0 =>
        array (
          'title' => 'High outdoor weather and waterproof requirements',
          'desc' => 'Under long-term exposure, loss of gloss and seal cracking are key risks.',
        ),
        1 =>
        array (
          'title' => 'Complex on-site conditions',
          'desc' => 'Temperature, humidity and substrate vary, requiring repeatable application parameters.',
        ),
        2 =>
        array (
          'title' => 'Material pairing and supply',
          'desc' => 'Multiple material categories need unified pairing and stable supply.',
        ),
      ),
      'combo_reason_en' => 'Silicone sealant handles joint waterproofing, epoxy primer and polyurethane topcoat provide steel corrosion protection and weather resistance, and leveling agent improves appearance, covering on-site sealing and coating needs.',
      'key_param_display_en' => 'Apply to a clean dry substrate, touch-dry about 30 min, full cure about 24 h',
      'hover_reveal_en' => 'Touch-dry about 30 min | full cure about 24 h',      'adjacent' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'automotive-parts',
      ),
      'order' => 2,
      'priority' => 'P0',
    ),
    2 =>
    array (
      'id' => 'automotive-parts',
      'name' => '汽车零部件',
      'slug' => 'automotive-parts',
      'title_q' => '汽车零部件如何选耐高温与粘接材料？',
      'desc' => '关注耐热、粘接强度与产线节拍',
      'pain_points' =>
      array (
        0 =>
        array (
          'title' => '受热部件防护难',
          'desc' => '排气与发动机周边部件需要稳定的耐高温防护。',
        ),
        1 =>
        array (
          'title' => '粘接节拍要求严',
          'desc' => '产线要求明确的操作时间与固化时间。',
        ),
        2 =>
        array (
          'title' => '一致性与可追溯',
          'desc' => '批量供货要求批次稳定、参数可追溯。',
        ),
      ),
      'combo' =>
      array (
        0 => 'structural-adhesive-a10',
        1 => 'heat-resistant-coating-300',
        2 => 'thickener-t02',
      ),
      'combo_reason' => '结构胶满足零部件结构粘接，耐高温涂料保护受热构件，增稠剂调节涂层施工黏度，匹配产线节拍。',
      'key_param_product' => 'structural-adhesive-a10',
      'key_param_display' => 'A∶B = 1∶1，操作时间约 30 分钟，25 ℃ 完全固化 24 小时',
      'hover_reveal' => 'A∶B = 1∶1｜操作约 30 分钟｜24 小时固化',
      'title_q_en' => 'How to choose heat-resistant and bonding materials for automotive parts?',
      'desc_en' => 'Focus on heat resistance, bonding strength and line cycle time',
      'pain_points_en' =>
      array (
        0 =>
        array (
          'title' => 'Difficult protection for heat-exposed parts',
          'desc' => 'Exhaust and engine-area parts need stable high-temperature protection.',
        ),
        1 =>
        array (
          'title' => 'Strict bonding cycle requirements',
          'desc' => 'The line needs defined working time and curing time.',
        ),
        2 =>
        array (
          'title' => 'Consistency and traceability',
          'desc' => 'Batch supply requires stable batches and traceable parameters.',
        ),
      ),
      'combo_reason_en' => 'Structural adhesive meets structural bonding of parts, heat-resistant coating protects heat-exposed components, and thickener adjusts application viscosity to match the line cycle.',
      'key_param_display_en' => 'A:B = 1:1, working time about 30 min, full cure 24 h at 25 C',
      'hover_reveal_en' => 'A:B = 1:1 | working about 30 min | 24 h cure',      'adjacent' =>
      array (
        0 => 'equipment-manufacturing',
        1 => 'construction-infrastructure',
      ),
      'order' => 3,
      'priority' => 'P1',
    ),
  ),
  'production' =>
  array (
    'workshops' =>
    array (
      0 =>
      array (
        'id' => 'raw-material',
        'name' => '原料处理车间',
        'desc' => '原材料进厂检验与预处理',
        'image_alt' => '示例制造原料处理车间',
        'name_en' => 'Raw Material Workshop',
        'desc_en' => 'Incoming inspection and pretreatment of raw materials',
        'image_alt_en' => 'Example Manufacturing raw material workshop',
        'image' => NULL,
      ),
      1 =>
      array (
        'id' => 'mixing',
        'name' => '配料混合车间',
        'desc' => '按配方精确配料与分散混合',
        'image_alt' => '示例制造配料混合车间',
        'name_en' => 'Mixing Workshop',
        'desc_en' => 'Precise batching, dispersing and mixing per formulation',
        'image_alt_en' => 'Example Manufacturing mixing workshop',
        'image' => NULL,
      ),
      2 =>
      array (
        'id' => 'processing',
        'name' => '成型加工车间',
        'desc' => '涂布、施胶与成型加工',
        'image_alt' => '示例制造成型加工车间',
        'name_en' => 'Processing Workshop',
        'desc_en' => 'Coating, adhesive application and forming',
        'image_alt_en' => 'Example Manufacturing processing workshop',
        'image' => NULL,
      ),
      3 =>
      array (
        'id' => 'qc-packaging',
        'name' => '品控包装车间',
        'desc' => '成品检测与分规格包装',
        'image_alt' => '示例制造品控包装车间',
        'name_en' => 'QC & Packaging Workshop',
        'desc_en' => 'Finished-product inspection and packaging by specification',
        'image_alt_en' => 'Example Manufacturing QC and packaging workshop',
        'image' => NULL,
      ),
    ),
    'sales_regions' =>
    array (
      0 => '东北',
      1 => '华北',
      2 => '华东',
      3 => '华中',
      4 => '西北',
      5 => '西南',
      6 => '华南',
    ),
    'sales_regions_en' =>
    array (
      0 => 'Northeast',
      1 => 'North China',
      2 => 'East China',
      3 => 'Central China',
      4 => 'Northwest',
      5 => 'Southwest',
      6 => 'South China',
    ),
    'certifications' =>
    array (
      'sc_license' => NULL,
      'standard_code' => NULL,
      'business_license' => '示例制造有限公司',
      'icp' => NULL,
    ),
  ),
  'brand_language' =>
  array (
    'slogan' => '以稳定品质，服务每一次制造',
    'mission' => '把材料性能与施工工艺参数做到可复现，让客户不依赖个人经验也能稳定生产。',
    'values' => '以精立业、以质取胜，合作共赢；以精细工艺立足，以稳定品质赢得长期合作。',
    'vision' => '成为值得长期信赖的工业材料合作伙伴。',
    'slogan_en' => 'Stable quality for every manufacturing need',
    'mission_en' => 'Make material performance and application parameters reproducible, so customers can produce steadily without relying on individual experience.',
    'values_en' => 'Build on precision, win through quality, grow together; stand on careful craftsmanship and earn long-term partnership through stable quality.',
    'vision_en' => 'Become a trusted long-term industrial materials partner.',
  ),
  'cooperation' =>
  array (
    'types' =>
    array (
      0 =>
      array (
        'id' => 'custom',
        'name' => '定制研发',
        'name_en' => 'Custom R&D',
        'fit' => '已有产品或产线，想定性能、定标准的客户',
        'fit_en' => 'Customers with existing products or lines who want custom performance and standards',
        'includes' =>
        array (
          0 => '需求与性能沟通',
          1 => '配方打样',
          2 => '试样确认',
          3 => '量产交付',
        ),
        'includes_en' =>
        array (
          0 => 'Requirement and performance discussion',
          1 => 'Formulation sampling',
          2 => 'Sample confirmation',
          3 => 'Mass production delivery',
        ),
        'cta' => '获取定制方案',
        'cta_en' => 'Get a custom solution',
      ),
      1 =>
      array (
        'id' => 'oem',
        'name' => 'OEM / ODM 代工',
        'name_en' => 'OEM / ODM Manufacturing',
        'fit' => '想做自己品牌、不自行建厂的客户',
        'fit_en' => 'Customers who want their own brand without building a factory',
        'includes' =>
        array (
          0 => '用我们的配方贴你的品牌',
          1 => '或按你的配方代工生产',
        ),
        'includes_en' =>
        array (
          0 => 'Use our formulations under your brand',
          1 => 'Or produce to your formulations',
        ),
        'cta' => '咨询代工政策',
        'cta_en' => 'Ask about OEM terms',
      ),
      2 =>
      array (
        'id' => 'dealer',
        'name' => '经销合作',
        'name_en' => 'Distribution Partnership',
        'fit' => '有区域渠道资源的客户',
        'fit_en' => 'Customers with regional channel resources',
        'includes' =>
        array (
          0 => '区域供货',
          1 => '经销政策',
          2 => '渠道支持',
        ),
        'includes_en' =>
        array (
          0 => 'Regional supply',
          1 => 'Distribution terms',
          2 => 'Channel support',
        ),
        'cta' => '咨询经销政策',
        'cta_en' => 'Ask about distribution terms',
      ),
    ),
    'process' =>
    array (
      0 =>
      array (
        'step' => 1,
        'name' => '需求沟通',
        'name_en' => 'Requirement Discussion',
        'desc' => '说清你的材料、性能要求和预计用量',
        'desc_en' => 'Clarify materials, performance requirements and expected volume',
      ),
      1 =>
      array (
        'step' => 2,
        'name' => '方案打样',
        'name_en' => 'Solution Sampling',
        'desc' => '按需求出样，提供试样',
        'desc_en' => 'Produce samples per requirements',
      ),
      2 =>
      array (
        'step' => 3,
        'name' => '试样确认',
        'name_en' => 'Sample Confirmation',
        'desc' => '确认性能与工艺，锁定配方与参数',
        'desc_en' => 'Confirm performance and process; finalize formulations and parameters',
      ),
      3 =>
      array (
        'step' => 4,
        'name' => '量产',
        'name_en' => 'Mass Production',
        'desc' => '产线安排生产，按约定交付',
        'desc_en' => 'Arrange production and deliver as agreed',
      ),
      4 =>
      array (
        'step' => 5,
        'name' => '持续供货',
        'name_en' => 'Ongoing Supply',
        'desc' => '稳定供货，后续按需调整',
        'desc_en' => 'Stable supply with adjustments as needed',
      ),
    ),
    'moq' => NULL,
    'sample_lead_time' => NULL,
    'delivery_lead_time' => NULL,
  ),
  'cases' =>
  array (
    0 =>
    array (
      'id' => 'case-01',
      'title' => '装备制造配套客户',
      'region_label' => '〔华东 · 装备制造〕',
      'scene' => 'equipment-manufacturing',
      'quote' => '统一配套之后，涂装和粘接的批次稳定性明显改善。',
      'title_en' => 'Equipment manufacturing pairing customer',
      'region_label_en' => '[East China · Equipment manufacturing]',
      'quote_en' => 'After unified pairing, the batch stability of coating and bonding clearly improved.',
      'quote_verified' => false,
      'combo' =>
      array (
        0 => 'epoxy-primer-100',
        1 => 'polyurethane-topcoat-200',
        2 => 'structural-adhesive-a10',
      ),
      'image' => NULL,
      'detail_page' => false,
    ),
    1 =>
    array (
      'id' => 'case-02',
      'title' => '建筑工程承包商',
      'region_label' => '〔华南 · 基建〕',
      'scene' => 'construction-infrastructure',
      'quote' => '现场接缝和钢结构防护用同一套材料，施工和补货都更省心。',
      'title_en' => 'Construction engineering contractor',
      'region_label_en' => '[South China · Infrastructure]',
      'quote_en' => 'Using one set of materials for on-site joints and steel protection made work and restocking easier.',
      'quote_verified' => false,
      'combo' =>
      array (
        0 => 'silicone-sealant-s20',
        1 => 'epoxy-primer-100',
        2 => 'polyurethane-topcoat-200',
      ),
      'image' => NULL,
      'detail_page' => false,
    ),
    2 =>
    array (
      'id' => 'case-03',
      'title' => '汽车零部件供应商',
      'region_label' => '〔西南 · 汽零〕',
      'scene' => 'automotive-parts',
      'quote' => '操作时间和固化时间明确，产线节拍更好安排。',
      'title_en' => 'Automotive parts supplier',
      'region_label_en' => '[Southwest · Automotive parts]',
      'quote_en' => 'Clear working and curing times made the line cycle easier to plan.',
      'quote_verified' => false,
      'combo' =>
      array (
        0 => 'structural-adhesive-a10',
        1 => 'heat-resistant-coating-300',
      ),
      'image' => NULL,
      'detail_page' => false,
    ),
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
  'gaps' =>
  array (
    0 =>
    array (
      'id' => 'production_license',
      'item' => '生产许可证编号',
      'blocks' =>
      array (
        0 => '工厂与资质页·资质区块',
        1 => '全站页脚合规行',
      ),
      'priority' => 'P0',
      'action' => '未取得前，资质区块整体隐藏',
    ),
    1 =>
    array (
      'id' => 'standard_code',
      'item' => '执行标准号（国标 / 企标）',
      'blocks' =>
      array (
        0 => '工厂与资质页',
        1 => '产品页规格区',
      ),
      'priority' => 'P0',
      'action' => '未取得前，对应字段隐藏',
    ),
    2 =>
    array (
      'id' => 'moq',
      'item' => '起订量（MOQ）',
      'blocks' =>
      array (
        0 => '合作方式页',
        1 => '产品页',
        2 => '场景页 FAQ',
      ),
      'priority' => 'P1',
      'action' => '隐藏对应字段，用「说明材料与用量，我们按情况报价」替代',
    ),
    3 =>
    array (
      'id' => 'lead_time',
      'item' => '交付周期 / 打样周期',
      'blocks' =>
      array (
        0 => '合作方式页',
      ),
      'priority' => 'P1',
      'action' => '隐藏对应字段',
    ),
    4 =>
    array (
      'id' => 'net_weight',
      'item' => '产品净含量规格',
      'blocks' =>
      array (
        0 => '全部产品详情页规格区',
      ),
      'priority' => 'P1',
      'action' => '未填写前「规格与包装」区块整体隐藏',
    ),
    5 =>
    array (
      'id' => 'workshop_photos',
      'item' => '车间与工厂实拍图',
      'blocks' =>
      array (
        0 => '工厂与资质页',
        1 => '首页',
        2 => '企业文化页',
      ),
      'priority' => 'P1',
      'action' => '未取得前隐藏车间区块，不得用渲染图顶替',
    ),
    6 =>
    array (
      'id' => 'scene_photos',
      'item' => '场景实拍图',
      'blocks' =>
      array (
        0 => '场景列表页',
        1 => '场景详情页头图',
      ),
      'priority' => 'P1',
      'action' => '未取得前用纯色块占位或省略头图',
    ),
    7 =>
    array (
      'id' => 'case_quotes',
      'item' => '案例引述确认或改无引述版本',
      'blocks' =>
      array (
        0 => '首页',
        1 => '案例列表页',
      ),
      'priority' => 'P2',
      'action' => '未确认前改为无引述版本，只保留客户类型 + 区域 + 使用组合',
    ),
    8 =>
    array (
      'id' => 'contact_qr',
      'item' => '联系二维码',
      'blocks' =>
      array (
        0 => '联系我们',
        1 => '首页',
      ),
      'priority' => 'P2',
      'action' => '未取得前隐藏二维码区块',
    ),
    9 =>
    array (
      'id' => 'geo_coords',
      'item' => '厂区经纬度坐标',
      'blocks' =>
      array (
        0 => '联系我们·LocalBusiness Schema',
      ),
      'priority' => 'P2',
      'action' => '未取得前 Schema 中省略 geo 字段',
    ),
    10 =>
    array (
      'id' => 'icp',
      'item' => 'ICP 备案号',
      'blocks' =>
      array (
        0 => '全站页脚',
      ),
      'priority' => 'P2',
      'action' => '未取得前该字段留空，不显示占位符',
    ),
  ),
);
