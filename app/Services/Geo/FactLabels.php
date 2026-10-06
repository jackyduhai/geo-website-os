<?php

namespace App\Services\Geo;

/**
 * 业务事实标签契约（RC-9 D-02 跨出口一致性）
 * ------------------------------------------------------------------
 * `geo.json` 与 `llms.txt` 是两个面向 AI 的出口。若它们对**同一条事实**
 * 使用不同的label，AI 无法把两个端点的同一条事实对齐 —— 等于又制造了一次
 * 口径分叉（与 RC-9 P0 同类的问题，只是发生在标签层而非取值层）。
 *
 * 因此本表是**唯一权威的标签来源**：
 *   · `llms.txt`（`LlmsBuilder`）从本表取 label；
 *   · `geo.json`（`GeoGraphBuilder`）也优先从本表取 label，
 *     仅当key 不在本表内时才回落到 `Fact.label`（运营在后台可编辑的原始标签）。
 *
 * 覆盖范围刻意**只含公开事实**。`is_public = 0` 的待补充项
 * （地址 / 官方电话 / MOQ / 交付周期 / 资质编号 / 执行标准号）不在本表，
 * 它们本就无值、门禁下不出现在任何出口，不应占位。
 *
 * 维护纪律：新增业务事实时，**必须**同步在
 * `database/seeders/FactSeeder.php` 与本表登记，否则跨出口一致性测试
 * （`tests/Feature/Geo/GeoFactConsistencyTest.php`）会失败并指出缺口。
 *
 * @see \docs\audit\RC\D-02-Architecture-Decision-Lock.md
 */
final class FactLabels
{
    /**
     * fact key → [locale => 权威 label]
     *
     * label 用各语言的展示名，不含冒号（llms.txt 以「- label: value」渲染，
     * 标签含冒号会破坏解析与 AI 对齐）。
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        // ---------- 企业域 ----------
        'FACT-COMPANY-001' => ['zh-CN' => '公司全称', 'en' => 'Company name'],
        'FACT-COMPANY-002' => ['zh-CN' => '品牌名', 'en' => 'Brand'],
        'FACT-COMPANY-003' => ['zh-CN' => '成立时间', 'en' => 'Founded'],
        'FACT-COMPANY-004' => ['zh-CN' => '正式投产', 'en' => 'Production start'],
        'FACT-COMPANY-006' => ['zh-CN' => '厂区面积', 'en' => 'Facility area'],
        'FACT-COMPANY-007' => ['zh-CN' => '年产能', 'en' => 'Annual capacity'],
        'FACT-COMPANY-008' => ['zh-CN' => '总投资', 'en' => 'Total investment'],
        'FACT-COMPANY-009' => ['zh-CN' => '生产车间', 'en' => 'Production workshops'],
        'FACT-COMPANY-010' => ['zh-CN' => '销售大区', 'en' => 'Sales regions'],
        'FACT-COMPANY-011' => ['zh-CN' => '核心产品线', 'en' => 'Core product lines'],
        'FACT-COMPANY-012' => ['zh-CN' => '技术积累', 'en' => 'R&D experience'],

        // ---------- 产品域 ----------
        'FACT-PRODUCT-001' => ['zh-CN' => '产品体系', 'en' => 'Product system'],
        'FACT-PRODUCT-002' => ['zh-CN' => '质量体系', 'en' => 'Quality system'],

        // ---------- 经营域 ----------
        'FACT-BIZ-001' => ['zh-CN' => '业务模式', 'en' => 'Business model'],
        'FACT-BIZ-002' => ['zh-CN' => '核心客户群', 'en' => 'Core customer groups'],
        'FACT-BIZ-003' => ['zh-CN' => '服务区域', 'en' => 'Service regions'],

        // ---------- 服务域 ----------
        'FACT-SVC-001' => ['zh-CN' => '服务口径', 'en' => 'Service scope'],
    ];

    /**
     * 权威标签；该 key 未登记时返回 null（由调用方决定回落策略）。
     */
    public static function for(string $key, string $locale): ?string
    {
        $label = self::LABELS[$key][$locale] ?? null;

        return ($label === null || trim($label) === '') ? null : $label;
    }

    public static function has(string $key): bool
    {
        return isset(self::LABELS[$key]);
    }

    /**
     * 供测试反射取用（避免测试里复制一份标签表导致漂移）。
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
