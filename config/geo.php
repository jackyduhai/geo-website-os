<?php

/**
 * GEO 相关配置
 */
return [

    /*
    |------------------------------------------------------------------
    | 应用版本（Versioning Contract，P-STEP 07）
    |------------------------------------------------------------------
    | 单一版本来源：geo:version / geo:upgrade 与 Release Manifest 均读此处。
    */
    'version' => env('GEO_OS_VERSION', '2.0.0'),

    /*
    |------------------------------------------------------------------
    | AI 检索 / 生成式引擎爬虫清单
    |------------------------------------------------------------------
    | 均为各厂商公开披露的 User-Agent 标识。显式放行的目的：
    | 让品牌事实、产品参数、工艺说明可被 AI 引擎读取并引用。
    | 未列出的爬虫由 robots.txt 中的 User-agent: * 覆盖。
    */
    'ai_crawlers' => [
        // OpenAI
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        // Anthropic
        'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'Claude-Web', 'anthropic-ai',
        // Google
        'Googlebot', 'Google-Extended', 'GoogleOther',
        // Microsoft / Bing / Copilot
        'bingbot',
        // Perplexity
        'PerplexityBot', 'Perplexity-User',
        // Apple
        'Applebot', 'Applebot-Extended',
        // 字节（豆包 / 抖音）
        'Bytespider',
        // 深度求索（DeepSeek）
        'DeepSeekBot',
        // 腾讯元宝
        'YuanbaoBot',
        // 百度（文心）
        'Baiduspider',
        // 阿里（通义 / 神马）
        'AliyunSpider', 'YisouSpider',
        // 月之暗面（Kimi）
        'MoonshotBot',
        // 智谱（GLM）
        'ChatGLM-Spider',
        // 360
        '360Spider',
        // 腾讯系（元宝依托搜狗通道）
        'Sogou web spider',
        // 华为（小艺）
        'PetalBot',
        // 其他
        'DuckDuckBot', 'meta-externalagent', 'Amazonbot', 'CCBot',
    ],

    /*
    |------------------------------------------------------------------
    | robots.txt 中对通用爬虫（User-agent: *）禁止抓取的路径
    |------------------------------------------------------------------
    */
    'robots_disallow' => [
        '/admin/',
        '/api/',
        '/search',
        '/*?s=',
        '/assets/temp/',
    ],

    /*
    | robots.txt 末尾追加内容（后台可覆盖，谨慎修改）
    */
    'robots_extra' => '',

    /*
    |------------------------------------------------------------------
    | 发布门禁
    |------------------------------------------------------------------
    | enforce_on_publish: 发布时是否强制校验四层结构与合规表述。
    | 生产环境必须为 true。
    */
    'gate' => [
        'enforce_on_publish' => true,
        'min_evidence'       => 2,
    ],

    /*
    |------------------------------------------------------------------
    | GEOFlow 对接（本期预留）
    |------------------------------------------------------------------
    | enabled 为 false 时，接口仍可访问但拒绝写入，便于联调。
    */
    'sync' => [
        'enabled'  => env('GEOFLOW_SYNC_ENABLED', false),
        'token'    => env('GEOFLOW_TOKEN', ''),
        'endpoint' => env('GEOFLOW_ENDPOINT', ''),
    ],

];
