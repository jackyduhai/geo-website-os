<?php

/*
|--------------------------------------------------------------------------
| Localization（前端国际化）
|--------------------------------------------------------------------------
| GEO Website OS v1 前端支持语言、默认语言与 URL 前缀的唯一注册表。
| 语言码采用 BCP-47：zh-CN、en。禁止项目中出现 zh / zh_CN / cn / en-US 等混用。
*/

return [
    // 官方支持的前端语言
    'supported' => ['zh-CN', 'en'],

    // 默认语言（无前缀）
    'default' => 'zh-CN',

    // 系统 / UI 字符串回退语言（Public Content 不使用此回退，见设计 §8）
    'fallback' => 'zh-CN',

    // 每种语言的 URL 前缀（默认语言空前缀）
    'prefixes' => [
        'zh-CN' => '',
        'en'    => 'en',
    ],

    // x-default hreflang 指向（冻结为默认语言根）
    'x_default' => 'zh-CN',
];
