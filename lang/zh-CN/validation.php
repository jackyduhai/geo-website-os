<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 验证语言行（简体中文）
    |--------------------------------------------------------------------------
    | 以下语言行包含验证器使用的默认错误消息。部分规则有多个版本
    | （例如大小规则）。可按需调整这些消息。
    */

    'accepted' => '您必须接受 :attribute。',
    'accepted_if' => '当 :other 为 :value 时，您必须接受 :attribute。',
    'active_url' => ':attribute 不是一个有效的网址。',
    'after' => ':attribute 必须是一个晚于 :date 的日期。',
    'after_or_equal' => ':attribute 必须是一个晚于或等于 :date 的日期。',
    'alpha' => ':attribute 只能由字母组成。',
    'alpha_dash' => ':attribute 只能由字母、数字、短横线和下划线组成。',
    'alpha_num' => ':attribute 只能由字母和数字组成。',
    'array' => ':attribute 必须是一个数组。',
    'ascii' => ':attribute 只能包含单字节字母、数字和符号。',
    'before' => ':attribute 必须是一个早于 :date 的日期。',
    'before_or_equal' => ':attribute 必须是一个早于或等于 :date 的日期。',
    'between' => [
        'array' => ':attribute 必须有 :min 到 :max 个单元。',
        'file' => ':attribute 大小必须为 :min 到 :max KB。',
        'numeric' => ':attribute 必须介于 :min 到 :max 之间。',
        'string' => ':attribute 必须为 :min 到 :max 个字符。',
    ],
    'boolean' => ':attribute 必须为真或假。',
    'can' => ':attribute 包含未授权的值。',
    'confirmed' => ':attribute 两次输入不一致。',
    'contains' => ':attribute 缺少必需的值。',
    'current_password' => '密码不正确。',
    'date' => ':attribute 不是一个有效的日期。',
    'date_equals' => ':attribute 必须等于 :date。',
    'date_format' => ':attribute 不匹配格式 :format。',
    'decimal' => ':attribute 必须有 :decimal 位小数。',
    'declined' => ':attribute 必须被拒绝。',
    'declined_if' => '当 :other 为 :value 时，:attribute 必须被拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须为 :digits 位数字。',
    'digits_between' => ':attribute 必须为 :min 到 :max 位数字。',
    'dimensions' => ':attribute 图片尺寸无效。',
    'distinct' => ':attribute 字段存在重复值。',
    'doesnt_end_with' => ':attribute 不能以以下内容结尾：:values。',
    'doesnt_start_with' => ':attribute 不能以以下内容开头：:values。',
    'email' => ':attribute 必须是一个有效的邮箱地址。',
    'ends_with' => ':attribute 必须以以下之一结尾：:values。',
    'enum' => '所选的 :attribute 无效。',
    'exists' => '所选的 :attribute 无效。',
    'extensions' => ':attribute 必须具有以下扩展名之一：:values。',
    'file' => ':attribute 必须是一个文件。',
    'filled' => ':attribute 字段必须有一个值。',
    'gt' => [
        'array' => ':attribute 必须多于 :value 个单元。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须至少有 :value 个单元。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须不少于 :value 个字符。',
    ],
    'hex_color' => ':attribute 必须是有效的十六进制颜色。',
    'image' => ':attribute 必须是图片。',
    'in' => '所选的 :attribute 无效。',
    'in_array' => ':attribute 字段不存在于 :other。',
    'integer' => ':attribute 必须是整数。',
    'ip' => ':attribute 必须是一个有效的 IP 地址。',
    'ipv4' => ':attribute 必须是一个有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是一个有效的 IPv6 地址。',
    'json' => ':attribute 必须是一个有效的 JSON 字符串。',
    'list' => ':attribute 必须是一个列表。',
    'lowercase' => ':attribute 必须为小写。',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个单元。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 不能超过 :value 个单元。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 必须不多于 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是一个有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 不能超过 :max 个单元。',
        'file' => ':attribute 不能大于 :max KB。',
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能多于 :max 个字符。',
    ],
    'max_digits' => ':attribute 不能超过 :max 位数字。',
    'mimes' => ':attribute 必须是一个 :values 类型的文件。',
    'mimetypes' => ':attribute 必须是一个 :values 类型的文件。',
    'min' => [
        'array' => ':attribute 至少有 :min 个单元。',
        'file' => ':attribute 至少为 :min KB。',
        'numeric' => ':attribute 至少为 :min。',
        'string' => ':attribute 至少有 :min 个字符。',
    ],
    'min_digits' => ':attribute 至少有 :min 位数字。',
    'missing' => ':attribute 字段必须缺失。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '所选的 :attribute 无效。',
    'not_regex' => ':attribute 格式无效。',
    'numeric' => ':attribute 必须是数字。',
    'password' => [
        'letters' => ':attribute 必须至少包含一个字母。',
        'mixed' => ':attribute 必须至少包含一个大写和一个小写字母。',
        'numbers' => ':attribute 必须至少包含一个数字。',
        'symbols' => ':attribute 必须至少包含一个符号。',
        'uncompromised' => '所给的 :attribute 已出现在数据泄露中，请选择其他 :attribute。',
    ],
    'present' => ':attribute 字段必须存在。',
    'present_if' => '当 :other 为 :value 时，:attribute 字段必须存在。',
    'prohibited' => ':attribute 字段被禁止。',
    'prohibits' => ':attribute 字段禁止 :other 出现。',
    'regex' => ':attribute 格式无效。',
    'required' => ':attribute 字段必填。',
    'required_array_keys' => ':attribute 必须包含以下键：:values。',
    'required_if' => '当 :other 为 :value 时，:attribute 字段必填。',
    'required_if_accepted' => '当 :other 被接受时，:attribute 字段必填。',
    'required_unless' => '除非 :other 是 :values，否则 :attribute 字段必填。',
    'required_with' => '当 :values 存在时，:attribute 字段必填。',
    'required_with_all' => '当 :values 都存在时，:attribute 字段必填。',
    'required_without' => '当 :values 不存在时，:attribute 字段必填。',
    'required_without_all' => '当 :values 都不存在时，:attribute 字段必填。',
    'same' => ':attribute 和 :other 必须匹配。',
    'size' => [
        'array' => ':attribute 必须包含 :size 个单元。',
        'file' => ':attribute 必须为 :size KB。',
        'numeric' => ':attribute 必须为 :size。',
        'string' => ':attribute 必须为 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以以下之一开头：:values。',
    'string' => ':attribute 必须是字符串。',
    'timezone' => ':attribute 必须是一个有效的时区。',
    'unique' => ':attribute 已被占用。',
    'uploaded' => ':attribute 上传失败。',
    'uppercase' => ':attribute 必须为大写。',
    'url' => ':attribute 必须是一个有效的网址。',
    'ulid' => ':attribute 必须是一个有效的 ULID。',
    'uuid' => ':attribute 必须是一个有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | 自定义验证语言行
    |--------------------------------------------------------------------------
    */

    'custom' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | 自定义验证属性名
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        //
    ],

];
