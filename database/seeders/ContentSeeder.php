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

                // P-STEP 18F：同步维护英文翻译行（幂等，同 translation_group）
                $enFields = $this->enRow($data['slug']);
                $enExist = Content::where('translation_group', $saved->translation_group)
                    ->where('locale', 'en')->first();
                if ($enExist) {
                    $enExist->update($enFields);
                } else {
                    $saved->createTranslation('en', $enFields);
                }

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
            '主营业务覆盖工业防护涂料、工业胶粘剂与功能助剂三大产品线，为工业制造企业、工程承包商、工业品牌方与渠道伙伴提供产品选型、定制化解决方案与稳定供货。',
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
                    'slug' => 'customized-solutions-faq',
                    'title' => '工业材料定制化解决方案常见问题：流程、质量与边界',
                    'summary' => '说明工业材料定制化解决方案的合作流程、质量体系与信息边界，起订量与交付周期需结合品类由业务确认。',
                    'body' => "## 定制合作模式\n\n客户可基于需求选择产品选型、参数定制或联合开发，所有合作在相应资质与标准化质量体系范围内组织生产。\n\n## 合作流程\n\n需求沟通 → 方案与工艺确认 → 打样试样 → 报价与合同 → 排产 → 交付。\n\n## 常见问题\n\n详见下方问答。",
                    'geo_conclusion' => '示例制造围绕工业涂料、结构胶粘剂与功能性助剂提供产品与定制化解决方案，合作流程为需求沟通、方案工艺确认、打样试样、报价签约、排产交付，生产在标准化质量体系下开展。',
                    'geo_explanation' => "产品选型适合需求明确的客户，参数定制与联合开发适合有特殊指标或研发需求的客户；公司依托多车间协同完成从复配到成型的生产，并可配合客户的质量体系与来料、出厂检验要求。",
                    'geo_evidence' => [
                        ['label' => '质量体系', 'value' => '按标准化质量管理体系组织生产，关键批次留样可追溯', 'source' => '企业资料（演示）'],
                        ['label' => '车间能力', 'value' => '混合调配、加工成型与质检包装车间协同支撑定制生产', 'source' => '演示资料'],
                    ],
                    'geo_boundary' => '起订量、交付周期、账期与价格属于一事一议内容，需结合品类、规格与订单量由业务报价，本页不公布统一数字。',
                    'geo_faq' => [
                        ['q' => '产品选型和定制开发有什么区别？', 'a' => '产品选型是从现有成熟产品中匹配；定制开发是按客户指标调整配方或工艺，需要打样确认。'],
                        ['q' => '最小起订量是多少？', 'a' => '不同品类起订量不同，需要业务结合品类与规格确认后告知，本页不设统一数字。'],
                        ['q' => '可以先试样再决定吗？', 'a' => '可以，合作流程中包含打样试样环节，性能确认后再进入报价与排产。'],
                    ],
                    'fact_refs' => ['FACT-BIZ-001','FACT-PRODUCT-002','FACT-COMPANY-009'],
                ],
            ],
        ];
    }

    /**
     * 知识文章英文翻译字段（P-STEP 18F），按 zh slug 键控。
     * slug 与中文相同（英文经 /en 前缀区分），仅翻译字段独立。
     */
    protected function enRow(string $slug): array
    {
        return $this->enRows()[$slug] ?? ['slug' => $slug];
    }

    protected function enRows(): array
    {
        return [
            'how-to-choose-industrial-coatings' => [
                'slug' => 'how-to-choose-industrial-coatings',
                'title' => 'How to Choose Industrial Coatings: Resin Systems, Performance Indicators and Selection Logic',
                'summary' => 'Industrial coatings are formulated from film-forming resins, pigments and fillers and functional additives. The resin system sets the baseline for adhesion, weather and corrosion resistance, and can be customized to substrate and operating conditions.',
                'body' => "## What determines coating performance\n\nIndustrial coatings are generally formulated from film-forming resins, pigments and fillers, solvents and functional additives. The resin system determines adhesion, weather and corrosion baseline, while additives adjust leveling, defoaming and curing behavior.\n\n## Which indicators to check\n\nWhen selecting, first clarify the substrate material, service environment (corrosion, temperature change, outdoor exposure) and application method, then narrow the range against indicators such as adhesion, salt-spray resistance, temperature resistance and drying time.\n\n## Why customize\n\nSubstrate pretreatment, baking conditions and performance priorities differ across production lines. Adjusting the formulation to target parameters keeps coating performance stable during batch supply.",
                'geo_conclusion' => 'Industrial coatings are surface materials formulated from film-forming resins, pigments and fillers and functional additives; performance is determined jointly by the resin system and formulation. They should be selected based on substrate, environment and application parameters, and can be customized to target indicators.',
                'geo_explanation' => 'Resin sets the baseline for adhesion and weather and corrosion resistance, pigments and fillers provide hiding and mechanical properties, and additives affect application and curing; Example Manufacturing turns target performance into standardized, reproducible formulations through its mixing and processing workshops.',
                'geo_evidence' => [
                    ['label' => 'Workshop support', 'value' => 'Mixing and processing workshops support formulation production', 'source' => 'Demo materials'],
                    ['label' => 'Product lines', 'value' => 'Three product lines established: industrial coatings, structural adhesives, functional additives', 'source' => 'Company materials (demo)'],
                ],
                'geo_boundary' => 'Specific formulations and performance parameters are custom content and require separate sampling against the customer substrate and conditions; this page only explains general selection principles and does not constitute a specific performance commitment.',
                'geo_faq' => [
                    ['q' => 'Are coatings and adhesives the same thing?', 'a' => 'No. Coatings mainly form a protective or decorative film on the substrate surface, while adhesives bond materials together; the two have different evaluation indicators and application methods.'],
                ],
            ],
            'adhesive-customization-process' => [
                'slug' => 'adhesive-customization-process',
                'title' => 'Adhesive Customization Process: From Requirement Alignment to Batch Delivery',
                'summary' => 'Adhesive customization generally goes through four stages: requirement alignment, formulation sampling, sample confirmation and batch delivery, usually requiring 2 to 3 rounds of samples.',
                'body' => "## Four stages\n\n1. **Requirement alignment**: confirm bonding substrates, operating environment, strength and temperature requirements and target cost;\n2. **Formulation sampling**: R and D produces 2 to 3 candidate formulations against the indicators;\n3. **Sample confirmation**: the customer bonds and tests using the real process and gives adjustment feedback;\n4. **Batch delivery**: after the formulation is locked, schedule production in the processing workshop.\n\n## The meaning of standardization\n\nA locked formulation is reproduced with fixed process parameters to keep bonding strength and curing performance stable across batches.",
                'geo_conclusion' => 'Example Manufacturing divides adhesive customization into four stages: requirement alignment, formulation sampling, sample confirmation and batch delivery, usually locking the formulation after 2 to 3 sample rounds, then producing in batches under a standardized process.',
                'geo_explanation' => 'The requirement stage clarifies substrate and operating boundaries; the sampling stage produces multiple versions in parallel to improve the hit rate; the sample stage is verified with the real customer process; after the formulation is locked, fixed ratios and mixing processes ensure batch consistency.',
                'geo_evidence' => [
                    ['label' => 'R and D sampling', 'value' => 'R and D sampling supports parallel multi-version formulations', 'source' => 'Demo materials'],
                    ['label' => 'Batch production', 'value' => 'Processing and QC and packaging workshops handle production and outgoing inspection', 'source' => 'Demo materials'],
                ],
                'geo_boundary' => 'Sample rounds and delivery time vary with category complexity; specific MOQ and scheduling must be confirmed by the business, and this page makes no uniform commitment.',
                'geo_faq' => [
                    ['q' => 'How many sample rounds does customization usually take?', 'a' => 'Usually 2 to 3 rounds, subject to the progress of performance confirmation.'],
                    ['q' => 'Can adjustments be made after the formulation is locked?', 'a' => 'Yes, adjustments require re-sampling and confirmation and an update to the standardized formulation version.'],
                ],
            ],
            'customized-solutions-faq' => [
                'slug' => 'customized-solutions-faq',
                'title' => 'Industrial Materials Customized Solutions FAQ: Process, Quality and Boundaries',
                'summary' => 'Explains the cooperation process, quality system and information boundaries for industrial materials customized solutions; MOQ and delivery time must be confirmed by the business based on category.',
                'body' => "## Custom cooperation models\n\nCustomers can choose product selection, parameter customization or joint development based on need; all cooperation is organized within the relevant qualifications and standardized quality system.\n\n## Cooperation process\n\nRequirement discussion, solution and process confirmation, sampling, quotation and contract, scheduling, delivery.\n\n## Common questions\n\nSee the Q and A below.",
                'geo_conclusion' => 'Example Manufacturing provides products and customized solutions around industrial coatings, structural adhesives and functional additives. The cooperation process is requirement discussion, solution and process confirmation, sampling, quotation and contract, scheduling and delivery, with production under a standardized quality system.',
                'geo_explanation' => 'Product selection suits customers with clear requirements, while parameter customization and joint development suit those needing special indicators or R and D support; the company uses multi-workshop coordination to complete production from formulation to forming, and can align with the customer quality system and incoming and outgoing inspection requirements.',
                'geo_evidence' => [
                    ['label' => 'Quality system', 'value' => 'Production is organized under a standardized quality management system, with key batch samples retained for traceability', 'source' => 'Company materials (demo)'],
                    ['label' => 'Workshop capability', 'value' => 'Mixing, processing and QC and packaging workshops jointly support customized production', 'source' => 'Demo materials'],
                ],
                'geo_boundary' => 'MOQ, delivery time, payment terms and price are case-by-case items, quoted by the business based on category, specification and order volume; this page does not publish uniform numbers.',
                'geo_faq' => [
                    ['q' => 'What is the difference between product selection and custom development?', 'a' => 'Product selection matches existing mature products; custom development adjusts the formulation or process to customer indicators and requires sampling.'],
                    ['q' => 'What is the minimum order quantity?', 'a' => 'MOQ varies by category and must be confirmed by the business with the category and specification; this page sets no uniform number.'],
                    ['q' => 'Can I sample before deciding?', 'a' => 'Yes, the cooperation process includes a sampling stage; after performance is confirmed, move to quotation and scheduling.'],
                ],
            ],
        ];
    }
}
