<?php

namespace App\Support;

use Overtrue\Pinyin\Pinyin;

/**
 * Slug 自动生成（20F-HAT P2-2）。
 *
 * 中文 / 中英混排名称 → 小写字母·数字·连字符的 slug 建议：
 *   「晨光精密制造」→ chen-guang-jing-mi-zhi-zao
 *   「工业防腐涂料 C100」→ gong-ye-fang-fu-tu-liao-c100
 *
 * 只做建议，不做强制：运营者可随时手改；后端仍以 slug 校验规则把关。
 */
class SlugSuggester
{
    public static function fromText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        try {
            $slug = (new Pinyin())->permalink($text, '-');
        } catch (\Throwable) {
            $slug = $text;
        }

        // 兜底清洗：小写化、非法字符转连字符、折叠连字符、掐头去尾
        $slug = strtolower((string) $slug);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        return mb_substr($slug, 0, 96);
    }
}
