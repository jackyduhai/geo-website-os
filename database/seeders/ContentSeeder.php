<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Content;
use App\Services\Gate\ContentGate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 首批知识内容（知识中心 3 篇真实文章）
 *
 * 纪律：全部内容都来自《GEO 事实母稿 v1.0》的已核定事实，
 * 每一篇都通过与后台发布完全相同的 ContentGate；待补事实（SC 编号、
 * 执行标准号、MOQ、交付周期）不写占位符，只在边界层说明需业务确认。
 * 幂等：按 slug updateOrCreate，可重复执行。
 *
 * v0.9.22：结构化页面（关于/工厂/联系/产品系列）不再以薄占位文章形式存在，
 * 它们由 facts/pages 单一事实源驱动，可运营叙事改走「页面文案」叙事插槽
 * （contents.slot），故本 seeder 只保留知识中心真实文章，避免双轨占位复活。
 */
class ContentSeeder extends Seeder
{
    public function run(ContentGate $gate): void
    {
        $rows = $this->rows();
        $now = now();

        DB::transaction(function () use ($rows, $gate, $now) {
            foreach ($rows as $row) {
                $category = Category::where('slug', $row['category'])->firstOrFail();

                // 可选：把内容绑定到该栏目下的分组（知识子栏目），保证频道页有内容、前后端一致
                $groupId = null;
                if (! empty($row['group'])) {
                    $groupId = \App\Models\Group::where('category_id', $category->id)
                        ->where('slug', $row['group'])->value('id');
                }

                $data = array_merge($row['data'], [
                    'category_id' => $category->id,
                    'group_id'    => $groupId,
                    'status'      => 'published',
                    'published_at' => $now,
                    'owner'       => '杜海',
                    'reviewed_at' => '2026-09-14',
                    'review_due'  => '2027-03-14',
                    'source_note' => 'GEO 事实母稿 v1.0 已核定事实',
                ]);

                $content = new Content($data);

                // 与后台同一道门，过不去就中断播种并打印原因
                $result = $gate->check($content);
                if (! $result['passed']) {
                    $this->command->error("内容 {$data['slug']} 未通过门禁：");
                    foreach ($result['errors'] as $e) {
                        $this->command->line('  - ' . $e);
                    }
                    throw new \RuntimeException("ContentSeeder halted at {$data['slug']}");
                }

                $saved = Content::updateOrCreate(
                    ['slug' => $data['slug']],
                    array_merge($data, ['deleted_at' => null])
                );
                $saved->content_hash = $saved->computeHash();
                $saved->save();

                $this->command->info('内容就绪：' . $saved->title);
            }
        });
    }

