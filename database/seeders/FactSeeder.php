<?php

namespace Database\Seeders;

use App\Models\Fact;
use Illuminate\Database\Seeder;

/**
 * 事实库种子数据（Demo / Example 演示数据）
 *
 * 仅由 DemoSeeder 在演示 / 开发 / 测试环境显式装载，geo:install 不调用本 Seeder。
 * 以下为虚构的「示例制造有限公司」演示事实，用于展示事实库的统一口径、
 * 来源标注、复核周期与「公开 / 待补充」分级能力；不对应任何真实企业。
 *
 * ── SoT 契约（RC-9 D-02 架构决策锁定）────────────────────────
 * `facts` 表是**业务事实（Business Fact）的唯一 Canonical SoT**。
 * `geo.json`、`llms.txt` 等factual AI 输出必须经`Fact::publicRows($locale)`
 * 取得事实，**不得自行读取另一套事实数据源**。
 *
 * 背景：历史实现里同一批事实存在两份 ——
 *   ·本表（facts，仅 zh-CN）
 *   · `organization.metadata['company']`（38 字段，含 `_en` 变体）
 * 二者覆盖范围不同且 locale 覆盖不同，导致 `en/geo.json` 的 facts 为空
 * 而 `en/llms.txt` 却能输出英文事实 —— AI 同时消费两端会得到矛盾答案。
 *
 * 处置（分层 SoT，见 docs/audit/RC/D-02-Architecture-Decision-Lock.md）：
 *   · 业务数值类事实  → SoT = 本表，AI 出口统一走 `Fact::publicRows()`
 *   · 实体属性类字段  → SoT = organization.metadata（legalName / address /
 *     areaServed / knowsAbout 等），`SchemaBuilder` **不直接读 facts**
 *     （遵守 SchemaBuilder.php:34 已冻结的数据源边界）
 *   · 跨出口一致性   → 由 tests/Feature/Geo/GeoFactConsistencyTest.php 契约保证
 *
 * 因此本 Seeder 必须**同时写入 zh-CN 与 en 两行**，让英文站的事实出口
 * 与中文站口径一致，而不是让英文站空着。
 *
 * ── en 行不做机械全量导入 ────────────────────────────────────
 * metadata 有 38 个字段，facts 只是其中一个语义子集。本 Seeder 显式列出
 * 需要翻译的事实（见 `$rowsEn`），其余字段不进事实库。
 * `is_public = 0` 的待补充项**不补 en 行** —— 它们本就没有值，
 * 门禁下不出现在任何出口，补英文占位只会制造「有值但无依据」的数据污染。
 */
class FactSeeder extends Seeder
{
    /**
     * 演示数据统一为默认语言（20G-3）。
     *
     * facts 是行级翻译模型，同一 key 可有 zh / en 多行；种子必须显式声明
     * 写入哪种语言，否则会随数据库返回顺序覆盖到任意语言行上。
     */
    private const SEED_LOCALE = 'zh-CN';

    private const SEED_LOCALE_EN = 'en';

