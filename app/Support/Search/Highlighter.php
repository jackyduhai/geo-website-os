<?php

namespace App\Support\Search;

/**
 * 搜索高亮的唯一安全边界。
 * ------------------------------------------------------------------
 * SQLite FTS5 的 highlight() / snippet() 不会对原文做 HTML 转义：标题 / 摘要里若含
 * {@code < > &} 或恶意片段，直接渲染会形成 XSS。本类统一处理：
 *
 *   1. 先 e() 把整段纯文本转义；
 *   2. 检索词也先 e()；
 *   3. 再在「转义结果」上用正则包裹 <mark>。
 *
 * 因此输出中 <mark> 是唯一被插入的标签，可安全地在 Blade 中 {!! !!} 输出。
 */
class Highlighter
{
    /**
     * 从原始查询拆出实际检索词（空白分隔，保留 CJK 子串，去重）。
     *
     * @return array<int,string>
     */
    public static function terms(string $query): array
    {
        $parts = preg_split('/\s+/u', trim($query)) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($t) => $t !== '')));
    }

    /**
     * 对纯文本做安全高亮，返回可安全 {!! !!} 输出的 HTML。
     *
     * @param  array<int,string>  $terms
     */
    public static function mark(string $plain, array $terms): string
    {
        $escaped = e($plain);

        foreach (array_unique($terms) as $term) {
            $needle = e($term);
            if ($needle === '') {
                continue;
            }
            $escaped = preg_replace(
                '/'.preg_quote($needle, '/').'/iu',
                '<mark>$0</mark>',
                $escaped,
            ) ?? $escaped;
        }

        return $escaped;
    }

    /**
     * 生成纯文本命中片段（excerpt）：优先在摘要、否则在正文中定位首个检索词，
     * 返回其前后上下文（无命中则回退开头）。返回纯文本，高亮仍由 {@see mark()} 处理。
     */
    public static function excerpt(string $summary, string $body, string $query, int $length = 160): string
    {
        $source = trim($summary) !== '' ? $summary : $body;
        if (trim($source) === '') {
            return '';
        }

        foreach (self::terms($query) as $term) {
            $pos = mb_stripos($source, $term);
            if ($pos !== false) {
                $start = max(0, $pos - 40);
                $fragment = trim(mb_substr($source, $start, $length));

                return ($start > 0 ? '…' : '').$fragment.'…';
            }
        }

        return mb_substr($source, 0, $length);
    }
}
