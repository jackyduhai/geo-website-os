<?php

/**
 * GEO Website OS 内置 Example 页面级成稿（机器可读）。
 * ------------------------------------------------------------------
 * 结构化事实走 config/facts；全局导航 / 页脚 / 表单 / 404 走 config/copy。
 * 本文件存放「只在某一页面使用」的示例成稿：合作 FAQ、关于我们成稿，以及按
 * Demo 产品 / 场景 slug 键控的产品 / 场景 FAQ。
 *
 * 行业中立纪律（P-STEP 18B）：
 *   - narrative / home_faqs / cooperation_faqs / about.* / factory_steps 是无 slug
 *     门控的出厂兜底，任何站点都可能直接渲染，必须保持行业中立；
 *   - product_faqs / scene_faqs 仅在 Demo 数据装载后、命中对应 Example slug 时才
 *     渲染，通用站点永不命中，作为内置 Example 内容保留；
 *   - 部署者可通过后台「页面文案」叙事插槽（contents.slot）覆盖默认成稿。
 */
return array (
  'narrative' => 
  array (
    'cooperation' => 
    array (
      'lead' => '无论你是产品采购、方案集成还是渠道合作，都可以在这里找到适合的合作方式。欢迎先沟通需求，我们会尽快给出可行方案。',
    ),
    'contact' => 
    array (
      'lead' => '填写表单或通过页面上的联系方式与我们沟通，告诉我们你的需求，我们会尽快与你联系。',
    ),
    'products_index' => 
    array (
      'lead' => '这里集中展示我们的产品与方案，可按系列浏览，查看规格、适用场景与常见问题；如有定制需求，欢迎联系我们。',
    ),
    'solutions_index' => 
    array (
      'lead' => '面向典型应用场景整理的产品组合与使用说明，先对号入座，再了解具体产品与用法。',
    ),
  ),
  'home_faqs' => 
  array (
  ),
  'cooperation_faqs' => 
  array (
    0 => 
    array (
      'q' => '合作流程是怎样的？',
      'a' => '通常从需求沟通开始，确认方案与报价后签订合同，再安排生产或备货、交付与售后支持。',
    ),
    1 => 
    array (
      'q' => '起订量是多少？',
      'a' => '不同产品与规格的起订要求不同，说明你的品类与预计用量后，我们会按实际情况报价。',
    ),
    2 => 
    array (
      'q' => '可以先试样或试用吗？',
      'a' => '可以，沟通清楚需求后可安排样品或试用，确认符合预期后再推进正式合作。',
    ),
    3 => 
    array (
      'q' => '可以定制吗？',
      'a' => '支持按需定制，具体规格、包装与交付方式可在合同中明确约定。',
    ),
    4 => 
    array (
      'q' => '能做区域或渠道保护吗？',
      'a' => '区域与渠道政策需结合品类和具体情况商谈，欢迎沟通你的合作模式。',
    ),
    5 => 
    array (
      'q' => '交付周期一般多久？',
      'a' => '交付周期与品类、规格和订单量有关，确认需求后我们会给出明确排期。',
    ),
  ),
  'about' => 
  array (
    'profile' => 
    array (
      'meta_desc' => '了解我们的企业概况、业务范围与服务能力。',
      'lead' => '一家专注于产品与服务、重视品质与长期合作的企业。',
      'paragraphs' => 
      array (
        0 => '我们是一家以产品与服务为核心的企业，围绕客户需求持续打磨自身能力，努力把每一次合作做扎实。',
        1 => '我们关注从需求对接到交付落地的完整过程，通过规范的流程与质量控制，让产品与服务保持稳定、可靠。',
        2 => '我们的业务覆盖多种产品与解决方案，面向不同类型的客户提供支持，也欢迎按需开展定制与渠道合作。',
        3 => '如有合作意向或进一步问题，欢迎通过页面上的联系方式与我们沟通。',
      ),
    ),
    'history' => 
    array (
      'meta_desc' => '了解我们的发展历程与重要节点。',
      'lead' => '一步一个脚印，持续把产品与服务做扎实。',
      'nodes' => 
      array (
      ),
    ),
    'culture' => 
    array (
      'meta_desc' => '了解我们的使命、愿景、价值观与做事方式。',
      'lead' => '专业、可靠、共赢，是我们做事的基本标准。',
      'cards' => 
      array (
        0 => 
        array (
          'label' => '使命',
          'main' => '以专业产品与服务创造客户价值',
          'desc' => '围绕客户真实需求提供可靠的产品与服务，帮助客户把事情做成。',
        ),
        1 => 
        array (
          'label' => '愿景',
          'main' => '成为值得长期信赖的合作伙伴',
          'desc' => '以稳定的品质与持续的服务，和客户建立长期、互信的合作关系。',
        ),
        2 => 
        array (
          'label' => '价值观',
          'main' => '专业、可靠、共赢',
          'desc' => '以专业立足，以可靠赢得信任，与客户和伙伴共同成长。',
        ),
        3 => 
        array (
          'label' => '品牌口号',
          'main' => '以专业可靠，服务每一位客户',
          'desc' => '把每一次交付都当作长期合作的开始，用心服务、负责到底。',
        ),
      ),
    ),
  ),
  'factory_steps' => 
  array (
    0 => 
    array (
      'title' => '需求沟通',
      'text' => '确认产品、规格与预计用量',
    ),
    1 => 
    array (
      'title' => '方案与报价',
      'text' => '提供方案、报价与交付周期',
    ),
    2 => 
    array (
      'title' => '合同与排期',
      'text' => '签订合同并安排生产或备货',
    ),
    3 => 
    array (
      'title' => '质检与交付',
      'text' => '按标准检验后安排发货与交付',
    ),
    4 => 
    array (
      'title' => '售后支持',
      'text' => '跟踪使用情况并提供售后支持',
    ),
  ),
  'product_faqs' => 
  array (
    'epoxy-primer-100' => 
    array (
      0 => 
      array (
        'q' => '这款底漆用在什么基材？',
        'a' => '主要用于经喷砂处理的钢材与钢结构件，作为防腐复合涂层的底漆。',
      ),
      1 => 
      array (
        'q' => '配比可以调整吗？',
        'a' => '推荐主剂与固化剂按 9∶1 配制，具体以产品技术资料为准。',
      ),
      2 => 
      array (
        'q' => '多久表干？',
        'a' => '25 ℃ 条件下表干约 30 分钟，复涂间隔按工艺要求执行。',
      ),
      3 => 
      array (
        'q' => '可以按我的要求改配方吗？',
        'a' => '可以，这属于定制研发，可按你的防腐与施工要求调整。',
      ),
      4 => 
      array (
        'q' => '起订量是多少？',
        'a' => '不同品类起订量不同，说明用量我们按你的情况报价。',
      ),
    ),
    'polyurethane-topcoat-200' => 
    array (
      0 => 
      array (
        'q' => '这款面漆怎么配套？',
        'a' => '建议配套环氧富锌底漆使用，底漆固化后施作面漆，构成复合涂层。',
      ),
      1 => 
      array (
        'q' => '配比是多少？',
        'a' => '推荐主剂与固化剂按 6∶1 配制，熟化后施工。',
      ),
      2 => 
      array (
        'q' => '多久完全固化？',
        'a' => '25 ℃ 表干约 40 分钟，完全固化约需 7 天。',
      ),
      3 => 
      array (
        'q' => '可以定制光泽与颜色吗？',
        'a' => '可以，可按你的外观与耐候要求定制。',
      ),
      4 => 
      array (
        'q' => '起订量是多少？',
        'a' => '不同品类起订量不同，说明用量我们按你的情况报价。',
      ),
    ),
    'structural-adhesive-a10' => 
    array (
      0 => 
      array (
        'q' => '能粘哪些材料？',
        'a' => '适用于金属与复合材料的结构粘接，使用前请保持粘接面清洁干燥。',
      ),
      1 => 
      array (
        'q' => '配比是多少？',
        'a' => '双组份按 A∶B = 1∶1 混合，搅拌至颜色均匀。',
      ),
      2 => 
      array (
        'q' => '操作时间多长？',
        'a' => '混合后操作时间约 30 分钟，请在此期间完成施胶与装配。',
      ),
      3 => 
      array (
        'q' => '多久达到强度？',
        'a' => '25 ℃ 下约 24 小时完全固化，期间需保持夹持固定。',
      ),
      4 => 
      array (
        'q' => '起订量是多少？',
        'a' => '不同规格起订量不同，说明用量我们按你的情况报价。',
      ),
    ),
    'leveling-agent-l01' => 
    array (
      0 => 
      array (
        'q' => '用在什么体系？',
        'a' => '主要用于溶剂型涂料体系，改善流平与成膜。',
      ),
      1 => 
      array (
        'q' => '添加量是多少？',
        'a' => '一般为配方总量的 0.1%–0.5%，具体通过试验确定。',
      ),
      2 => 
      array (
        'q' => '什么时候加入？',
        'a' => '建议在分散阶段加入，与基料充分混合。',
      ),
      3 => 
      array (
        'q' => '可以和其他助剂搭配吗？',
        'a' => '可以，但建议先做相容性试验，避免局部过量。',
      ),
      4 => 
      array (
        'q' => '起订量是多少？',
        'a' => '不同品类起订量不同，说明用量我们按你的情况报价。',
      ),
    ),
  ),
  'scene_faqs' => 
  array (
    'equipment-manufacturing' => 
    array (
      0 => 
      array (
        'q' => '涂装和粘接要一次配齐吗？',
        'a' => '不必，可以先上底漆、面漆与结构胶等主流程材料，跑通后再补充助剂。',
      ),
      1 => 
      array (
        'q' => '用量怎么估算？',
        'a' => '与涂布面积、干膜厚度和产量有关，我们可以帮你按工艺参数倒推需求。',
      ),
      2 => 
      array (
        'q' => '批次一致性怎么保证？',
        'a' => '关键参数固定、产线按标准流程生产并逐批检测，便于稳定复现。',
      ),
      3 => 
      array (
        'q' => '起订量是多少？',
        'a' => '说明材料和用量，我们按你的情况报价。',
      ),
    ),
    'construction-infrastructure' => 
    array (
      0 => 
      array (
        'q' => '现场温湿度变化大怎么办？',
        'a' => '产品资料给出了表干与固化条件，现场按参数控制施工窗口即可。',
      ),
      1 => 
      array (
        'q' => '密封和防腐能用一套材料吗？',
        'a' => '接缝用硅酮密封胶，钢结构用环氧底漆加聚氨酯面漆，各司其职、配套使用。',
      ),
      2 => 
      array (
        'q' => '大批量供货稳定吗？',
        'a' => '自有产线与品控流程，可按工程进度安排供货。',
      ),
      3 => 
      array (
        'q' => '起订量是多少？',
        'a' => '说明材料和用量，我们按你的情况报价。',
      ),
    ),
    'automotive-parts' => 
    array (
      0 => 
      array (
        'q' => '受热部件用什么？',
        'a' => '持续受热构件建议选用有机硅耐高温涂料，按工艺加温固化。',
      ),
      1 => 
      array (
        'q' => '能匹配产线节拍吗？',
        'a' => '结构胶标注了操作时间与固化时间，可据此安排施胶与装配节拍。',
      ),
      2 => 
      array (
        'q' => '可以做产线专属配方吗？',
        'a' => '可以，这属于定制研发，可按你的标准化要求锁定配方与参数。',
      ),
      3 => 
      array (
        'q' => '起订量是多少？',
        'a' => '说明材料和用量，我们按你的情况报价。',
      ),
    ),
  ),
  'narrative_en' =>
  array (
    'cooperation' =>
    array (
      'lead' => 'Whether you are sourcing products, integrating solutions or looking for channel partnership, you can find a suitable way to work with us here. Feel free to share your needs first, and we will provide a workable plan soon.',
    ),
    'contact' =>
    array (
      'lead' => 'Fill in the form or use the contact details on the page to reach us, tell us your needs, and we will get back to you soon.',
    ),
    'products_index' =>
    array (
      'lead' => 'Our products and solutions are gathered here. Browse by line to view specifications, applicable scenarios and FAQs; contact us for custom requirements.',
    ),
    'solutions_index' =>
    array (
      'lead' => 'Product combinations and usage notes organized for typical application scenarios. Find your scenario first, then learn about the specific products and how to use them.',
    ),
  ),
  'cooperation_faqs_en' =>
  array (
    0 => array ('q' => 'What is the cooperation process?', 'a' => 'It usually starts with requirement discussion. After confirming the solution and quotation, we sign a contract, then arrange production or stocking, delivery and after-sales support.'),
    1 => array ('q' => 'What is the minimum order quantity?', 'a' => 'MOQ varies by product and specification. Tell us your category and expected volume, and we will quote accordingly.'),
    2 => array ('q' => 'Can I get samples or a trial first?', 'a' => 'Yes. Once needs are clear, we can arrange samples or a trial, and move to formal cooperation after they meet expectations.'),
    3 => array ('q' => 'Can products be customized?', 'a' => 'Yes, on-demand customization is supported. Specific specifications, packaging and delivery terms can be agreed in the contract.'),
    4 => array ('q' => 'Can you provide regional or channel protection?', 'a' => 'Regional and channel policies are discussed based on the category and details. Feel free to share your cooperation model.'),
    5 => array ('q' => 'How long is the delivery cycle?', 'a' => 'Delivery time depends on category, specification and order volume. We will give a clear schedule after confirming requirements.'),
  ),
  'about_en' =>
  array (
    'profile' =>
    array (
      'meta_desc' => 'Learn about our company overview, business scope and service capabilities.',
      'lead' => 'A company focused on products and services, committed to quality and long-term cooperation.',
      'paragraphs' =>
      array (
        0 => 'We are a company centered on products and services, continuously refining our capabilities around customer needs and making every cooperation solid.',
        1 => 'We focus on the complete process from requirement alignment to delivery, keeping products and services stable and reliable through standardized procedures and quality control.',
        2 => 'Our business covers a range of products and solutions, supporting different types of customers, and we welcome customization and channel cooperation as needed.',
        3 => 'If you are interested in cooperation or have further questions, feel free to reach us through the contact details on the page.',
      ),
    ),
    'history' =>
    array (
      'meta_desc' => 'Learn about our development history and key milestones.',
      'lead' => 'Step by step, we keep making our products and services solid.',
      'nodes' =>
      array (
      ),
    ),
    'culture' =>
    array (
      'meta_desc' => 'Learn about our mission, vision, values and way of working.',
      'lead' => 'Professional, reliable and mutually beneficial is our basic standard.',
      'cards' =>
      array (
        0 => array ('label' => 'Mission', 'main' => 'Create customer value with professional products and services', 'desc' => 'Provide reliable products and services around real customer needs, helping customers get things done.'),
        1 => array ('label' => 'Vision', 'main' => 'Become a trusted long-term partner', 'desc' => 'Build long-term, trusting relationships with customers through stable quality and continuous service.'),
        2 => array ('label' => 'Values', 'main' => 'Professional, reliable, mutually beneficial', 'desc' => 'Stand on professionalism, earn trust through reliability, and grow together with customers and partners.'),
        3 => array ('label' => 'Brand slogan', 'main' => 'Serving every customer professionally and reliably', 'desc' => 'Treat every delivery as the start of long-term cooperation, serving with care and taking responsibility to the end.'),
      ),
    ),
  ),
  'factory_steps_en' =>
  array (
    0 => array ('title' => 'Requirement Discussion', 'text' => 'Confirm products, specifications and expected volume'),
    1 => array ('title' => 'Solution and Quotation', 'text' => 'Provide the solution, quotation and delivery time'),
    2 => array ('title' => 'Contract and Scheduling', 'text' => 'Sign the contract and arrange production or stocking'),
    3 => array ('title' => 'QC and Delivery', 'text' => 'Inspect to standards, then arrange shipment and delivery'),
    4 => array ('title' => 'After-Sales Support', 'text' => 'Track usage and provide after-sales support'),
  ),
  'product_faqs_en' =>
  array (
    'epoxy-primer-100' =>
    array (
      0 => array ('q' => 'What substrates is this primer used on?', 'a' => 'Mainly used on blast-cleaned steel and steel structural parts as the primer of an anti-corrosion composite coating system.'),
      1 => array ('q' => 'Can the mix ratio be adjusted?', 'a' => 'The recommended base-to-hardener ratio is 9:1; refer to the product technical data sheet for details.'),
      2 => array ('q' => 'How long does it take to become touch-dry?', 'a' => 'At 25 C, touch-dry takes about 30 minutes. Follow the process requirements for recoating intervals.'),
      3 => array ('q' => 'Can you adjust the formulation to my requirements?', 'a' => 'Yes, this is custom R and D and can be adjusted to your anti-corrosion and application requirements.'),
      4 => array ('q' => 'What is the MOQ?', 'a' => 'MOQ varies by category. Tell us your volume and we will quote for your situation.'),
    ),
    'polyurethane-topcoat-200' =>
    array (
      0 => array ('q' => 'How is this topcoat paired?', 'a' => 'We recommend pairing it with epoxy zinc-rich primer. Apply the topcoat after the primer has cured to form a composite coating.'),
      1 => array ('q' => 'What is the mix ratio?', 'a' => 'The recommended base-to-hardener ratio is 6:1; apply after induction.'),
      2 => array ('q' => 'How long until full cure?', 'a' => 'At 25 C, touch-dry takes about 40 minutes and full cure takes about 7 days.'),
      3 => array ('q' => 'Can gloss and color be customized?', 'a' => 'Yes, they can be customized to your appearance and weather-resistance requirements.'),
      4 => array ('q' => 'What is the MOQ?', 'a' => 'MOQ varies by category. Tell us your volume and we will quote for your situation.'),
    ),
    'structural-adhesive-a10' =>
    array (
      0 => array ('q' => 'What materials can it bond?', 'a' => 'Suitable for structural bonding of metals and composite materials. Keep bonding surfaces clean and dry before use.'),
      1 => array ('q' => 'What is the mix ratio?', 'a' => 'For the two-component product, mix A:B = 1:1 and stir until the color is uniform.'),
      2 => array ('q' => 'How long is the working time?', 'a' => 'The working time after mixing is about 30 minutes. Complete adhesive application and assembly within this window.'),
      3 => array ('q' => 'How long until it reaches strength?', 'a' => 'At 25 C, it fully cures in about 24 hours; keep clamping during this period.'),
      4 => array ('q' => 'What is the MOQ?', 'a' => 'MOQ varies by specification. Tell us your volume and we will quote for your situation.'),
    ),
    'leveling-agent-l01' =>
    array (
      0 => array ('q' => 'What systems is it used in?', 'a' => 'Mainly used in solvent-based coating systems to improve leveling and film formation.'),
      1 => array ('q' => 'What is the dosage?', 'a' => 'Generally 0.1 to 0.5 percent of the total formulation; determine the exact amount through testing.'),
      2 => array ('q' => 'When should it be added?', 'a' => 'We recommend adding it during the dispersion stage to mix fully with the base material.'),
      3 => array ('q' => 'Can it be combined with other additives?', 'a' => 'Yes, but we recommend a compatibility test first to avoid local overdosage.'),
      4 => array ('q' => 'What is the MOQ?', 'a' => 'MOQ varies by category. Tell us your volume and we will quote for your situation.'),
    ),
  ),
  'scene_faqs_en' =>
  array (
    'equipment-manufacturing' =>
    array (
      0 => array ('q' => 'Do coating and bonding materials need to be sourced all at once?', 'a' => 'No. You can start with main-process materials such as primer, topcoat and structural adhesive, then add additives once the line is running.'),
      1 => array ('q' => 'How do I estimate the volume?', 'a' => 'It relates to coating area, dry film thickness and output. We can help you back-calculate demand from process parameters.'),
      2 => array ('q' => 'How is batch consistency ensured?', 'a' => 'Key parameters are fixed, the line produces to standard procedures, and each batch is tested for stable, reproducible results.'),
      3 => array ('q' => 'What is the MOQ?', 'a' => 'Tell us the materials and volume, and we will quote for your situation.'),
    ),
    'construction-infrastructure' =>
    array (
      0 => array ('q' => 'What if on-site temperature and humidity vary a lot?', 'a' => 'The product data gives touch-dry and curing conditions; control the application window on site according to the parameters.'),
      1 => array ('q' => 'Can one set of materials handle both sealing and anti-corrosion?', 'a' => 'Use silicone sealant for joints and epoxy primer plus polyurethane topcoat for steel; each does its own job and they work together.'),
      2 => array ('q' => 'Is large-volume supply stable?', 'a' => 'With our own production lines and QC procedures, supply can be arranged according to project progress.'),
      3 => array ('q' => 'What is the MOQ?', 'a' => 'Tell us the materials and volume, and we will quote for your situation.'),
    ),
    'automotive-parts' =>
    array (
      0 => array ('q' => 'What should be used for heat-exposed components?', 'a' => 'For continuously heat-exposed components, we recommend silicone high-temperature coating, cured by heating per the process.'),
      1 => array ('q' => 'Can it match the production line cycle?', 'a' => 'The structural adhesive states its working time and curing time, so adhesive application and assembly can be scheduled accordingly.'),
      2 => array ('q' => 'Can a line-specific formulation be created?', 'a' => 'Yes, this is custom R and D. The formulation and parameters can be locked to your standardization requirements.'),
      3 => array ('q' => 'What is the MOQ?', 'a' => 'Tell us the materials and volume, and we will quote for your situation.'),
    ),
  ),
);
