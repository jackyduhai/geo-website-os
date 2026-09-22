<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Content;
use App\Services\Gate\ContentGate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 首批知识内容（知识中心 3 篇示例文章，Demo / Example 演示数据）
 *
 * 仅由 DemoSeeder 在演示 / 开发 / 测试环境显式装载，geo:install 不调用本 Seeder。
 * 纪律：全部内容围绕虚构「示例制造有限公司」的演示事实展开，
 * 每一篇都通过与后台发布完全相同的 ContentGate；待补事实（资质编号、
 * 执行标准号、MOQ、交付周期）不写占位符，只在边界层说明需业务确认。
 * 幂等：按 slug updateOrCreate，可重复执行。
 *
 * v0.9.22：结构化页面（关于/工厂/联系/产品系列）不再以薄占位文章形式存在，
 * 它们由 facts/pages 单一事实源驱动，可运营叙事改走「页面文案」叙事插槽
 * （contents.slot），故本 seeder 只保留知识中心示例文章，避免双轨占位复活。
 *
 * P-STEP 18B：出厂 config/pages 的 about.profile 已改为行业中立骨架（Blank System
 * 不携带任何行业身份）；Demo 的完整制造企业简介改由本 seeder 写入 about.profile
 * 叙事插槽承载，仅在 db:seed / DemoSeeder 时装载，与出厂默认彻底分离。
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
                    'owner'       => 'Administrator',
                    'reviewed_at' => '2026-09-14',
                    'review_due'  => '2027-03-14',
                    'source_note' => '示例事实库（虚构演示数据）',
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

        // Demo 专属：完整制造企业简介经 about.profile 叙事插槽装载（出厂 config 保持行业中立）
        $this->seedProfileSlot();
    }

    /**
     * Demo「企业简介」叙事插槽（about.profile）。
     *
     * 仅由 DemoSeeder / db:seed 装载，geo:install 不调用；不经 ContentGate
     * （与后台「页面文案」保存路径一致）。幂等：按 slot updateOrCreate。
     */
    protected function seedProfileSlot(): void
    {
        $site = \App\Models\Site::where('slug', \App\Models\Site::DEFAULT_SLUG)->first();

        $body = implode("\n\n", [
            '示例制造由深耕工业材料领域十余年的团队创立，公司主体于 2014 年注册成立，2015 年主要产线投产。',
            '公司位于示例城市，厂区约 12,000 平方米，设有原料处理、配料混合、成型加工、品控包装四个生产车间，覆盖从原料预处理到成品包装的主要流程。',
            '主营业务覆盖工业防护涂料、工业胶粘剂与功能助剂三大产品线，为装备制造企业、工程承包商、工业品牌方与渠道经销商提供配方定制研发、OEM / ODM 代工与原料供应。',
            '服务网络覆盖东北、华北、华东、华中、西北、西南、华南等销售区域，面向全国供货。',
        ]);

        // 清理可能存在的历史软删片段，避免 slot 唯一索引冲突
        Content::withoutGlobalScope('not_slot')->withTrashed()
            ->where('slot', 'about.profile')->whereNotNull('deleted_at')->forceDelete();

        Content::withoutGlobalScope('not_slot')->updateOrCreate(
            ['slot' => 'about.profile'],
            [
                'site_id'     => $site?->id,
                'type'        => 'page',
                'category_id' => null,
                'group_id'    => null,
                'cover_id'    => null,
                'slug'        => \App\Support\Narrative::reservedSlug('about.profile'),
                'title'       => '企业简介 · 页头导语与正文',
                'summary'     => '工业材料的专业制造商，2014 年成立。',
                'body'        => $body,
                'status'      => 'published',
                'published_at' => now(),
                'owner'       => 'demo-seeder',
            ]
        );

        $this->command->info('内容就绪：企业简介叙事插槽（Demo）');
    }

    /**
     * 内容数据集（仅知识中心示例文章）
     */
    protected function rows(): array
    {
        return [
            [
                'category' => 'knowledge',
                'group'    => 'selection',
                'data' => [
                    'type' => 'article',
                    'slug' => 'how-to-choose-industrial-coatings',
                    'title' => '工业涂料怎么选：树脂体系、性能指标与选型逻辑',
                    'summary' => '工业涂料由成膜树脂、颜填料与功能助剂复配而成，树脂体系决定附着力与耐候防腐基线，可按基材与工况需求定制。',
                    'body' => "## 涂料由什么决定性能\n\n工业涂料通常由成膜树脂、颜填料、溶剂与功能助剂复配而成，树脂体系决定附着力、耐候与防腐基线，助剂则调节流平、消泡与固化表现。\n\n## 选型看哪些指标\n\n选型时先明确基材材质、使用环境（腐蚀、温变、户外曝晒）与施工方式，再对照附着力、耐盐雾、耐温与干燥时间等指标缩小范围。\n\n## 为什么要定制\n\n不同产线的基材前处理、烘烤条件与性能侧重不同，按目标参数调整配方，才能保证批量供货时涂层表现稳定。",
                    'geo_conclusion' => '工业涂料是由成膜树脂、颜填料与功能助剂复配而成的表面材料，性能由树脂体系与配方共同决定，应结合基材、环境与施工参数选型，并可按目标指标定制。',
                    'geo_explanation' => "树脂决定附着力与耐候防腐基线，颜填料提供遮盖与力学性能，助剂影响施工与固化；示例制造依托混合调配与加工成型车间，把目标性能转化为可复配的标准化配方。",
                    'geo_evidence' => [
                        ['label' => '车间支撑', 'value' => '混合调配车间与加工成型车间支撑复配生产', 'source' => '演示资料'],
                        ['label' => '产品线', 'value' => '已形成工业涂料、结构胶粘剂、功能性助剂三大产品线', 'source' => '企业资料（演示）'],
                    ],
                    'geo_boundary' => '具体配方与性能参数属于定制内容，需结合客户基材与工况单独打样；本页只解释通用选型原理，不构成具体性能承诺。',
                    'geo_faq' => [
                        ['q' => '涂料和胶粘剂是一回事吗？', 'a' => '不是。涂料主要在基材表面形成保护或装饰涂层，胶粘剂用于材料之间的粘接，两者评价指标与施工方式不同。'],
                    ],
                    'fact_refs' => ['FACT-COMPANY-009','FACT-COMPANY-011','FACT-PRODUCT-001'],
                ],
            ],
            [
                'category' => 'knowledge',
                'group'    => 'process',
                'data' => [
                    'type' => 'article',
                    'slug' => 'adhesive-customization-process',
                    'title' => '胶粘剂定制开发流程：从需求对接到批量交付',
                    'summary' => '胶粘剂定制一般经过需求对接、配方打样、试样确认、批量交付四个阶段，通常需要 2–3 轮试样。',
                    'body' => "## 四个阶段\n\n1. **需求对接**：确认粘接基材、工况环境、强度与耐温要求及目标成本；\n2. **配方打样**：研发按指标产出 2–3 个候选配方；\n3. **试样确认**：客户按实际工艺粘接、测试并反馈调整；\n4. **批量交付**：锁定配方后在加工成型车间排产。\n\n## 标准化的意义\n\n锁定后的配方以固定工艺参数复配，保证不同批次之间的粘接强度与固化表现稳定。",
                    'geo_conclusion' => '示例制造的胶粘剂定制分为需求对接、配方打样、试样确认与批量交付四个阶段，通常经过 2–3 轮试样后锁定配方，再按标准化工艺批量生产。',
                    'geo_explanation' => "需求阶段明确基材与工况边界；打样阶段并行产出多个版本提高命中率；试样阶段以客户真实工艺验证；配方锁定后通过固定配比与混合工艺保证批次一致性。",
                    'geo_evidence' => [
                        ['label' => '研发打样', 'value' => '研发打样支持多版本配方并行打样', 'source' => '演示资料'],
                        ['label' => '批量生产', 'value' => '加工成型与质检包装车间负责生产与出厂检验', 'source' => '演示资料'],
                    ],
                    'geo_boundary' => '试样轮次与交付周期会随品类复杂度变化，具体起订量与排期需由业务确认，本页不做统一承诺。',
                    'geo_faq' => [
                        ['q' => '定制一般需要几轮试样？', 'a' => '通常 2–3 轮，具体以性能确认进度为准。'],
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
                    'title' => '工业材料 OEM/ODM 代工常见问题：流程、质量与边界',
                    'summary' => '说明工业材料 OEM/ODM 代工的合作流程、质量体系与信息边界，起订量与交付周期需结合品类由业务确认。',
                    'body' => "## 代工模式\n\nOEM 按客户配方或指定要求生产，ODM 由厂方提供配方与工艺方案，两者都在相应资质与标准化质量体系范围内组织生产。\n\n## 合作流程\n\n需求沟通 → 配方与工艺确认 → 打样试样 → 报价与合同 → 排产 → 交付。\n\n## 常见问题\n\n详见下方问答。",
                    'geo_conclusion' => '示例制造提供工业涂料、结构胶粘剂与功能性助剂的 OEM/ODM 代工，合作流程为需求沟通、配方工艺确认、打样试样、报价签约、排产交付，生产在标准化质量体系下开展。',
                    'geo_explanation' => "OEM 适合已有成熟配方的品牌，ODM 适合需要研发支持的客户；公司依托多车间协同完成从复配到成型的生产，并可配合客户的质量体系与来料、出厂检验要求。",
                    'geo_evidence' => [
                        ['label' => '质量体系', 'value' => '按标准化质量管理体系组织生产，关键批次留样可追溯', 'source' => '企业资料（演示）'],
                        ['label' => '车间能力', 'value' => '混合调配、加工成型与质检包装车间协同支撑代工', 'source' => '演示资料'],
                    ],
                    'geo_boundary' => '起订量、交付周期、账期与价格属于一事一议内容，需结合品类、规格与订单量由业务报价，本页不公布统一数字。',
                    'geo_faq' => [
                        ['q' => 'OEM 和 ODM 有什么区别？', 'a' => 'OEM 按客户提供的配方与要求生产；ODM 由厂方提供配方与工艺方案，客户再做选择与调整。'],
                        ['q' => '最小起订量是多少？', 'a' => '不同品类起订量不同，需要业务结合品类与规格确认后告知，本页不设统一数字。'],
                        ['q' => '可以先试样再决定吗？', 'a' => '可以，合作流程中包含打样试样环节，性能确认后再进入报价与排产。'],
                    ],
                    'fact_refs' => ['FACT-BIZ-001','FACT-PRODUCT-002','FACT-COMPANY-009'],
                ],
            ],
        ];
    }
}
