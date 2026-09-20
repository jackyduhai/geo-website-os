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
 * 这些是演示站点、llms.txt、JSON-LD 三处输出的共同口径来源，
 * 任何页面不得写出与本表冲突的数字。
 */
class FactSeeder extends Seeder
{
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
                '配方定制研发 + OEM/ODM 代工 + 成品供应', 'business',
                '企业资料（演示）', ''],

            ['FACT-BIZ-002', '核心客户群',
                '装备制造 / 建筑工程 / 汽车零部件 / 工业品牌方 / 渠道经销商', 'business',
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
            Fact::updateOrCreate(
                ['key' => $key],
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
                ['key' => $g[0]],
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

        $this->command->info(sprintf(
            '事实库：已公开 %d 项，待补充 %d 项',
            Fact::where('is_public', true)->count(),
            Fact::where('is_public', false)->count()
        ));
    }
}
