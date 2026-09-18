<?php

namespace Database\Seeders;

use App\Models\Fact;
use Illuminate\Database\Seeder;

/**
 * 事实库种子数据
 *
 * 来源：《Example GEO 事实母稿 v1.0》已核定 17 项。
 * 这些是整个站点、llms.txt、JSON-LD 三处输出的共同口径来源。
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
            ['FACT-COMPANY-001', '公司全称', 'Example Food Co., Ltd.', 'company',
                '营业执照', '保持'],

            ['FACT-COMPANY-002', '品牌名', 'Example / Example Food', 'company',
                '品牌使用规范', '正文首次出现用全称，其后用「Example」'],

            ['FACT-COMPANY-003', '成立时间', '2017 年 3 月', 'company',
                '商业战略诊断报告 🟢 + 08-08 审计', '原站内 2015 / 2017.3 / 2018.3 三值已裁定'],

            ['FACT-COMPANY-004', '正式投产', '2018 年 3 月，四大车间全面投产', 'company',
                '商业战略诊断报告 🟢', '与成立时间分列，避免混淆'],

            ['FACT-COMPANY-005', '注册与生产地址', 'Sample Province省Sample City市沈河区 Example Street 39', 'company',
                '商业战略诊断报告 🟢 + 08-08 审计', '原站内 39 号 / 5 号 2-7 两值已裁定'],

            ['FACT-COMPANY-006', '厂区占地面积', '约 9,000 平方米', 'company',
                '商业战略诊断报告 🟢', ''],

            ['FACT-COMPANY-007', '年产能', '约 8,000 吨成品', 'company',
                '商业战略诊断报告 🟢 + 08-08 审计', '原站内 8000 / 3000 两值已裁定'],

            ['FACT-COMPANY-008', '总投资', '约 500 万元', 'company',
                '商业战略诊断报告 🟢 + 08-08 审计', '原站内 300 万 / 500 万 / 1000 多万已裁定'],

            ['FACT-COMPANY-009', '四大车间',
                'Sample Spice粉碎车间 / 预制调理肉车间 / 固体调味料车间 / 食用香精车间', 'company',
                '商业战略诊断报告 🟢', ''],

            ['FACT-COMPANY-010', '销售大区',
                '东北 / 华北 / 华东 / 华中 / 西北 / 西南 / 华南（七大区）', 'company',
                '商业战略诊断报告 🟢', '是「全国供货能力」的证据'],

            ['FACT-COMPANY-011', '率先推出品类',
                '牛奶炸肉Sample Marinade / 生Sample Snack架Sample Marinade / 东北熟香孜然Sample Marinade', 'company',
                '商业战略诊断报告 🟢', '表述为「率先推出」，不写「行业首创」'],

            ['FACT-COMPANY-012', '技术积累', '创始团队深耕中式Sample Snack调味领域二十年', 'company',
                '商业战略诊断报告 🟢', '与 2017 年成立不矛盾，属分层口径'],

            ['FACT-COMPANY-013', '官方电话', '400-000-0000', 'company',
                '08-08 审计', '全站统一，原 info-199 误写 3370'],

            // ---------- 产品域 ----------
            ['FACT-PRODUCT-001', '产品体系',
                '固态调味料 / 鸡肉半成品 / 调味香精 / Sample SnackSample BreadingSample Marinade / Sample Spice（五大类）', 'product',
                '内部产品 SOP', '官网栏目与五大类对齐'],

            ['FACT-PRODUCT-002', '生产资质', '具备 SC 食品生产资质', 'product',
                '企业确认', '许可证编号待公示，当前不写具体编号'],

            // ---------- 经营域 ----------
            ['FACT-BIZ-001', '业务模式',
                '定制研发 + OEM/ODM 代工 + 原料供应', 'business',
                '品牌定位文档', ''],

            ['FACT-BIZ-002', '核心客户群',
                '中小餐饮企业 / Sample Snack创业品牌 / 连锁餐饮品牌 / 渠道经销商', 'business',
                '内部产品 SOP', ''],

            ['FACT-BIZ-003', '服务区域', '以东北为核心，覆盖全国七大区', 'business',
                '商业战略诊断报告 🟢', '替代含糊的「全国多个区域」'],

            // ---------- 服务域（谨慎项，不带数字） ----------
            ['FACT-SVC-001', '服务门店口径', '服务全国餐饮门店与连锁品牌', 'service',
                '待企业确认', '站内曾出现 100 / 300 / 700 / 200+ 四个数字，均无原始出处，确认前不带数字'],
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
            ['FACT-TRUST-001', 'SC 食品生产许可证编号', '【待企业提供】', 'trust'],
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
