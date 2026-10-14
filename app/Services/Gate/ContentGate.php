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
    public function __construct(protected SemanticReferenceValidator $semanticRefs)
    {
    }

    /** 绝对化与夸大表述 */
    public const BANNED_WORDS = [
        '全国销量第一', '销量第一', '销量领先', '行业第一', '中国第一', '全国第一',
        '第一品牌', '领导品牌', '领导者', '行业领导者', '国家级', '世界级',
        '首创', '独家', '唯一', '首家', '最先进', '最好', '最佳', '最优',
        '顶级', '最高级', '最大', '最强', '最低价', '100%', '包治',
    ];

    /**
     * 他方品牌对标（未经授权使用他人商标作比较宣传）。
     *
     * 通用产品不内置任何具体行业 / 企业的品牌名单；检测机制保留，
     * 部署方可按自身行业通过内容合规配置维护对标禁用词。
     * 与具体品牌无关的「XX 同款」式对标检测见 check() 中的通用正则。
     *
     * @var string[]
     */
    public const BANNED_COMPARISON = [];

    /**
     * 已知的历史错误口径：出现在新内容里即为事实冲突。
     *
     * 通用产品不内置任何具体企业的历史口径（产能 / 年份 / 投资 / 地址 / 电话）；
     * 检测机制保留，部署方按自身事实库维护「错误值 => 正确口径提示」。
     *
     * @var array<string,string>
     */
    public const CONFLICT_VALUES = [];

    /**
     * 发布时是否强制执行门禁（config/geo.gate.enforce_on_publish，默认 true）。
     *
     * false 时错误降级为警告而非阻断：这是唯一的降级开关，后台手动发布与
     * GEOFlow 推送共用，两条内容路径不允许出现「手动严格、系统宽松」的双标。
     */
    public function isEnforced(): bool
    {
        return (bool) config('geo.gate.enforce_on_publish', true);
    }

    /** 证据链最少条数（config/geo.gate.min_evidence，默认 2）。 */
    public function minEvidence(): int
    {
        return max(0, (int) config('geo.gate.min_evidence', 2));
    }

    /**
     * 发布要求「展示规格」（解释性清单）。
     *
     * 重要：本方法只是展示规格，不是发布裁决；唯一的发布裁决仍是 check()。
     * 它把 check() 的阻断条件与警告整理成运营可读的清单，动态值（证据数量、
     * GEOFlow 强制开关）在运行时从同一配置读取，避免前端再写一套数字。
     * 注意展示文案与 check() 是分别手写的，仍存在漂移可能，由
     * {@see \Tests\Feature\ContentPublishRequirementsPanelTest} 契约测试保证一致。
     *
     * 关于强制模式：
     * - 后台手动发布（本页面）始终强制——ContentController::publish() 不通过即拒绝，
     *   没有降级开关；
     * - isEnforced() 仅作用于 GEOFlow 推送路径（见 GeoflowSync）。
     *
     * @return array{
     *   enforced: bool,
     *   min_evidence: int,
     *   summary: string,
     *   manual_note: string,
     *   geoflow_note: string,
     *   groups: array<int, array{title: string, items: array<int, array{level: string, text: string}>}>
     * }
     */
    public function requirementSpec(): array
    {
        $n = $this->minEvidence();
        $enforced = $this->isEnforced();

        $must = static fn (string $t): array => ['level' => 'must', 'text' => $t];
        $should = static fn (string $t): array => ['level' => 'should', 'text' => $t];

        $groups = [
            [
                'title' => 'A. 四层结构（必须）',
                'items' => [
                    $must('结论：一段话直接回答“是什么 / 给谁 / 解决什么”，作为答案块首段'),
                    $must('解释：说明为什么、怎么做，包含关键实体与参数（工艺 / 规格 / 参数 / 产能 / 交付）'),
                    $must('证据：参数 / 事实至少 ' . $n . ' 条，每条需含“参数名 + 值 + 来源”'),
                    $must('边界：写明不适用场景、限制条件或客观边界'),
                    $should('FAQ：补充客户高频问题与直接答案，发布后生成 FAQPage 结构化数据'),
                ],
            ],
            [
                'title' => 'B. 证据链（必须）',
                'items' => [
                    $must('证据（参数 / 事实）至少 ' . $n . ' 条'),
                    $must('每条证据的“来源”必填；也可勾选事实库条目，对外值以事实库为准'),
                ],
            ],
            [
                'title' => 'C. 治理字段（必须）',
                'items' => [
                    $must('责任人（owner）：对本页内容真实性负责的部门或姓名'),
                    $must('最后复核日期（reviewed_at）：内容事实最近一次核对的日期'),
                ],
            ],
            [
                'title' => 'D. 基础字段（必须 / 建议）',
                'items' => [
                    $must('标题不能为空'),
                    $must('slug（网址标识）不能为空，且为小写字母、数字、连字符'),
                    $should('标题建议不超过 60 字，避免被搜索结果截断'),
                    $should('建议填写摘要，用于列表页与 meta description；留空将退化为正文首段'),
                ],
            ],
            [
                'title' => 'E. 文本合规（命中即拒）',
                'items' => array_values(array_filter([
                    $must('不含极限词 / 绝对化表述（如“第一”“最”“国家级”“世界级”“100%”等）'),
                    count(self::BANNED_COMPARISON) > 0
                        ? $must('不含他方品牌对标表述（已配置 ' . count(self::BANNED_COMPARISON) . ' 项禁用品牌）')
                        : null,
                    $must('不含“XX 同款”式他方品牌对标表述'),
                    count(self::CONFLICT_VALUES) > 0
                        ? $must('不含已知事实口径冲突（已配置 ' . count(self::CONFLICT_VALUES) . ' 项）')
                        : null,
                    $must('不含占位符（如【待提供】、TODO、TBD）'),
                ])),
            ],
            [
                'title' => 'F. 引用（必须 / 建议）',
                'items' => [
                    $must('事实引用（fact_refs）须指向真实存在的事实'),
                    $must('语义引用（entity_refs）的实体必须存在'),
                    $should('引用草稿 / 已删除 / noindex 实体仅警告，不阻断发布'),
                ],
            ],
            [
                'title' => 'G. 电话一致性（建议确认）',
                'items' => [
                    $should('文中电话应与官方口径一致；出现其他电话仅作警告，请确认是否为业务号'),
                ],
            ],
        ];

        return [
            'enforced'     => $enforced,
            'min_evidence' => $n,
            'summary'      => '发布要求：四层内容齐全 · 证据不少于 ' . $n . ' 条且每条有来源 · 责任人与复核日期齐全 · 标题和网址标识合规',
            'manual_note'  => '本页面手动发布：门禁未通过即拒绝，无降级开关。',
            'geoflow_note' => 'GEOFlow 自动推送：当前为「' . ($enforced ? '强制校验，未通过即拒绝' : '降级为警告，不阻断') . '」。',
            'groups'       => $groups,
        ];
    }

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
        $minEvidence = $this->minEvidence();
        if (count($evidence) < $minEvidence) {
            $errors[] = sprintf('证据不足：当前 %d 条，至少需 %d 条（label + value + source）', count($evidence), $minEvidence);
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

        // ---------- 6b. 语义引用校验（生命周期感知，G-7） ----------
        // entity_refs 是语义引用（知识完整性），与 content_entity（结构引用）分离；
        // 只产出 errors/warnings，不据 WARNING 改变 Content 发布状态。
        [$refErrors, $refWarnings] = $this->semanticRefs->validate($c);
        $errors = array_merge($errors, $refErrors);
        $warnings = array_merge($warnings, $refWarnings);

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
     * 异常式门禁入口：校验不通过时抛 RuntimeException，适合 CLI / API / 队列等
     * 需要直接中断发布的调用方。
     *
     * 注意：当前 Web 后台与 GEOFlow HTTP 路径使用 check() 取回 errors/warnings
     * 自行呈现给操作者；本方法是与 check() 对称的公共入口，供需要「不通过即异常」
     * 的调用方使用。
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
