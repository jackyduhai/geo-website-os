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
        $path = $this->withLocalePrefix($path);

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

    /**
     * 前台非默认语言（/en）请求时，给相对路径自动补语言前缀，使 url('/products/')
     * 等所有功能性 / 内容链接随语言切换，无需每个 Blade 手工处理（locale 统一兜底）。
     * 仅在 LocaleContext 已由 SetLocale 设为非默认语言时生效：console / 后台 / 默认语言
     * 完全不动；外链、协议相对、tel/mailto、锚点、已带前缀的路径幂等不重复。
     */
    private function withLocalePrefix($path)
    {
        if (! is_string($path)) {
            return $path;
        }
        $locale = Localization\LocaleContext::current();
        if ($locale === null) {
            return $path;
        }
        $prefix = Localization\LocaleRegistry::prefix($locale);
        if ($prefix === '') {
            return $path;
        }
        $candidate = ltrim($path, '/');
        if ($candidate === '' || preg_match('~^(https?:|//|tel:|mailto:|#)~i', $candidate)) {
            if ($candidate === '') {
                return '/' . $prefix; // url('/') => /en
            }
            return $path;
        }
        if ($candidate === $prefix || str_starts_with($candidate, $prefix . '/')) {
            return $path; // 已带语言前缀，幂等
        }
        return '/' . $prefix . '/' . $candidate;
    }
}