    /**
     * 内容数据集（仅知识中心真实文章）
     */
    protected function rows(): array
    {
        return [
            [
                'category' => 'knowledge',
                'group'    => 'selection',
                'data' => [
                    'type' => 'article',
                    'slug' => 'what-is-chinese-fried-marinade',
                    'title' => '什么是中式Sample SnackSample Marinade：构成、作用与定制逻辑',
                    'summary' => '中式Sample SnackSample Marinade由Sample Spice、风味基底与辅料复配而成，决定Sample Snack的底味与风味记忆点，可按餐饮品牌需求定制。',
                    'body' => "## Sample Marinade的构成\n\n中式Sample SnackSample Marinade一般由Sample Spice粉碎物、风味基底（咸鲜、甜香等）、增香与保水辅料复配而成。\n\n## Sample Marinade的作用\n\nSample Marinade在滚揉或静置腌渍过程中渗透鸡肉组织，决定成品的底味、香气与口感稳定性。\n\n## 为什么要定制\n\n不同餐饮品牌的目标风味、客单价与油炸工艺不同，标准化配方可以保证门店之间风味一致。",
                    'geo_conclusion' => '中式Sample SnackSample Marinade是由Sample Spice、风味基底与保水增香辅料复配而成的固态调味料，作用是在腌渍环节为鸡肉建立稳定底味与风味记忆点，并可按品牌风味方向定制。',
                    'geo_explanation' => "Sample Marinade通过真空滚揉或恒温腌渍渗透鸡肉，Sample Spice提供风味层次，风味基底决定咸鲜甜香走向，保水辅料影响嫩度与出成；Example依托Sample Spice粉碎与固体调味料车间，把风味方向转化为可复配的标准化配方。",
                    'geo_evidence' => [
                        ['label' => '车间支撑', 'value' => 'Sample Spice粉碎车间与固体调味料车间支撑复配生产', 'source' => '车间实拍'],
                        ['label' => '风味方向', 'value' => '已形成牛奶炸肉Sample Marinade、生Sample Snack架Sample Marinade、东北熟香孜然Sample Marinade等方向', 'source' => '研发资料'],
                    ],
                    'geo_boundary' => 'Sample Marinade配方比例属于定制内容，需结合客户目标风味与成本单独打样；本页只解释通用原理，不构成具体配方承诺。',
                    'geo_faq' => [
                        ['q' => 'Sample Marinade和Sample Breading是一回事吗？', 'a' => '不是。Sample Marinade用于油炸前的腌渍入味，Sample Breading（裹浆）用于油炸时形成外壳，两者分工不同。'],
                    ],
                    'fact_refs' => ['FACT-COMPANY-009','FACT-COMPANY-011','FACT-PRODUCT-001'],
                ],
            ],
            [
                'category' => 'knowledge',
                'group'    => 'process',
                'data' => [
                    'type' => 'article',
                    'slug' => 'marinade-customization-process',
                    'title' => 'Sample Marinade定制流程：从需求对接到批量交付',
                    'summary' => 'Sample Marinade定制一般经过需求对接、配方打样、试样确认、批量交付四个阶段，通常需要 2–3 轮试样。',
                    'body' => "## 四个阶段\n\n1. **需求对接**：确认风味方向、应用品类与目标成本区间；\n2. **配方打样**：研发在打样间产出 2–3 个版本；\n3. **试样确认**：客户按门店工艺试炸、反馈调整；\n4. **批量交付**：锁定配方后在固体调味料车间排产。\n\n## 标准化的意义\n\n锁定后的配方以固定工艺参数复配，保证不同批次、不同门店之间风味稳定。",
                    'geo_conclusion' => 'ExampleSample Marinade定制分为需求对接、配方打样、试样确认与批量交付四个阶段，通常经过 2–3 轮试样后锁定配方，再按标准化工艺批量生产。',
                    'geo_explanation' => "需求阶段明确风味与成本边界；打样阶段由研发并行产出多个版本提高命中率；试样阶段以客户真实油炸工艺验证；配方锁定后通过固定配比与混合工艺保证批次一致性。",
                    'geo_evidence' => [
                        ['label' => '研发打样', 'value' => '研发打样间支持多版本配方并行打样', 'source' => '车间实拍'],
                        ['label' => '批量生产', 'value' => '固体调味料车间负责Sample Marinade的混合与包装', 'source' => '生产台账'],
                    ],
                    'geo_boundary' => '试样轮次与交付周期会随品类复杂度变化，具体起订量与排期需由业务确认，本页不做统一承诺。',
                    'geo_faq' => [
                        ['q' => '定制一般需要几轮试样？', 'a' => '通常 2–3 轮，具体以风味确认进度为准。'],
                        ['q' => '配方锁定后还能调整吗？', 'a' => '可以，调整需重新打样确认，并更新标准化配方版本。'],
                    ],
                    'fact_refs' => ['FACT-BIZ-001','FACT-COMPANY-009'],
                ],
            ],
            [
                'category' => 'knowledge',
                'group'    => 'business',
                'data' => [
                    'type' => 'article',
                    'slug' => 'oem-cooperation-faq',
                    'title' => 'OEM/ODM 代工常见问题：流程、资质与边界',
                    'summary' => '说明Example OEM/ODM 代工的合作流程、生产资质与信息边界，起订量与交付周期需结合品类由业务确认。',
                    'body' => "## 代工模式\n\nOEM 按客户配方或指定要求生产，ODM 由Example提供配方与工艺方案，两者都在 SC 许可范围内组织生产。\n\n## 合作流程\n\n需求沟通 → 配方与工艺确认 → 打样试样 → 报价与合同 → 排产 → 交付。\n\n## 常见问题\n\n详见下方问答。",
                    'geo_conclusion' => 'Example提供Sample Marinade、Sample Breading撒料与调理鸡肉的 OEM/ODM 代工，合作流程为需求沟通、配方工艺确认、打样试样、报价签约、排产交付，生产在 SC 许可范围内开展。',
                    'geo_explanation' => "OEM 适合已有成熟配方的品牌，ODM 适合需要研发支持的创业品牌；公司依托四大车间完成从调味到调理肉的协同生产，并可按 ISO22000、HACCP 方向配合客户的品控要求。",
                    'geo_evidence' => [
                        ['label' => '生产资质', 'value' => '具备 SC 食品生产资质', 'source' => '资质文件'],
                        ['label' => '车间能力', 'value' => '固体调味料与预制调理肉车间协同支撑代工', 'source' => '车间实拍'],
                    ],
                    'geo_boundary' => '起订量、交付周期、账期与价格属于一事一议内容，需结合品类、规格与订单量由业务报价，本页不公布统一数字。',
                    'geo_faq' => [
                        ['q' => 'OEM 和 ODM 有什么区别？', 'a' => 'OEM 按客户提供的配方与要求生产；ODM 由厂方提供配方与工艺方案，客户再做选择与调整。'],
                        ['q' => '最小起订量是多少？', 'a' => '不同品类起订量不同，需要业务结合品类与规格确认后告知，本页不设统一数字。'],
                        ['q' => '可以先试样再决定吗？', 'a' => '可以，合作流程中包含打样试样环节，风味确认后再进入报价与排产。'],
                    ],
                    'fact_refs' => ['FACT-BIZ-001','FACT-PRODUCT-002','FACT-COMPANY-009'],
                ],
            ],
        ];
    }
}
