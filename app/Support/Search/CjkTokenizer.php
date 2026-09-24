<?php

namespace App\Support\Search;

/**
 * CJK 分词器（P-STEP 18H-1）。
 * ------------------------------------------------------------------
 * FTS5 默认 unicode61 把连续汉字当成一个 token，且前缀匹配只能命中「词头」，导致
 * 中文搜词尾 / 词中子词（底漆、面漆、固化剂、胶粘剂）召回为 0。本类在写入 / 查询前
 * 对文本做可控切分：
 *
 *   - 连续汉字段 → unigram（单字）+ bigram（相邻两字）。任意连续子串（≥1 字）都能
 *     召回；多字查询因同时要求其 bigram，保证字与字相邻，避免单字 AND 的误召回；
 *   - 连续拉丁 / 数字段 → 整词保留（大小写归一由 FTS unicode61 处理，词干 / 复数由
 *     查询端前缀匹配解决，V1 不做语义分词）。
 *
 * FTS 表存 token 串只用于匹配 / 排名 / 定位；原文始终在普通表 search_documents，
 * 结果展示 / 摘要从原文取，因此 token 化不影响人类看到的内容。
 */
class CjkTokenizer
{
    /**
     * @return array<int,string>
     */
    public static function tokenize(string $text): array
    {
        if (! preg_match_all('/[\p{Han}]+|[A-Za-z0-9]+/u', $text, $m)) {
            return [];
        }

        $tokens = [];

        foreach ($m[0] as $seg) {
            if (preg_match('/^[\p{Han}]+$/u', $seg)) {
                $len = mb_strlen($seg);

                for ($i = 0; $i < $len; $i++) {
                    $tokens[] = mb_substr($seg, $i, 1);       // unigram

                    if ($i < $len - 1) {
                        $tokens[] = mb_substr($seg, $i, 2);   // bigram
                    }
                }
            } else {
                $tokens[] = $seg;
            }
        }

        return $tokens;
    }

    /**
     * 构造适合「结果高亮」的词单元（与匹配口径一致，但避免单字过度高亮 / mark 嵌套）：
     *   - 拉丁 / 数字段：整词；
     *   - 汉字段：多字 → 相邻 bigram（更接近「词」）；仅一个字 → 该 unigram。
     * 去重；安全转义仍由 {@see Highlighter::mark()} 负责。
     *
     * @return array<int,string>
     */
    public static function highlightTerms(string $query): array
    {
        if (! preg_match_all('/[\p{Han}]+|[A-Za-z0-9]+/u', $query, $m)) {
            return [];
        }

        $terms = [];

        foreach ($m[0] as $seg) {
            if (! preg_match('/^[\p{Han}]+$/u', $seg)) {
                $terms[] = $seg;
                continue;
            }

            $len = mb_strlen($seg);

            if ($len === 1) {
                $terms[] = $seg;
                continue;
            }

            for ($i = 0; $i < $len - 1; $i++) {
                $terms[] = mb_substr($seg, $i, 2);
            }
        }

        return array_values(array_unique($terms));
    }

    public static function hasHan(string $token): bool
    {
        return (bool) preg_match('/\p{Han}/u', $token);
    }
}
