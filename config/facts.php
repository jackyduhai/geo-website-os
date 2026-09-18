<?php

/**
 * 自动生成，请勿手改。
 * 来源：facts.yaml
 * 生成：scripts/compile_facts.php（修改事实后重跑该脚本）
 */

return array (
  'meta' => 
  array (
    'version' => '1.0',
    'updated' => '2026-09-15',
    'source' => 'Example GEO 事实母稿 v1.0',
    'reviewed' => 
    array (
      'confirmed' => 18,
      'degraded' => 3,
      'gaps' => 6,
    ),
  ),
  'company' => 
  array (
    'name' => 'Example Food Co., Ltd.',
    'name_en' => 'Sample City Example Food Co., Ltd.',
    'brand' => 'Example',
    'brand_en' => 'EXAMPLE',
    'short_name' => 'Example',
    'founded' => '2017-03',
    'founded_display' => '2017 年 3 月',
    'established_production' => '2018-03',
    'established_production_display' => '2018 年 3 月',
    'address' => 
    array (
      'full' => 'Sample Province省Sample City市沈河区 Example Street 39',
      'country' => 'CN',
      'province' => 'Sample Province省',
      'city' => 'Sample City市',
      'district' => '沈河区',
      'street' => 'Example Street 39',
      'lat' => NULL,
      'lng' => NULL,
    ),
    'area_sqm' => 9000,
    'area_display' => '约 9,000 平方米',
    'annual_capacity_tons' => 8000,
    'annual_capacity_display' => '约 8,000 吨成品',
    'total_investment_wan' => 500,
    'total_investment_display' => '约 500 万元',
    'tech_experience_years' => 20,
    'tech_experience_display' => '二十年',
    'phone' => '400-000-0000',
    'phone_tel' => '+86400-000-0000',
    'website' => 'https://www.example.com',
    'domain' => 'example.com',
    'industry' => '食品制造 / 调味品',
    'business_model' => 
    array (
      0 => '配方定制研发',
      1 => '品牌 OEM/ODM 代工',
      2 => '原料供应',
      3 => '经销合作',
    ),
    'target_customers' => 
    array (
      0 => '中小餐饮企业',
      1 => 'Sample Snack创业品牌',
      2 => '连锁餐饮品牌',
      3 => '渠道经销商',
    ),
    'served_stores' => NULL,
    'served_stores_display' => '服务全国餐饮门店与连锁品牌',
  ),
  'time_convention' => 
  array (
    'experience' => '二十年',
    'company' => '2017 年',
    'production' => '2018 年',
    'canonical_sentence' => 'Example品牌由深耕中式Sample Snack调味领域二十年的团队创立，公司主体于 2017 年 3 月在Sample City注册成立，2018 年 3 月四大车间全面投产。',
  ),
  'production' => 
  array (
    'workshops' => 
    array (
      0 => 
      array (
        'id' => 'spice-grinding',
        'name' => 'Sample Spice粉碎车间',
        'desc' => '单体与复合Sample Spice的粉碎与配比',
        'image_alt' => 'Example Food Co., Ltd.Sample Spice粉碎车间实拍',
        'image' => NULL,
      ),
      1 => 
      array (
        'id' => 'prepared-meat',
        'name' => '预制调理肉车间',
        'desc' => '鸡肉半成品的腌制与调理加工',
        'image_alt' => 'Example Food Co., Ltd.预制调理肉车间实拍',
        'image' => NULL,
      ),
      2 => 
      array (
        'id' => 'solid-seasoning',
        'name' => '固体调味料车间',
        'desc' => 'Sample Marinade、撒料、粉料的混合与包装',
        'image_alt' => 'Example Food Co., Ltd.固体调味料车间实拍',
        'image' => NULL,
      ),
      3 => 
      array (
        'id' => 'edible-flavor',
        'name' => '食用香精车间',
        'desc' => '液体精油、固体粉末、半固态膏体的生产',
        'image_alt' => 'Example Food Co., Ltd.食用香精车间实拍',
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
    'certifications' => 
    array (
      'sc_license' => NULL,
      'standard_code' => NULL,
      'business_license' => 'Example Food Co., Ltd.',
      'icp' => NULL,
    ),
  ),
  'product_lines' => 
  array (
    0 => 
    array (
      'id' => 'seasoning',
      'name' => 'Sample SnackSample Marinade（固态调味料）',
      'slug' => 'seasoning',
      'desc' => '中式、韩式、西式、特色风味，含Sample Flavor系列、川香麻辣、黑椒风味等。',
      'order' => 1,
      'featured' => true,
    ),
    1 => 
    array (
      'id' => 'prepared-chicken',
      'name' => '鸡肉半成品',
      'slug' => 'prepared-chicken',
      'desc' => 'OEM 定制半成品与鸡货腌制小通品。按你的品牌定制。',
      'order' => 2,
      'featured' => true,
    ),
    2 => 
    array (
      'id' => 'flavor',
      'name' => '调味香精',
      'slug' => 'flavor',
      'desc' => '液体精油、固体粉末、半固态膏状，用于风味补强。',
      'order' => 3,
    ),
    3 => 
    array (
      'id' => 'coating',
      'name' => 'Sample SnackSample BreadingSample Marinade',
      'slug' => 'coating',
      'desc' => '大鸡排颗粒粉、金牌脆鳞Sample Breading、韩式Sample Snack浆粉。',
      'order' => 4,
    ),
    4 => 
    array (
      'id' => 'spices',
      'name' => 'Sample Spice',
      'slug' => 'spices',
      'desc' => '单体与复合Sample Spice，源头直采。',
      'order' => 5,
    ),
  ),
  'products' => 
  array (
    0 => 
    array (
      'id' => 'orleans-801',
      'name' => 'Sample Flavor 801 Sample SnackSample Marinade',
      'short_name' => 'Sample Flavor 801',
      'slug' => 'orleans-801',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '经典Sample Flavor风味。每 500 g 鸡腿肉配 8 g Sample Marinade，口味清、不压肉味。',
      'mains' => 
      array (
        0 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡腿肉',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 8 g',
        ),
        2 => 
        array (
          'label' => '冷藏腌制',
          'value' => '4 小时以上',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '鸡腿肉 500 g / Sample Marinade 8 g / 清水 50 g',
          'note' => '按重量比配料',
        ),
        1 => 
        array (
          'step' => '搅拌',
          'value' => '20 分钟',
          'note' => '搅拌至水分被吸收',
        ),
        2 => 
        array (
          'step' => '腌制',
          'value' => '冷藏 4 小时以上',
          'note' => '冷藏环境，密封',
        ),
        3 => 
        array (
          'step' => '后续',
          'value' => '按需Sample Breading',
          'note' => '可搭配金牌脆鳞Sample Breading',
        ),
        4 => 
        array (
          'step' => '油炸',
          'value' => '按工艺要求',
          'note' => '参考同类产品 160℃ 左右',
        ),
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'chain-fastfood',
        2 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'orleans-808',
        1 => 'golden-crispy-coating',
        2 => 'taiwanese-chicken-cutlet-marinade',
      ),
      'net_weight' => NULL,
      'packaging' => NULL,
      'shelf_life' => NULL,
      'storage' => NULL,
      'moq' => NULL,
    ),
    1 => 
    array (
      'id' => 'orleans-808',
      'name' => 'Sample Flavor 808 Sample SnackSample Marinade',
      'short_name' => 'Sample Flavor 808',
      'slug' => 'orleans-808',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => 'Sample Flavor系列，与金牌脆鳞Sample Breading组合使用，二次蘸水轻搓起鳞。',
      'mains' => 
      array (
        0 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡腿肉',
        ),
        1 => 
        array (
          'label' => '搭配建议',
          'value' => '与金牌脆鳞Sample Breading组合使用',
        ),
        2 => 
        array (
          'label' => '起鳞方式',
          'value' => '二次蘸水后轻搓',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '按重量比配料',
          'note' => '用量见产品规格表【待补】',
        ),
        1 => 
        array (
          'step' => '腌制',
          'value' => '按门店工艺时间',
          'note' => '',
        ),
        2 => 
        array (
          'step' => 'Sample Breading',
          'value' => '与金牌脆鳞Sample Breading配合',
          'note' => '二次蘸水后轻搓起鳞',
        ),
      ),
      'scenes' => 
      array (
        0 => 'chain-fastfood',
        1 => 'fried-chicken-shop',
      ),
      'related' => 
      array (
        0 => 'golden-crispy-coating',
        1 => 'orleans-801',
        2 => 'taiwanese-chicken-cutlet-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    2 => 
    array (
      'id' => 'american-fried-chicken-marinade',
      'name' => '美式香甜风味Sample SnackSample Marinade',
      'short_name' => '美式Sample SnackSample Marinade',
      'slug' => 'american-fried-chicken-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '美式风味，腌制后直接下锅。每 500 g 鸡腿肉配 30 g。',
      'mains' => 
      array (
        0 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡腿肉',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 30 g',
        ),
        2 => 
        array (
          'label' => '油温与时间',
          'value' => '160℃ 炸 3 分 25 秒',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '鸡腿肉 500 g / Sample Marinade 30 g',
          'note' => '按重量比配料',
        ),
        1 => 
        array (
          'step' => '腌制',
          'value' => '抓匀腌制',
          'note' => '按门店工艺时间',
        ),
        2 => 
        array (
          'step' => '油炸',
          'value' => '油温 160℃',
          'note' => '控制油温稳定',
        ),
        3 => 
        array (
          'step' => '出锅',
          'value' => '炸 3 分 25 秒',
          'note' => '计时出锅，控油',
        ),
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'golden-crispy-coating',
        1 => 'sample-city-chicken-frame-marinade',
        2 => 'sichuan-spicy-marinade',
      ),
      'alias_internal' => 'Sample Person同款',
      'alias_public' => NULL,
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    3 => 
    array (
      'id' => 'korean-fried-chicken-marinade',
      'name' => '韩式Sample SnackSample Marinade',
      'slug' => 'korean-fried-chicken-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '韩式风味，需要搭配肉用调理料与水。适合外卖与夜市出餐。',
      'mains' => 
      array (
        0 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡腿肉',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 20 g Sample Marinade + 6.5 g 调理料 + 100 g 水',
        ),
        2 => 
        array (
          'label' => '油温与时间',
          'value' => '170℃ 炸 3 分 45 秒',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '鸡腿肉 500 g / Sample Marinade 20 g / 肉用调理料 6.5 g / 水 100 g',
          'note' => '四样按比例配齐',
        ),
        1 => 
        array (
          'step' => '腌制',
          'value' => '拌匀腌制',
          'note' => '让水分充分吸收',
        ),
        2 => 
        array (
          'step' => '油炸',
          'value' => '油温 170℃',
          'note' => '温度高于美式版本',
        ),
        3 => 
        array (
          'step' => '出锅',
          'value' => '炸 3 分 45 秒',
          'note' => '计时出锅',
        ),
      ),
      'scenes' => 
      array (
        0 => 'night-market',
        1 => 'chain-fastfood',
      ),
      'related' => 
      array (
        0 => 'meat-conditioner',
        1 => 'garlic-marinade',
        2 => 'spicy-bbq-sprinkle',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    4 => 
    array (
      'id' => 'sample-city-chicken-frame-marinade',
      'name' => '生Sample Snack架Sample Marinade',
      'slug' => 'sample-city-chicken-frame-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => 'Sample City特色鸡架用Sample Marinade。每 500 g 鸡架配 25 g。',
      'mains' => 
      array (
        0 => '鸡架',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡架',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 25 g',
        ),
        2 => 
        array (
          'label' => '油温与时间',
          'value' => '160℃ 炸 3 分 45 秒',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '鸡架 500 g / Sample Marinade 25 g',
          'note' => '按重量比配料',
        ),
        1 => 
        array (
          'step' => '腌制',
          'value' => '抓匀腌制',
          'note' => '让Sample Marinade均匀附着',
        ),
        2 => 
        array (
          'step' => '油炸',
          'value' => '油温 160℃',
          'note' => '控制油温稳定',
        ),
        3 => 
        array (
          'step' => '出锅',
          'value' => '炸 3 分 45 秒',
          'note' => '计时出锅，控油',
        ),
      ),
      'scenes' => 
      array (
        0 => 'night-market',
        1 => 'grill-skewer',
        2 => 'fried-chicken-shop',
      ),
      'related' => 
      array (
        0 => 'american-fried-chicken-marinade',
        1 => 'salt-baked-marinade',
        2 => 'spicy-bbq-sprinkle',
      ),
      'category_note' => 'Example较早推出中式生Sample Snack架Sample Marinade品类，面向Sample City及东北地区鸡架经营场景。',
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    5 => 
    array (
      'id' => 'taiwanese-chicken-cutlet-marinade',
      'name' => '招牌台式鸡排Sample Marinade',
      'slug' => 'taiwanese-chicken-cutlet-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '台式鸡排风味。每 500 g 鸡腿肉配 15 g Sample Marinade，适合汉堡与鸡排渠道。',
      'mains' => 
      array (
        0 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '鸡腿肉',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 15 g Sample Marinade + 6.5 g 调理料 + 100 g 水',
        ),
        2 => 
        array (
          'label' => '油温与时间',
          'value' => '160℃ 炸 3 分 15 秒',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '鸡腿肉 500 g / Sample Marinade 15 g / 肉用调理料 6.5 g / 水 100 g',
          'note' => '四样按比例配齐',
        ),
        1 => 
        array (
          'step' => '腌制',
          'value' => '拌匀腌制',
          'note' => '让水分充分吸收',
        ),
        2 => 
        array (
          'step' => '油炸',
          'value' => '油温 160℃',
          'note' => '控制油温稳定',
        ),
        3 => 
        array (
          'step' => '出锅',
          'value' => '炸 3 分 15 秒',
          'note' => '计时出锅',
        ),
      ),
      'scenes' => 
      array (
        0 => 'chain-fastfood',
        1 => 'fried-chicken-shop',
      ),
      'related' => 
      array (
        0 => 'orleans-808',
        1 => 'granular-coating',
        2 => 'meat-conditioner',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    6 => 
    array (
      'id' => 'garlic-marinade',
      'name' => '中式蒜香Sample Marinade',
      'slug' => 'garlic-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '蒜香风味，需要Sample Breading浆。每 500 g 琵琶腿配 33 g Sample Marinade。',
      'mains' => 
      array (
        0 => '琵琶腿',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用主料',
          'value' => '琵琶腿',
        ),
        1 => 
        array (
          'label' => '推荐用量',
          'value' => '每 500 g 配 33 g',
        ),
        2 => 
        array (
          'label' => 'Sample Breading浆粉水比',
          'value' => '1:1.2',
        ),
        3 => 
        array (
          'label' => '油温与时间',
          'value' => '160℃ 炸 8 分钟',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '配料',
          'value' => '琵琶腿 500 g / Sample Marinade 33 g',
          'note' => '按重量比配料',
        ),
        1 => 
        array (
          'step' => '裹浆',
          'value' => 'Sample Breading浆粉水比 1:1.2',
          'note' => '调浆后裹匀',
        ),
        2 => 
        array (
          'step' => '油炸',
          'value' => '160℃',
          'note' => '控制油温稳定',
        ),
        3 => 
        array (
          'step' => '出锅',
          'value' => '炸 8 分钟',
          'note' => '时间较长，注意火候',
        ),
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'american-fried-chicken-marinade',
        1 => 'golden-crispy-coating',
        2 => 'sichuan-spicy-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    7 => 
    array (
      'id' => 'golden-crispy-coating',
      'name' => '金牌脆鳞Sample Breading',
      'slug' => 'golden-crispy-coating',
      'line' => 'coating',
      'tag' => 'Sample SnackSample BreadingSample Marinade',
      'tagline' => '与Sample Flavor 808 组合使用，二次蘸水轻搓起鳞。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '搭配建议',
          'value' => '与Sample Flavor 808 组合使用',
        ),
        1 => 
        array (
          'label' => '起鳞方式',
          'value' => '二次蘸水后轻搓',
        ),
        2 => 
        array (
          'label' => '效果',
          'value' => '形成明显鳞片',
        ),
      ),
      'params' => 
      array (
        0 => 
        array (
          'step' => '一次Sample Breading',
          'value' => '腌好的鸡腿肉Sample Breading',
          'note' => '轻压使粉附着',
        ),
        1 => 
        array (
          'step' => '二次蘸水',
          'value' => '快速蘸水',
          'note' => '不要久泡，避免冲掉粉层',
        ),
        2 => 
        array (
          'step' => '二次Sample Breading',
          'value' => '再Sample Breading并轻搓',
          'note' => '搓动产生鳞片',
        ),
        3 => 
        array (
          'step' => '油炸',
          'value' => '按工艺要求',
          'note' => '参考同类产品 160℃ 左右',
        ),
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'chain-fastfood',
      ),
      'related' => 
      array (
        0 => 'orleans-808',
        1 => 'granular-coating',
        2 => 'korean-fried-chicken-batter',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    8 => 
    array (
      'id' => 'granular-coating',
      'name' => '大鸡排颗粒粉',
      'slug' => 'granular-coating',
      'line' => 'coating',
      'tag' => 'Sample SnackSample BreadingSample Marinade',
      'tagline' => '大鸡排上浆用颗粒粉。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用场景',
          'value' => '大鸡排上浆',
        ),
        1 => 
        array (
          'label' => '搭配建议',
          'value' => '与台式鸡排Sample Marinade配合',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'chain-fastfood',
      ),
      'related' => 
      array (
        0 => 'taiwanese-chicken-cutlet-marinade',
        1 => 'golden-crispy-coating',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    9 => 
    array (
      'id' => 'korean-fried-chicken-batter',
      'name' => '韩式Sample Snack浆粉',
      'slug' => 'korean-fried-chicken-batter',
      'line' => 'coating',
      'tag' => 'Sample SnackSample BreadingSample Marinade',
      'tagline' => '韩式Sample Snack挂浆用粉。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用场景',
          'value' => '韩式Sample Snack挂浆',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'korean-fried-chicken-marinade',
        1 => 'golden-crispy-coating',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    10 => 
    array (
      'id' => 'meat-conditioner',
      'name' => '肉用调理料',
      'slug' => 'meat-conditioner',
      'line' => 'flavor',
      'tag' => '调味香精',
      'tagline' => '提升肉的持水与口感，与Sample Marinade配合使用。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '作用',
          'value' => '提升持水与口感',
        ),
        1 => 
        array (
          'label' => '搭配用量',
          'value' => '每 500 g 主料配 6.5 g',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'night-market',
        1 => 'chain-fastfood',
      ),
      'related' => 
      array (
        0 => 'korean-fried-chicken-marinade',
        1 => 'taiwanese-chicken-cutlet-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    11 => 
    array (
      'id' => 'chicken-flavor-76809',
      'name' => '鸡肉肉香素 76809',
      'slug' => 'chicken-flavor-76809',
      'line' => 'flavor',
      'tag' => '调味香精',
      'tagline' => '鸡肉风味香精，用于风味补强。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '类型',
          'value' => '液体精油 / 固体粉末 / 半固态膏状',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'canteen',
      ),
      'related' => 
      array (
        0 => 'chicken-powder-seasoning',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    12 => 
    array (
      'id' => 'sichuan-spicy-marinade',
      'name' => '川香麻辣Sample Marinade',
      'slug' => 'sichuan-spicy-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '川味方向，适配炒、炸。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '川香麻辣',
        ),
        1 => 
        array (
          'label' => '使用方式',
          'value' => '腌、拌、炒均可',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'canteen',
        1 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'five-spice-marinade',
        1 => 'peppercorn-marinade',
        2 => 'sichuan-spicy-sprinkle',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    13 => 
    array (
      'id' => 'five-spice-marinade',
      'name' => '中式五香Sample Marinade',
      'slug' => 'five-spice-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '传统味型，适配蒸、卤、焖。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '中式五香',
        ),
        1 => 
        array (
          'label' => '使用方式',
          'value' => '蒸、卤、焖均可',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'canteen',
      ),
      'related' => 
      array (
        0 => 'sichuan-spicy-marinade',
        1 => 'peppercorn-marinade',
        2 => 'chicken-powder-seasoning',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    14 => 
    array (
      'id' => 'peppercorn-marinade',
      'name' => '藤椒风味Sample Marinade',
      'slug' => 'peppercorn-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '清香麻辣，做出差异化味型。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '藤椒清香麻辣',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'canteen',
      ),
      'related' => 
      array (
        0 => 'sichuan-spicy-marinade',
        1 => 'five-spice-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    15 => 
    array (
      'id' => 'black-pepper-marinade',
      'name' => '黑椒风味Sample Marinade',
      'slug' => 'black-pepper-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '经典口味，大众接受度高。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '黑椒',
        ),
        1 => 
        array (
          'label' => '适配做法',
          'value' => '煎、烤、炸均可',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'light-meal',
        1 => 'grill-skewer',
      ),
      'related' => 
      array (
        0 => 'low-calorie-chicken-marinade',
        1 => 'provence-chicken-marinade',
        2 => 'salt-baked-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    16 => 
    array (
      'id' => 'salt-baked-marinade',
      'name' => '盐焗风味Sample Marinade',
      'slug' => 'salt-baked-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '咸香底味，区别于传统卤味。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '盐焗咸香',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'grill-skewer',
      ),
      'related' => 
      array (
        0 => 'black-pepper-marinade',
        1 => 'sample-city-chicken-frame-marinade',
        2 => 'original-bbq-sprinkle',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    17 => 
    array (
      'id' => 'low-calorie-chicken-marinade',
      'name' => '低卡轻食鸡排Sample Marinade',
      'slug' => 'low-calorie-chicken-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '面向低卡需求，适合水煮、空气炸、少油煎。',
      'mains' => 
      array (
        0 => '鸡胸肉',
        1 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适配做法',
          'value' => '水煮、空气炸、少油煎',
        ),
        1 => 
        array (
          'label' => '配比原则',
          'value' => '按 500 g 主料配比',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'light-meal',
      ),
      'related' => 
      array (
        0 => 'provence-chicken-marinade',
        1 => 'black-pepper-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    18 => 
    array (
      'id' => 'provence-chicken-marinade',
      'name' => '普罗旺斯鸡排Sample Marinade',
      'slug' => 'provence-chicken-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '香草风味，做味型差异。',
      'mains' => 
      array (
        0 => '鸡胸肉',
        1 => '鸡腿肉',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '西式香草',
        ),
        1 => 
        array (
          'label' => '适配做法',
          'value' => '煎、烤、空气炸',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'light-meal',
      ),
      'related' => 
      array (
        0 => 'low-calorie-chicken-marinade',
        1 => 'black-pepper-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    19 => 
    array (
      'id' => 'spicy-bbq-sprinkle',
      'name' => '辣味烧烤撒料',
      'slug' => 'spicy-bbq-sprinkle',
      'line' => 'seasoning',
      'tag' => '撒料',
      'tagline' => '出锅前撒料，做重口味差异款。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '使用时机',
          'value' => '出锅前撒',
        ),
        1 => 
        array (
          'label' => '味型',
          'value' => '辣味',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'night-market',
        1 => 'grill-skewer',
      ),
      'related' => 
      array (
        0 => 'garlic-spicy-sprinkle',
        1 => 'original-bbq-sprinkle',
        2 => 'salt-baked-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    20 => 
    array (
      'id' => 'original-bbq-sprinkle',
      'name' => '原味烧烤撒料',
      'slug' => 'original-bbq-sprinkle',
      'line' => 'seasoning',
      'tag' => '撒料',
      'tagline' => '不抢味，做基础出品。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '使用时机',
          'value' => '出锅前撒',
        ),
        1 => 
        array (
          'label' => '味型',
          'value' => '原味',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'grill-skewer',
      ),
      'related' => 
      array (
        0 => 'spicy-bbq-sprinkle',
        1 => 'salt-baked-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    21 => 
    array (
      'id' => 'garlic-spicy-sprinkle',
      'name' => '蒜香麻辣撒料',
      'slug' => 'garlic-spicy-sprinkle',
      'line' => 'seasoning',
      'tag' => '撒料',
      'tagline' => '蒜香与麻辣结合，做差异化味型。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '使用时机',
          'value' => '出锅前撒',
        ),
        1 => 
        array (
          'label' => '味型',
          'value' => '蒜香麻辣',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'spicy-bbq-sprinkle',
        1 => 'korean-fried-chicken-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    22 => 
    array (
      'id' => 'noodle-soup-powder',
      'name' => '粉面调汤粉',
      'slug' => 'noodle-soup-powder',
      'line' => 'seasoning',
      'tag' => '粉料',
      'tagline' => '粉面汤底调味用粉。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '用途',
          'value' => '粉面汤底调味',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'chain-fastfood',
      ),
      'related' => 
      array (
        0 => 'taiwanese-chicken-cutlet-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    23 => 
    array (
      'id' => 'chicken-powder-seasoning',
      'name' => '鸡粉调味料',
      'slug' => 'chicken-powder-seasoning',
      'line' => 'seasoning',
      'tag' => '粉料',
      'tagline' => '汤菜与炒菜的基础提鲜。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '用途',
          'value' => '汤菜与炒菜基础提鲜',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'canteen',
      ),
      'related' => 
      array (
        0 => 'five-spice-marinade',
        1 => 'chicken-flavor-76809',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    24 => 
    array (
      'id' => 'sichuan-spicy-sprinkle',
      'name' => '招牌麻辣风味撒料',
      'slug' => 'sichuan-spicy-sprinkle',
      'line' => 'seasoning',
      'tag' => '撒料',
      'tagline' => '招牌麻辣风味，出锅前撒。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '使用时机',
          'value' => '出锅前撒',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'spicy-bbq-sprinkle',
        1 => 'sichuan-spicy-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    25 => 
    array (
      'id' => 'cumin-marinade',
      'name' => '东北熟香孜然Sample Marinade',
      'slug' => 'cumin-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '东北熟香孜然风味，面向东北烧烤与炸串场景。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '孜然熟香',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'grill-skewer',
        1 => 'night-market',
      ),
      'related' => 
      array (
        0 => 'salt-baked-marinade',
        1 => 'spicy-bbq-sprinkle',
      ),
      'category_note' => 'Example较早推出该品类。',
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    26 => 
    array (
      'id' => 'milk-fried-meat-marinade',
      'name' => '牛奶炸肉Sample Marinade',
      'slug' => 'milk-fried-meat-marinade',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '牛奶炸肉方向的风味Sample Marinade。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '味型方向',
          'value' => '奶香',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
      ),
      'related' => 
      array (
        0 => 'american-fried-chicken-marinade',
      ),
      'category_note' => 'Example较早推出该品类。',
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    27 => 
    array (
      'id' => 'yanhong-sichuan-peppercorn',
      'name' => '汉源青花椒',
      'slug' => 'hanyuan-green-peppercorn',
      'line' => 'spices',
      'tag' => 'Sample Spice',
      'tagline' => '单体Sample Spice，源头直采。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '类型',
          'value' => '单体Sample Spice',
        ),
        1 => 
        array (
          'label' => '产地方向',
          'value' => '汉源',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
      ),
      'related' => 
      array (
        0 => 'indian-chili-powder',
        1 => 'taiwan-five-spice-powder',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    28 => 
    array (
      'id' => 'indian-chili-powder',
      'name' => '印度辣椒粉',
      'slug' => 'indian-chili-powder',
      'line' => 'spices',
      'tag' => 'Sample Spice',
      'tagline' => '单体Sample Spice，源头直采。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '类型',
          'value' => '单体Sample Spice',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
      ),
      'related' => 
      array (
        0 => 'hanyuan-green-peppercorn',
        1 => 'taiwan-five-spice-powder',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    29 => 
    array (
      'id' => 'taiwan-five-spice-powder',
      'name' => '招牌台式五香粉',
      'slug' => 'taiwan-five-spice-powder',
      'line' => 'spices',
      'tag' => 'Sample Spice',
      'tagline' => '复合Sample Spice组合。',
      'mains' => 
      array (
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '类型',
          'value' => '复合Sample Spice',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
      ),
      'related' => 
      array (
        0 => 'hanyuan-green-peppercorn',
        1 => 'indian-chili-powder',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
    30 => 
    array (
      'id' => 'iron-griddle-chicken-frame-2',
      'name' => '铁板鸡架 2 号',
      'slug' => 'iron-griddle-chicken-frame-2',
      'line' => 'seasoning',
      'tag' => 'Sample SnackSample Marinade',
      'tagline' => '铁板鸡架场景配套Sample Marinade。',
      'mains' => 
      array (
        0 => '鸡架',
      ),
      'key_params' => 
      array (
        0 => 
        array (
          'label' => '适用场景',
          'value' => '铁板鸡架',
        ),
      ),
      'params' => 
      array (
      ),
      'scenes' => 
      array (
        0 => 'fried-chicken-shop',
      ),
      'related' => 
      array (
        0 => 'sample-city-chicken-frame-marinade',
        1 => 'american-fried-chicken-marinade',
      ),
      'net_weight' => NULL,
      'moq' => NULL,
    ),
  ),
  'scenes' => 
  array (
    0 => 
    array (
      'id' => 'fried-chicken-shop',
      'name' => 'Sample Snack创业小店',
      'slug' => 'fried-chicken-shop',
      'title_q' => 'Sample Snack创业小店怎么选Sample Marinade？',
      'desc' => '刚起步，要的是出品稳定、上手快',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '没有配方积累，出品靠手感',
          'desc' => '今天咸明天淡，顾客第一次觉得好吃，第二次就不一定了。',
        ),
        1 => 
        array (
          'title' => '不知道该进哪几款料',
          'desc' => '品类太多，怕买了用不上，压在库里。',
        ),
        2 => 
        array (
          'title' => '起量之后供货跟不上',
          'desc' => '小店刚有起色，供货的稳定性就成了瓶颈。',
        ),
      ),
      'combo' => 
      array (
        0 => 'iron-griddle-chicken-frame-2',
        1 => 'american-fried-chicken-marinade',
        2 => 'sample-city-chicken-frame-marinade',
        3 => 'golden-crispy-coating',
      ),
      'combo_reason' => '四款覆盖「鸡腿 + 鸡架」两个主力品类。美式Sample Marinade负责出餐快的单品，生Sample Snack架Sample Marinade做Sample City特色差异化，脆鳞Sample Breading解决「看起来不够香」的问题。',
      'key_param_product' => 'american-fried-chicken-marinade',
      'key_param_display' => '鸡腿肉 500 g + Sample Marinade 30 g，油温 160℃，炸 3 分 25 秒',
      'hover_reveal' => '鸡腿肉 500g ＋ Sample Marinade 30g｜160℃，炸 3 分 25 秒',
      'adjacent' => 
      array (
        0 => 'night-market',
        1 => 'chain-fastfood',
      ),
      'order' => 1,
      'priority' => 'P0',
    ),
    1 => 
    array (
      'id' => 'night-market',
      'name' => '夜市 / 烧烤摊',
      'slug' => 'night-market',
      'title_q' => '夜市和烧烤摊用什么Sample Marinade？',
      'desc' => '出餐要快，味道要够冲',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '出餐慢就是丢客',
          'desc' => '夜市客流集中在几个时段，一慢就排队走人。',
        ),
        1 => 
        array (
          'title' => '味道不够冲，回头客少',
          'desc' => '夜市拼的是第一口的记忆点，味型平就没人记住。',
        ),
        2 => 
        array (
          'title' => '摊位上条件有限',
          'desc' => '没有后厨、没有称量设备，工序越简单越好。',
        ),
      ),
      'combo' => 
      array (
        0 => 'korean-fried-chicken-marinade',
        1 => 'spicy-bbq-sprinkle',
        2 => 'garlic-spicy-sprinkle',
        3 => 'meat-conditioner',
      ),
      'combo_reason' => '夜市的核心矛盾是「快」和「够味」。腌制负责底味，撒料负责出锅前的最后一击。肉用调理料解决「炸久了发柴」的问题。',
      'key_param_product' => 'korean-fried-chicken-marinade',
      'key_param_display' => '鸡腿肉 500 g + Sample Marinade 20 g + 调理料 6.5 g + 水 100 g，170℃，炸 3 分 45 秒',
      'hover_reveal' => '鸡腿肉 500g ＋ Sample Marinade 20g ＋ 调理料 6.5g ＋ 水 100g｜170℃，3 分 45 秒',
      'adjacent' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'grill-skewer',
      ),
      'order' => 2,
      'priority' => 'P0',
    ),
    2 => 
    array (
      'id' => 'chain-fastfood',
      'name' => '连锁快餐 / 外卖',
      'slug' => 'chain-fastfood',
      'title_q' => '连锁快餐和外卖怎么保证每家店味道一样？',
      'desc' => '要标准化，要稳定供货',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '门店多了口味就开始飘',
          'desc' => '每家店的老师傅手感不一样，同一个产品在两个城市是两种味道。',
        ),
        1 => 
        array (
          'title' => '新人上手慢',
          'desc' => '靠经验传授，培训周期长，人走味就变。',
        ),
        2 => 
        array (
          'title' => '供货跟不上扩张',
          'desc' => '开到第十家店的时候，原料供应成了扩张的天花板。',
        ),
      ),
      'combo' => 
      array (
        0 => 'orleans-808',
        1 => 'taiwanese-chicken-cutlet-marinade',
        2 => 'granular-coating',
        3 => 'noodle-soup-powder',
      ),
      'combo_reason' => '连锁场景的解法不是「更好吃」，是「更一致」。四款用量都按主料重量精确配比，门店只需按比例操作。粉面调汤粉让门店能在同一套供货体系里把配套产品也配齐。',
      'key_param_product' => 'taiwanese-chicken-cutlet-marinade',
      'key_param_display' => '鸡腿肉 500 g + Sample Marinade 15 g + 调理料 6.5 g + 水 100 g，160℃，炸 3 分 15 秒',
      'hover_reveal' => '鸡腿肉 500g ＋ Sample Marinade 15g ＋ 调理料 6.5g ＋ 水 100g｜160℃，3 分 15 秒',
      'adjacent' => 
      array (
        0 => 'fried-chicken-shop',
        1 => 'canteen',
      ),
      'order' => 3,
      'priority' => 'P0',
    ),
    3 => 
    array (
      'id' => 'canteen',
      'name' => '中餐酒楼 / 食堂',
      'slug' => 'canteen',
      'title_q' => '中餐酒楼和食堂的鸡肉菜用什么Sample Marinade？',
      'desc' => '要复合风味，能直接上菜',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '要的是复合风味，不是单一Sample Snack味',
          'desc' => '酒楼菜单上鸡肉菜式多，味型不能只有一种。',
        ),
        1 => 
        array (
          'title' => '菜品要能标准化出餐',
          'desc' => '厨师换了，招牌菜不能跟着变味。',
        ),
        2 => 
        array (
          'title' => '采购品类杂，对账麻烦',
          'desc' => '调味料供应商分散，品控和采购成本都高。',
        ),
      ),
      'combo' => 
      array (
        0 => 'five-spice-marinade',
        1 => 'sichuan-spicy-marinade',
        2 => 'peppercorn-marinade',
        3 => 'chicken-powder-seasoning',
      ),
      'combo_reason' => '中餐的Sample Marinade用法比Sample Snack店复杂，工序不统一，所以参数不能一刀切。四款覆盖「五香、麻辣、藤椒、基础提鲜」四个方向，后厨可按菜式自行组合。',
      'key_param_product' => NULL,
      'key_param_display' => NULL,
      'param_note' => 
      array (
        0 => 
        array (
          'label' => '配比原则',
          'value' => '按 500 g 主料配比，可随菜式调整风味方向',
        ),
        1 => 
        array (
          'label' => '使用方式',
          'value' => '腌、拌、炒、焖均可；不同菜式用量需按风味强度调整',
        ),
        2 => 
        array (
          'label' => '建议路径',
          'value' => '先拿样品试做一两道招牌菜，确定用量后再定标准',
        ),
      ),
      'hover_reveal' => '该组合按 500 g 主料配比，支持按菜式调整风味方向',
      'adjacent' => 
      array (
        0 => 'chain-fastfood',
        1 => 'light-meal',
      ),
      'order' => 4,
      'priority' => 'P2',
    ),
    4 => 
    array (
      'id' => 'light-meal',
      'name' => '轻食 / 健身渠道',
      'slug' => 'light-meal',
      'title_q' => '轻食和健身餐的鸡胸鸡排怎么腌才不寡淡？',
      'desc' => '要低卡，但不能寡淡',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '低卡和好吃难两全',
          'desc' => '减了油盐之后味道就撑不起来。',
        ),
        1 => 
        array (
          'title' => '水煮和空气炸容易柴',
          'desc' => '少油做法对肉的持水性要求更高。',
        ),
        2 => 
        array (
          'title' => '顾客吃两次就腻',
          'desc' => '味型太单一，复购撑不住。',
        ),
      ),
      'combo' => 
      array (
        0 => 'low-calorie-chicken-marinade',
        1 => 'provence-chicken-marinade',
        2 => 'black-pepper-marinade',
      ),
      'combo_reason' => '轻食场景的味型轮换比Sample Snack店更重要，因为顾客每周可能吃三四次。三款覆盖「清淡、香草、黑椒」三个方向，可按周轮换。',
      'key_param_product' => NULL,
      'key_param_display' => NULL,
      'param_note' => 
      array (
        0 => 
        array (
          'label' => '适配做法',
          'value' => '水煮、空气炸、少油煎',
        ),
        1 => 
        array (
          'label' => '配比原则',
          'value' => '按 500 g 主料配比',
        ),
        2 => 
        array (
          'label' => '烹饪要点',
          'value' => '水煮建议低温慢煮，空气炸中途翻面，避免表面过干',
        ),
      ),
      'hover_reveal' => '适合水煮、空气炸、少油煎。按 500 g 主料配比',
      'adjacent' => 
      array (
        0 => 'canteen',
        1 => 'chain-fastfood',
      ),
      'order' => 5,
      'priority' => 'P2',
    ),
    5 => 
    array (
      'id' => 'grill-skewer',
      'name' => '卤味 / 烤串店',
      'slug' => 'grill-skewer',
      'title_q' => '卤味和烤串店怎么把风味做出层次？',
      'desc' => '要风味层次，做出差异化',
      'pain_points' => 
      array (
        0 => 
        array (
          'title' => '只有咸辣两个味型',
          'desc' => '顾客尝不出和别人家的区别。',
        ),
        1 => 
        array (
          'title' => '撒料和Sample Marinade不配套',
          'desc' => '底味和表面味道打架，吃起来乱。',
        ),
        2 => 
        array (
          'title' => '隔壁卖的跟你一样',
          'desc' => '同一条街上产品高度同质。',
        ),
      ),
      'combo' => 
      array (
        0 => 'salt-baked-marinade',
        1 => 'black-pepper-marinade',
        2 => 'original-bbq-sprinkle',
        3 => 'spicy-bbq-sprinkle',
      ),
      'combo_reason' => '烤串与卤味的味型是「底味 + 表面味」两层。Sample Marinade决定肉的本味，撒料决定顾客第一口的印象。四款按「两种底味 × 两种撒料」组合，能搭出四种出品。',
      'key_param_product' => NULL,
      'key_param_display' => NULL,
      'param_note' => 
      array (
        0 => 
        array (
          'label' => '底味',
          'value' => '盐焗或黑椒Sample Marinade，按 500 g 主料配比',
        ),
        1 => 
        array (
          'label' => '表面味',
          'value' => '出锅前撒原味或辣味撒料',
        ),
        2 => 
        array (
          'label' => 'Sample City特色参考',
          'value' => '若做鸡架品类，可参考生Sample Snack架Sample Marinade：鸡架 500 g 配 25 g Sample Marinade，160℃ 炸 3 分 45 秒',
        ),
      ),
      'hover_reveal' => '两种底味 × 两种撒料，可搭出四种出品',
      'adjacent' => 
      array (
        0 => 'night-market',
        1 => 'fried-chicken-shop',
      ),
      'order' => 6,
      'priority' => 'P2',
    ),
  ),
  'brand_language' => 
  array (
    'slogan' => '用真诚心，做好每一份鸡肉',
    'mission' => '用真诚心做好每一份调味料',
    'values' => '以精立业、以质取胜，合作共赢！',
    'vision' => '立为民普食大志，振兴酉合之雄风',
    'vision_conflict' => 
    array (
      'source_a' => 
      array (
        'from' => 'Example Food企业介绍 2026 版',
        'text' => '打造中国中式Sample SnackSample Marinade领先品牌',
        'risk' => '含绝对化用语',
      ),
      'source_b' => 
      array (
        'from' => 'Example VI 手册 v2.0',
        'text' => '立为民普食大志，振兴酉合之雄风',
        'risk' => '无',
      ),
      'recommendation' => 'source_b',
      'status' => '待企业裁定',
    ),
    'mission_conflict' => 
    array (
      'source_a' => 
      array (
        'from' => '企业介绍 2026 版',
        'text' => '用真诚心做好每一份调味料',
      ),
      'source_b' => 
      array (
        'from' => 'VI 手册 v2.0 Slogan',
        'text' => '用真诚心，做好每一份鸡肉',
      ),
      'status' => '待企业统一',
    ),
  ),
  'cooperation' => 
  array (
    'types' => 
    array (
      0 => 
      array (
        'id' => 'custom',
        'name' => '定制研发',
        'fit' => '已有门店或产品线，想定口味、定标准的客户',
        'includes' => 
        array (
          0 => '口味方向沟通',
          1 => '配方打样',
          2 => '试样确认',
          3 => '量产交付',
        ),
        'cta' => '获取定制方案',
      ),
      1 => 
      array (
        'id' => 'oem',
        'name' => 'OEM / ODM 代工',
        'fit' => '想做自己品牌，不想自己建厂的客户',
        'includes' => 
        array (
          0 => '用我们的配方打你的品牌',
          1 => '或按你的配方代工',
        ),
        'cta' => '咨询代工政策',
      ),
      2 => 
      array (
        'id' => 'dealer',
        'name' => '经销合作',
        'fit' => '有区域渠道资源的客户',
        'includes' => 
        array (
          0 => '区域供货',
          1 => '经销政策',
          2 => '渠道支持',
        ),
        'cta' => '咨询经销政策',
      ),
    ),
    'process' => 
    array (
      0 => 
      array (
        'step' => 1,
        'name' => '需求沟通',
        'desc' => '说清你的产品、口味方向和预计用量',
      ),
      1 => 
      array (
        'step' => 2,
        'name' => '配方打样',
        'desc' => '按需求出样，给你试',
      ),
      2 => 
      array (
        'step' => 3,
        'name' => '试样确认',
        'desc' => '你试完确认风味与工艺，锁定配方',
      ),
      3 => 
      array (
        'step' => 4,
        'name' => '量产',
        'desc' => '四大车间安排生产，按约定交付',
      ),
      4 => 
      array (
        'step' => 5,
        'name' => '持续供货',
        'desc' => '稳定供货，后续按需调整',
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
      'title' => '社区Sample Snack小店',
      'region_label' => '〔东北 · Sample City〕',
      'scene' => 'fried-chicken-shop',
      'quote' => '刚开店的时候最怕口味不稳，客人说今天咸明天淡。',
      'quote_verified' => false,
      'combo' => 
      array (
        0 => 'american-fried-chicken-marinade',
        1 => 'golden-crispy-coating',
        2 => 'sample-city-chicken-frame-marinade',
      ),
      'image' => NULL,
      'detail_page' => false,
    ),
    1 => 
    array (
      'id' => 'case-02',
      'title' => '连锁快餐品牌',
      'region_label' => '〔华东 · 连锁〕',
      'scene' => 'chain-fastfood',
      'quote' => '门店多起来之后，最重要的是每家店味道一样。',
      'quote_verified' => false,
      'combo' => 
      array (
        0 => 'orleans-808',
        1 => 'taiwanese-chicken-cutlet-marinade',
        2 => 'granular-coating',
      ),
      'image' => NULL,
      'detail_page' => false,
    ),
    2 => 
    array (
      'id' => 'case-03',
      'title' => '夜市烧烤摊位',
      'region_label' => '〔华北 · 夜市〕',
      'scene' => 'night-market',
      'quote' => '夜市出餐慢就是丢客。料要够冲、上手要快。',
      'quote_verified' => false,
      'combo' => 
      array (
        0 => 'korean-fried-chicken-marinade',
        1 => 'spicy-bbq-sprinkle',
        2 => 'garlic-spicy-sprinkle',
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
      3 => '销量第一',
      4 => '第一',
      5 => '最',
      6 => '领导者',
      7 => '领先',
      8 => '首创',
      9 => '国家级',
      10 => '独家',
      11 => '顶级',
      12 => '极致',
      13 => '唯一',
    ),
    'banned_comparisons' => 
    array (
      0 => 'Sample Chain同款',
      1 => 'Sample Chain',
      2 => '麦当劳',
      3 => 'Sample Person同款',
      4 => 'Sample Person',
      5 => 'Sample Port同款',
      6 => 'Sample Port',
      7 => '华莱士同款',
    ),
    'vague_terms' => 
    array (
      0 => 
      array (
        'banned' => '多年经验',
        'replace' => '二十年',
      ),
      1 => 
      array (
        'banned' => '全国各地',
        'replace' => '全国七大销售区域',
      ),
      2 => 
      array (
        'banned' => '全国各地客户',
        'replace' => '覆盖七大销售区域的客户',
      ),
      3 => 
      array (
        'banned' => '行业首创',
        'replace' => '率先推出',
      ),
      4 => 
      array (
        'banned' => '最大的',
        'replace' => '约 9,000 平方米的',
      ),
    ),
    'canonical_values' => 
    array (
      'founded' => '2017 年 3 月',
      'established_production' => '2018 年 3 月',
      'capacity' => '约 8,000 吨成品',
      'investment' => '约 500 万元',
      'phone' => '400-000-0000',
      'address' => 'Sample Province省Sample City市沈河区 Example Street 39',
      'area' => '约 9,000 平方米',
    ),
    'forbidden_practices' => 
    array (
      0 => '不得虚构数据、奖项、认证、客户名称',
      1 => '不得将示意引述包装成真实客户证言',
      2 => '不得在待补字段填写占位符文字，应隐藏字段或整块暂缓',
      3 => '不得使用未授权的客户名称与商标',
    ),
  ),
  'gaps' => 
  array (
    0 => 
    array (
      'id' => 'sc_license',
      'item' => 'SC 食品生产许可证编号',
      'blocks' => 
      array (
        0 => '工厂与资质页·资质区块',
        1 => '全站页脚合规行',
      ),
      'priority' => 'P0',
      'action' => '未拿到前，资质区块整体隐藏',
    ),
    1 => 
    array (
      'id' => 'standard_code',
      'item' => '执行标准号（GB / Q 企标）',
      'blocks' => 
      array (
        0 => '工厂与资质页',
        1 => '产品页规格区',
      ),
      'priority' => 'P0',
      'action' => '未拿到前，对应字段隐藏',
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
      'action' => '隐藏对应字段，用「说清你的品类和用量，我们按你的情况报价」替代',
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
      'item' => '车间与工厂实拍图 5 张',
      'blocks' => 
      array (
        0 => '工厂与资质页',
        1 => '首页 S05',
        2 => '企业文化页',
      ),
      'priority' => 'P1',
      'action' => '未拿到前隐藏车间区块，不得用渲染图顶替',
    ),
    6 => 
    array (
      'id' => 'scene_photos',
      'item' => '场景实拍图 6 张',
      'blocks' => 
      array (
        0 => '场景列表页',
        1 => '场景详情页头图',
      ),
      'priority' => 'P1',
      'action' => '未拿到前用纯色块占位或省略头图',
    ),
    7 => 
    array (
      'id' => 'logo_svg',
      'item' => 'Logo SVG 源文件',
      'blocks' => 
      array (
        0 => '全站导航与页脚',
      ),
      'priority' => 'P1',
      'action' => '必须有 SVG，不得用位图',
    ),
    8 => 
    array (
      'id' => 'vision_ruling',
      'item' => '企业愿景口径裁定',
      'blocks' => 
      array (
        0 => '关于我们·企业文化页',
      ),
      'priority' => 'P1',
      'action' => '先采用 VI 手册版本，待企业确认后替换',
    ),
    9 => 
    array (
      'id' => 'case_quotes',
      'item' => '案例引述确认或改无引述版本',
      'blocks' => 
      array (
        0 => '首页 S07',
        1 => '案例列表页',
      ),
      'priority' => 'P2',
      'action' => '未确认前改为无引述版本，只保留客户类型 + 区域 + 使用组合',
    ),
    10 => 
    array (
      'id' => 'wechat_qr',
      'item' => '微信二维码（建议活码）',
      'blocks' => 
      array (
        0 => '联系我们',
        1 => '首页 S08',
      ),
      'priority' => 'P2',
      'action' => '未拿到前隐藏二维码区块',
    ),
    11 => 
    array (
      'id' => 'geo_coords',
      'item' => '厂区经纬度坐标',
      'blocks' => 
      array (
        0 => '联系我们·LocalBusiness Schema',
      ),
      'priority' => 'P2',
      'action' => '未拿到前 Schema 中省略 geo 字段',
    ),
    12 => 
    array (
      'id' => 'icp',
      'item' => 'ICP 备案号',
      'blocks' => 
      array (
        0 => '全站页脚',
      ),
      'priority' => 'P2',
      'action' => '未拿到前该字段留空，不显示占位符',
    ),
    13 => 
    array (
      'id' => 'scene_params',
      'item' => '中餐 / 轻食 / 卤味三类场景的配比参数',
      'blocks' => 
      array (
        0 => '三个场景页的关键参数区',
      ),
      'priority' => 'P2',
      'action' => '用 param_note 文字说明替代，不得编造数字',
    ),
    14 => 
    array (
      'id' => 'case_materials',
      'item' => '可授权的客户案例素材',
      'blocks' => 
      array (
        0 => '案例详情页',
      ),
      'priority' => 'P3',
      'action' => '未授权前不建详情页',
    ),
  ),
);