    public function run(): void
    {
        $reviewed = '2026-09-14';
        $due      = '2027-03-14';   // 半年后复核

        $rows = [
            // ---------- 企业域 ----------
            ['FACT-COMPANY-001', '公司全称', '示例制造有限公司', 'company',
                '营业执照（演示）', '保持'],

            ['FACT-COMPANY-002', '品牌名', '示例制造 / EXAMPLEMFG', 'company',
                '品牌使用规范', '正文首次出现用全称，其后用「示例制造」'],

            ['FACT-COMPANY-003', '成立时间', '2014 年 6 月', 'company',
                '企业资料（演示）', ''],

            ['FACT-COMPANY-004', '正式投产', '2015 年 9 月，生产基地全面投产', 'company',
                '企业资料（演示）', '与成立时间分列，避免混淆'],

            ['FACT-COMPANY-006', '厂区占地面积', '约 12,000 平方米', 'company',
                '企业资料（演示）', ''],

            ['FACT-COMPANY-007', '年产能', '约 1,200 吨成品', 'company',
                '企业资料（演示）', ''],

            ['FACT-COMPANY-008', '总投资', '约 800 万元', 'company',
                '企业资料（演示）', ''],

            ['FACT-COMPANY-009', '生产车间',
                '原料处理车间 / 配料混合车间 / 成型加工车间 / 品控包装车间', 'company',
                '企业资料（演示）', ''],

            ['FACT-COMPANY-010', '销售大区',
                '华东 / 华北 / 华南 / 华中 / 西南 / 西北 / 东北（七大区）', 'company',
                '企业资料（演示）', '是「全国供货能力」的证据'],

            ['FACT-COMPANY-011', '核心产品线',
                '工业涂料 / 结构胶粘剂 / 功能性助剂', 'company',
                '企业资料（演示）', '与官网产品栏目对齐'],

            ['FACT-COMPANY-012', '技术积累', '研发团队拥有十年以上工业材料配方与量产经验', 'company',
                '企业资料（演示）', ''],

            // ---------- 产品域 ----------
            ['FACT-PRODUCT-001', '产品体系',
                '工业涂料 / 结构胶粘剂 / 功能性助剂（三大产品线）', 'product',
                '内部产品资料（演示）', '官网栏目与三大产品线对齐'],

            ['FACT-PRODUCT-002', '质量体系', '按标准化质量管理体系组织生产，关键批次留样可追溯', 'product',
                '企业资料（演示）', '具体认证证书编号见待补充项'],

            // ---------- 经营域 ----------
            ['FACT-BIZ-001', '业务模式',
                '产品研发生产 + 定制化解决方案 + 成品供应', 'business',
                '企业资料（演示）', ''],

            ['FACT-BIZ-002', '核心客户群',
                '工业制造 / 建筑工程 / 汽车零部件 / 工业品牌方 / 渠道伙伴', 'business',
                '内部产品资料（演示）', ''],

            ['FACT-BIZ-003', '服务区域', '覆盖全国主要工业区域（七大区）', 'business',
                '企业资料（演示）', ''],

            // ---------- 服务域（谨慎项，不带数字） ----------
            ['FACT-SVC-001', '服务口径', '为工业客户提供从打样、试样到批量供货的技术支持', 'service',
                '企业资料（演示）', '具体服务客户数量未经核定前不带数字'],
        ];

        $sort = 0;
        foreach ($rows as $r) {
            [$key, $label, $value, $group, $source, $note] = array_pad($r, 6, '');
            /**
             * 匹配条件必须带 `locale`（20G-3）。
             *
             * facts 已纳入行级翻译，唯一约束是 UNIQUE(site_id, key, locale)，
             * 同一个 key 会有 zh / en 两行。若只按 key 查找，
             * Eloquent 会取「第一条」—— 可能是 en 行，于是中文种子数据会
             * 覆盖英文翻译。这里显式锁定 `zh-CN`（演示数据全部为中文）。
             */
            Fact::updateOrCreate(
                ['key' => $key, 'locale' => self::SEED_LOCALE],
                [
                    'label'       => $label,
                    'value'       => $value,
                    'group'       => $group,
                    'source'      => $source,
                    'owner'       => '顾问',
                    'reviewed_at' => $reviewed,
                    'review_due'  => $due,
                    'is_public'   => true,
                    'sort'        => $sort += 10,
                ]
            );
        }

        // 缺口项单独标记为不公开，后台可见但不参与页面输出
        $gaps = [
            ['FACT-COMPANY-005', '注册与生产地址', '【待企业提供】', 'company'],
            ['FACT-COMPANY-013', '官方电话', '【待企业提供】', 'company'],
            ['FACT-TRUST-001', '生产资质 / 认证证书编号', '【待企业提供】', 'trust'],
            ['FACT-TRUST-002', '执行标准号', '【待企业提供】', 'trust'],
            ['FACT-BIZ-004', '起订量 MOQ', '【待企业提供】', 'business'],
            ['FACT-BIZ-005', '交付周期', '【待企业提供】', 'business'],
        ];
        foreach ($gaps as $g) {
            Fact::updateOrCreate(
                ['key' => $g[0], 'locale' => self::SEED_LOCALE],
                [
                    'label'       => $g[1],
                    'value'       => $g[2],
                    'group'       => $g[3],
                    'source'      => '企业未提供',
                    'owner'       => '企业',
                    'reviewed_at' => null,
                    'review_due'  => null,
                    'is_public'   => false,
                    'sort'        => $sort += 10,
                ]
            );
        }

        // ------------------------------------------------------------------
        // 英文行（RC-9 D-02）：让en 站的事实出口与 zh 站口径一致。
        //
        // 只为**有依据**的事实写en 行：
        //   · 值来自organization.metadata['company'][*_en]的，语义对应后照搬；
        //   · metadata 无对应英文表述的（如生产车间 / 销售大区），按中文值
        //     做语义等价的英文表述，保证英文 AI 也能回答同类问题；
        //   · 待补充项（is_public = 0）不写 —— 无值可译，门禁下本就不输出。
        //
        // 匹配条件同样带 locale（20G-3）；`translation_group` 由 key 确定性派生
        // （`Fact::groupForKey()` → `TG-FACT-{key}`），所以 zh / en 两行会自动
        // 归入同一翻译组，无需手工指定。
        // ------------------------------------------------------------------
        $rowsEn = [
            ['FACT-COMPANY-001', 'Company Name', 'Example Manufacturing Co., Ltd.', 'company',
                'Business license (demo)', 'Use the full legal name on first mention'],

            ['FACT-COMPANY-002', 'Brand Name', 'Example Manufacturing / EXAMPLEMFG', 'company',
                'Brand usage guidelines', 'First mention uses the full name, short name afterwards'],

            ['FACT-COMPANY-003', 'Founded', 'June 2014', 'company',
                'Company profile (demo)', ''],

            ['FACT-COMPANY-004', 'Production Start', 'September 2015, full plant commissioning', 'company',
                'Company profile (demo)', 'Kept separate from the founding date to avoid confusion'],

            ['FACT-COMPANY-006', 'Facility Area', 'About 12,000 m2', 'company',
                'Company profile (demo)', ''],

            ['FACT-COMPANY-007', 'Annual Capacity', 'About 1,200 tons of finished products', 'company',
                'Company profile (demo)', ''],

            ['FACT-COMPANY-008', 'Total Investment', 'About 8 million CNY', 'company',
                'Company profile (demo)', ''],

            ['FACT-COMPANY-009', 'Production Workshops',
                'Raw material processing / mixing compounding / forming / quality control and packaging',
                'company', 'Company profile (demo)', ''],

            ['FACT-COMPANY-010', 'Sales Regions',
                'East China / North China / South China / Central China / Southwest / Northwest / Northeast (seven regions)',
                'company', 'Company profile (demo)', 'Evidence for nationwide supply capability'],

            ['FACT-COMPANY-011', 'Core Product Lines',
                'Industrial coatings / structural adhesives / functional additives', 'company',
                'Company profile (demo)', 'Aligned with the product sections of the site'],

            ['FACT-COMPANY-012', 'R&D Experience',
                'The R&D team has over ten years of experience in industrial material formulation and mass production',
                'company', 'Company profile (demo)', ''],

            ['FACT-PRODUCT-001', 'Product System',
                'Industrial coatings / structural adhesives / functional additives (three product lines)',
                'product', 'Internal product documentation (demo)',
                'Aligned with the product sections and the three product lines'],

            ['FACT-PRODUCT-002', 'Quality System',
                'Production follows a standardised quality management system; critical batches are retained for traceability',
                'product', 'Company profile (demo)', 'Certificate numbers are listed as a pending item'],

            ['FACT-BIZ-001', 'Business Model',
                'Product R&D and manufacturing / customised solutions / finished product supply',
                'business', 'Company profile (demo)', ''],

            ['FACT-BIZ-002', 'Core Customer Groups',
                'Industrial manufacturers / construction and engineering / automotive parts / industrial brand owners / distribution partners',
                'business', 'Internal product documentation (demo)', ''],

            ['FACT-BIZ-003', 'Service Regions',
                'Covering the major industrial regions nationwide (seven regions)',
                'business', 'Company profile (demo)', ''],

            ['FACT-SVC-001', 'Service Scope',
                'Technical support for industrial customers from sampling and trial batches to volume supply',
                'service', 'Company profile (demo)',
                'No customer counts are stated until they are verified'],
        ];

        foreach ($rowsEn as $r) {
            [$key, $label, $value, $group, $source, $note] = array_pad($r, 6, '');
            Fact::updateOrCreate(
                ['key' => $key, 'locale' => self::SEED_LOCALE_EN],
                [
                    'label'       => $label,
                    'value'       => $value,
                    'group'       => $group,
                    'source'      => $source,
                    'owner'       => 'Consultant',
                    'reviewed_at' => $reviewed,
                    'review_due'  => $due,
                    'is_public'   => true,
                    'sort'        => $sort,      // 与 zh 行同序，两语言排序稳定
                ]
            );
        }

        $this->command->info(sprintf(
            '事实库：已公开 %d 项，待补充 %d 项（zh-CN %d · en %d）',
            Fact::where('is_public', true)->count(),
            Fact::where('is_public', false)->count(),
            Fact::where('locale', self::SEED_LOCALE)->count(),
            Fact::where('locale', self::SEED_LOCALE_EN)->count()
        ));
    }
}