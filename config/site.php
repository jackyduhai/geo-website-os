<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment Mode
    |--------------------------------------------------------------------------
    |
    | single-site: 单站点模式，unknown host fallback 到 default site
    | multi-site:  多站点模式，unknown host 返回 404（安全）
    |
    | 生产多站点部署必须设置为 multi-site。
    | 开发/单站部署可以保持 single-site。
    |
    */
    'mode' => env('SITE_MODE', 'single-site'),

    /*
    |--------------------------------------------------------------------------
    | Default Site Fallback
    |--------------------------------------------------------------------------
    |
    | 当请求的 domain 无法解析到任何 site 时，是否回退到 default site。
    |
    | single-site 模式：true（兼容现有行为）
    | multi-site 模式：false（安全模式，未知 domain 返回 404）
    |
    */
    'default_fallback' => env('SITE_DEFAULT_FALLBACK', true),

    /*
    |--------------------------------------------------------------------------
    | Default Site Slug
    |--------------------------------------------------------------------------
    |
    | 默认站点的 slug 标识
    |
    */
    'default_slug' => env('SITE_DEFAULT_SLUG', 'default'),

];
