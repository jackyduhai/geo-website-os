<?php

namespace App\Services\Gate;

use App\Models\Content;
use App\Models\Fact;

/**
 * 内容门禁
 *
 * 设计原则：GEO 规范如果不能变成构建/发布时的硬约束，就等于没有规范。
 * 检验方式很直接：一份缺答案块、缺证据来源、含极限词的内容，能不能被发布出去？
 *
 * 本类的职责就是让答案是「不能」。两条内容路径（后台手动、GEOFlow 推送）
 * 共用这一处实现，避免「手动发的宽松、系统发的严格」这种双标。
 */
class ContentGate
{
    /** 绝对化与夸大表述 */
    public const BANNED_WORDS = [
        '全国销量第一', '销量第一', '销量领先', '行业第一', '中国第一', '全国第一',
        '第一品牌', '领导品牌', '领导者', '行业领导者', '国家级', '世界级',
        '首创', '独家', '唯一', '首家', '最先进', '最好', '最佳', '最优',
        '顶级', '最高级', '最大', '最强', '最低价', '100%', '包治',
    ];

    /** 他方品牌对标（未经授权使用他人商标作比较宣传） */
    public const BANNED_COMPARISON = [
        'Sample Chain', '麦当劳', '德克士', '华莱士', '正新鸡排', 'Sample Port', 'Sample Person',
        '杨国福', '张亮',
    ];

    /** 已知的历史错误口径：出现在新内容里即为事实冲突 */
    public const CONFLICT_VALUES = [
        '3000 吨'  => '年产能应为约 8,000 吨',
        '3000吨'   => '年产能应为约 8,000 吨',
        '2015 年'  => '成立时间应为 2017 年 3 月',
        '2015年'   => '成立时间应为 2017 年 3 月',
        '1000 多万' => '总投资应为约 500 万元',
        '1000多万' => '总投资应为约 500 万元',
        'Example Street 5' => '地址应为Example Street 39',
        '400-000-0000' => '官方电话应为 400-000-0000',
    ];

    /**
     * 执行全量校验
     *
     * @return array{passed:bool, errors:array, warnings:array}
     */
    public function check(Content $c): array
    {
        $errors = [];
        $warnings = [];

        // ---------- 1. 四层结构完整性 ----------
        if (trim((string) $c->geo_conclusion) === '') {
            $errors[] = '缺少「结论」：需一段话直接回答问题，作为答案块首段';
        }
        if (trim((string) $c->geo_explanation) === '') {
            $errors[] = '缺少「解释」：需说明为什么、怎么做';
        }
        if (trim((string) $c->geo_boundary) === '') {
            $errors[] = '缺少「边界」：需写明不适用场景或限制条件';
        }

        // ---------- 2. 证据链 ----------
        $evidence = $c->evidenceList();
        if (count($evidence) < 2) {
            $errors[] = sprintf('证据不足：当前 %d 条，至少需 2 条（label + value + source）', count($evidence));
        } else {
            foreach ($evidence as $i => $e) {
                if (trim((string) ($e['source'] ?? '')) === '') {
                    $errors[] = sprintf('第 %d 条证据缺少来源（source）', $i + 1);
                }
            }
        }

        // ---------- 3. 治理字段 ----------
        if (trim((string) $c->owner) === '') {
            $errors[] = '缺少责任人（owner）';
        }
        if (! $c->reviewed_at) {
            $errors[] = '缺少最后复核日期（reviewed_at）';
        }

        // ---------- 4. 基础字段 ----------
        if (trim((string) $c->title) === '') {
            $errors[] = '标题不能为空';
        }
        if (trim((string) $c->slug) === '') {
            $errors[] = 'URL 段（slug）不能为空';
        } elseif (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $c->slug)) {
            $errors[] = 'URL 段（slug）需为小写字母、数字与连字符，且语义可读';
        }
        if (mb_strlen((string) $c->title) > 60) {
            $warnings[] = '标题超过 60 字，可能被搜索结果截断';
        }
        if (trim((string) $c->summary) === '') {
            $warnings[] = '缺少摘要，列表页与 meta description 将退化为正文首段';
        }

        // ---------- 5. 文本合规：极限词 / 对标表述 / 冲突口径 ----------
        $text = $this->flattenText($c);

        foreach (self::BANNED_WORDS as $w) {
            if (mb_strpos($text, $w) !== false) {
                $errors[] = sprintf('命中极限词「%s」', $w);
            }
        }

        foreach (self::BANNED_COMPARISON as $b) {
            if (mb_strpos($text, $b) !== false) {
                $errors[] = sprintf('命中他方品牌「%s」：未经授权不得作比较宣传', $b);
            }
        }

        if (preg_match('/[\x{4e00}-\x{9fa5}A-Za-z]{2,}同款/u', $text)) {
            $errors[] = '命中「XX 同款」式对标表述，属未经授权使用他人商标';
        }

        foreach (self::CONFLICT_VALUES as $bad => $hint) {
            if (mb_strpos($text, $bad) !== false) {
                $errors[] = sprintf('事实口径冲突：出现「%s」，%s', $bad, $hint);
            }
        }

        // ---------- 6. 事实引用校验 ----------
        $refs = $c->fact_refs ?: [];
        if ($refs) {
            $known = Fact::whereIn('key', $refs)->pluck('key')->toArray();
            foreach (array_diff($refs, $known) as $missing) {
                $errors[] = sprintf('引用了不存在的事实项：%s', $missing);
            }
        }

        // ---------- 7. 敏感信息占位符 ----------
        if (preg_match('/【待[^】]*】|TODO|TBD|XXX/i', $text)) {
            $errors[] = '正文中出现占位符（如【待提供】），不允许发布';
        }

        // ---------- 8. 警告项：NAP 一致性 ----------
        $phone = (string) (Fact::where('key', 'FACT-COMPANY-013')->value('value') ?? '');
        if ($phone && preg_match_all('/(?:400[-\s]?\d{3}[-\s]?\d{4}|1[3-9]\d{9})/', $text, $m)) {
            foreach (array_unique($m[0]) as $found) {
                if (str_replace([' ', '-'], '', $found) !== str_replace([' ', '-'], '', $phone)) {
                    $warnings[] = sprintf('出现其他电话「%s」，当前官方口径为 %s，请确认是否为业务号', $found, $phone);
                }
            }
        }

        return [
            'passed'   => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /** 把参与校验的所有文本拼成一段 */
    protected function flattenText(Content $c): string
    {
        $parts = [
            $c->title,
            $c->summary,
            $c->body,
            $c->geo_conclusion,
            $c->geo_explanation,
            $c->geo_boundary,
            $c->source_note,
        ];

        foreach ($c->evidenceList() as $e) {
            $parts[] = ($e['label'] ?? '') . ' ' . ($e['value'] ?? '') . ' ' . ($e['source'] ?? '');
        }
        foreach ($c->faqList() as $f) {
            $parts[] = ($f['q'] ?? '') . ' ' . ($f['a'] ?? '');
        }
        foreach ($c->keyFactList() as $k) {
            $parts[] = ($k['key'] ?? '') . ' ' . ($k['value'] ?? '');
        }

        return implode("\n", array_filter(array_map('strval', $parts)));
    }

    /**
     * 发布前调用；不通过则抛异常。
     * 后台控制器与 GEOFlow 接口都走这里。
     */
    public function assertPassable(Content $c): void
    {
        $result = $this->check($c);
        if (! $result['passed']) {
            throw new \RuntimeException(
                "内容未通过 GEO 门禁，共 " . count($result['errors']) . " 项：\n- "
                . implode("\n- ", $result['errors'])
            );
        }
    }
}
