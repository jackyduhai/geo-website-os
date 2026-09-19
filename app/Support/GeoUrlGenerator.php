<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;

/**
 * 通用 UrlGenerator：保留目录型 URL 尾斜杠。
 * ------------------------------------------------------------------
 * 背景：Laravel 底层 UrlGenerator::format() 用 trim($path, '/') 会同时剥掉
 * 首尾斜杠，导致 url('/products/') 输出 /products。前台目录型页面规范要求
 * 以「/」结尾（见 CanonicalizeSlash），若内部链接都丢尾斜杠，每次点击都会
 * 先吃一次 301 才到规范地址，既损性能也不利于 SEO。
 *
 * 单点修复（全站唯一）：仅当传入路径本身以「/」结尾时，在父类生成结果后补回
 * 单个尾斜杠；详情型路径（不以 / 结尾，如 /products/orleans-801）完全不动，
 * query / fragment 原样保留。
 */
class GeoUrlGenerator extends BaseUrlGenerator
{
    public function to($path, $extra = [], $secure = null)
    {
        $wantTrailing = is_string($path)
            && $path !== '/'
            && $this->pathEndsWithSlash($path);

        $url = parent::to($path, $extra, $secure);

        if (! $wantTrailing) {
            return $url;
        }

        // 拆出 fragment，再拆 query，只对 path 部分补斜杠
        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment = '#' . $fragment;
        }
        $query = '';
        if (str_contains($url, '?')) {
            [$url, $query] = explode('?', $url, 2);
            $query = '?' . $query;
        }
        if (! str_ends_with($url, '/')) {
            $url .= '/';
        }

        return $url . $query . $fragment;
    }

    private function pathEndsWithSlash(string $path): bool
    {
        $pathOnly = (string) parse_url($path, PHP_URL_PATH);
        if ($pathOnly === '' || str_contains($pathOnly, '.')) {
            return false; // 带扩展名（文件）不处理
        }
        return str_ends_with($pathOnly, '/');
    }
}
