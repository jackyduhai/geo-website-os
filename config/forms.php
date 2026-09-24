<?php

/*
|--------------------------------------------------------------------------
| Forms（产品化表单）
|--------------------------------------------------------------------------
| 表单字段类型注册表与默认契约（P-STEP 18H-2）。
|  - field_types：每种类型的 input 形态、基础服务端规则、是否需要选项；
|  - V1 不做自由排版 / 多步 / 条件分支 / 复杂计算（接口不堵死）。
*/

return [
    // 出厂默认联系表单 slug
    'default_slug' => 'contact',

    // 蜜罐字段名（默认 website；可在 Form 开关蜜罐）
    'honeypot_field' => 'website',

    // 字段类型注册表
    'field_types' => [
        'text' => [
            'label'   => '单行文本',
            'input'   => 'text',
            'rules'   => ['string', 'max:255'],
            'options' => false,
        ],
        'textarea' => [
            'label'   => '多行文本',
            'input'   => 'textarea',
            'rules'   => ['string', 'max:5000'],
            'options' => false,
        ],
        'email' => [
            'label'   => '邮箱',
            'input'   => 'email',
            'rules'   => ['email', 'max:255'],
            'options' => false,
        ],
        'tel' => [
            'label'   => '电话',
            'input'   => 'tel',
            'rules'   => ['string', 'max:30'],
            'options' => false,
        ],
        'number' => [
            'label'   => '数字',
            'input'   => 'number',
            'rules'   => ['numeric'],
            'options' => false,
        ],
        'select' => [
            'label'   => '下拉选择',
            'input'   => 'select',
            'rules'   => ['string', 'max:120'],
            'options' => true,
        ],
        'radio' => [
            'label'   => '单选',
            'input'   => 'radio',
            'rules'   => ['string', 'max:120'],
            'options' => true,
        ],
        'checkbox' => [
            // 单个无选项 checkbox → accepted；带选项多勾选 → array（service 按选项裁决）
            'label'   => '勾选',
            'input'   => 'checkbox',
            'rules'   => [],
            'options' => true,
        ],
        'date' => [
            'label'   => '日期',
            'input'   => 'date',
            'rules'   => ['date'],
            'options' => false,
        ],
        'url' => [
            'label'   => '网址',
            'input'   => 'url',
            'rules'   => ['url', 'max:500'],
            'options' => false,
        ],
        'hidden' => [
            'label'   => '隐藏字段',
            'input'   => 'hidden',
            'rules'   => ['string', 'max:255'],
            'options' => false,
        ],
    ],
];
