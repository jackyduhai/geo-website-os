<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\PageBlock;
use App\Support\Facts;
use App\Support\PageCache;
use Illuminate\Database\Seeder;

/**
 * 站点结构种子（Demo 数据层）：栏目树 + 分组 + 首页区块。
 *
 * 重要分层（P-STEP 18B / Blank System ≠ Demo Site）：
 *   - 出厂安装 geo:install 只迁移 + 中性默认 + BlankHomepageSeeder（清空首页垂直装修），
 *     得到行业中立空站；
 *   - 本 seeder 仅在显式 db:seed（DemoSeeder）时运行，负责重建完整的「工业材料制造」
 *     Example 演示站装修（栏目、分组、16 个首页区块及其行业文案）。
 *   因此这里出现的制造 / OEM / ODM / 车间 / 配方等文案都属于 Example Demo 数据，
 *   不会进入出厂空站的默认模板。
 *
 * 结构规则：
 *   栏目 category ── 可进导航、有独立页面（列表 / 单页）
 *   分组 group   ── 栏目内的二级归集，用于内容分类，不单独成页
 */
class StructureSeeder extends Seeder
{
    public function run(): void
    {
        // v0.9.22：栏目树只保留真正由 CMS 内容驱动的知识中心、新闻动态。
        $tree = [
            [
                'name'        => '知识中心',
                'slug'        => 'knowledge',
                'type'        => 'list',
                'description' => '选型指南、工艺参数与应用案例类内容，供采购与技术选型参考。',
                'sort'        => 30,
                'groups'      => [
                    ['name' => '选型指南', 'slug' => 'selection', 'sort' => 10,
                     'description' => '如何认识与挑选涂料、胶粘剂与功能性助剂。'],
                    ['name' => '工艺与配方', 'slug' => 'process', 'sort' => 20,
                     'description' => '生产工艺、参数逻辑与配方定制过程。'],
                    ['name' => '选型与应用', 'slug' => 'business', 'sort' => 30,
                     'description' => '选型落地、代工合作与稳定供应参考。'],
                ],
            ],
            [
                'name'        => '新闻动态',
                'slug'        => 'news',
                'type'        => 'list',
                'description' => '公司动态、行业动态与对外公告。',
                'sort'        => 40,
                'groups'      => [
                    ['name' => '企业新闻', 'slug' => 'company-news', 'sort' => 10, 'description' => '公司经营与参展动态。'],
                    ['name' => '行业动态', 'slug' => 'industry-news', 'sort' => 20, 'description' => '行业展会与市场信息。'],
                    ['name' => '通知公告', 'slug' => 'notice', 'sort' => 30, 'description' => '对外公告。'],
                ],
            ],
        ];

        foreach ($tree as $node) {
            $parent = Category::updateOrCreate(
                ['slug' => $node['slug']],
                [
                    'parent_id'   => null,
                    'name'        => $node['name'],
                    'type'        => $node['type'],
                    'description' => $node['description'] ?? null,
                    'sort'        => $node['sort'],
                    'is_nav'      => true,
                    'is_active'   => true,
                ]
            );

            foreach ($node['groups'] ?? [] as $g) {
                Group::updateOrCreate(
                    ['category_id' => $parent->id, 'slug' => $g['slug']],
                    [
                        'name'        => $g['name'],
                        'description' => $g['description'] ?? null,
                        'sort'        => $g['sort'],
                        'is_active'   => true,
                    ]
                );
            }
        }

        // ---------- 首页区块（Demo 工业材料制造装修，sort 即前台呈现顺序） ----------
        $co = Facts::company();
        $brand = $co['brand'] ?? ($co['name'] ?? config('app.name'));
        $years = (int) ($co['tech_experience_years'] ?? 0);

        // type => [title, subtitle, sort, categorySlug, limit, items[]]
        $blocks = [
            ['hero', $brand . '，从研发到量产的一站式定制制造', $brand . ($years > 0 ? ' · 深耕行业 ' . $years . ' 年' : ''), 10, null, null, null],
            ['scenes', '先选应用场景，再看匹配的产品组合', '按应用行业与工况分诊：每类场景都给出推荐产品组合，以及可复现的关键参数与工艺要点。', 20, null, null, null],
            ['capabilities', '专注产品研发与生产的一体化源头工厂', '', 26, null, null, [
                ['icon' => 'sliders', 'title' => '配方定制', 'text' => '按客户需求定向研发，非标规格按需定制，明确性能方向后给出配方与工艺建议。'],
                ['icon' => 'factory', 'title' => 'OEM · ODM 代工', 'text' => '从配方到成品的一体化代工链路，支持按客户配方或客户品牌组织生产。'],
                ['icon' => 'repeat', 'title' => '打样到量产', 'text' => '需求对接、配方打样、试样确认、批量交付，各环节标准化衔接。'],
            ]],
            ['products', '产品体系', '覆盖不同应用场景的产品系列', 34, null, null, null],
            ['params', '把规格做到粘度、固含与固化时间，产线照着就能复现', '不止提供产品，更给出标准化配比与工艺参数，多基地、批量生产都能稳定还原同一性能。', 44, null, null, [
                ['title' => '自有产线生产', 'text' => '从原料处理到品控包装同厂完成，性能与批次更可控。'],
                ['title' => '给到真实配比工艺', 'text' => '写清配比、温度与时间，不只供料，更说明怎么用。'],
                ['title' => '多产品线一站配齐', 'text' => '防护涂料、工业胶粘剂与功能助剂协同配套，减少多头对接。'],
            ]],
            ['stats', '', '', 50, null, null, null],
            ['workshops', '生产线一体协同，从研发到量产闭环', '各生产环节全面投产，支撑从配方到成品的一体化交付。', 54, null, null, [
                ['icon' => 'package', 'title' => '原料处理车间', 'text' => '原材料进厂检验与预处理。'],
                ['icon' => 'sliders', 'title' => '配料混合车间', 'text' => '按配方精确配料与分散混合。'],
                ['icon' => 'gear', 'title' => '成型加工车间', 'text' => '涂布、施胶与成型加工。'],
                ['icon' => 'shield', 'title' => '品控包装车间', 'text' => '成品检测与分规格包装。'],
            ]],
            ['mid_banner', '', '', 57, null, 6, null],
            ['cooperation', '三种合作方式，从研发到稳定供货', '无论装备制造、建筑工程、汽车零部件还是渠道经销商，都能找到对应的合作路径。', 60, null, null, null],
            ['steps', '四步完成从需求到批量交付', '', 64, null, null, [
                ['icon' => 'chat', 'title' => '需求对接', 'text' => '明确品类、规格方向、包装与用量，建立需求档案。'],
                ['icon' => 'sliders', 'title' => '配方打样', 'text' => '研发按需求调配，提供首轮样品与规格说明。'],
                ['icon' => 'beaker', 'title' => '试样确认', 'text' => '通常 2–3 轮试样，锁定配方与工艺参数。'],
                ['icon' => 'truck', 'title' => '批量交付', 'text' => '各生产线排产，稳定供货并持续品质跟进。'],
            ]],
            ['cases', '不同行业，都在用同一套稳定标准', '为保护客户经营信息，以下均做匿名处理，仅呈现应用行业与所用产品组合。', 68, null, 3, null],
            ['knowledge', '知识中心', '选型与工艺应用参考', 72, 'knowledge', 6, null],
            ['news', '新闻动态', '公司动态与行业信息', 78, 'news', 6, null],
            ['cta', '联系我们', '配方定制、打样与 OEM/ODM 代工咨询', 84, null, null, null],
            ['faqs', '合作前，你可能想先了解这些', '', 90, null, null, [
                ['title' => '起订量是多少？', 'text' => '不同品类的起订量不一样。说明你的材料与用量，我们按你的情况报价。'],
                ['title' => '可以按我自己的配方做吗？', 'text' => '可以，这属于 OEM 代工模式。我们按你提供的配方与标准生产，配方归属与保密方式在合同中约定。'],
                ['title' => '可以打我自己品牌吗？', 'text' => '可以，支持 OEM 与 ODM 两种方式。包装与品牌由你方确定，我们负责生产与供货。'],
                ['title' => '打样需要多久？', 'text' => '打样周期与配方复杂度有关，沟通清楚需求后我们给出确切时间。'],
                ['title' => '你们的材料稳定吗？', 'text' => '关键产品都标注了推荐配比与施工工艺参数，产线按标准流程生产，便于批次间稳定复现。'],
                ['title' => '供货稳定吗？', 'text' => '自有产线与品控包装流程，服务覆盖全国多个销售区域，可按订单节奏稳定供货。'],
                ['title' => '有技术支持吗？', 'text' => '提供产品使用方法与施工工艺参数，需要现场支持的情况可以单独沟通。'],
                ['title' => '怎么拿到样品？', 'text' => '填写页面下方表单，或通过页面上的联系方式与我们沟通，确认需求后安排试样。'],
            ]],
            ['facts', '主体事实', '可直接核验的基础信息', 94, null, null, null],
        ];

        foreach ($blocks as [$type, $title, $subtitle, $sort, $categorySlug, $limit, $items]) {
            $cat = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;
            $content = $items !== null
                ? json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            PageBlock::updateOrCreate(
                ['page' => 'home', 'type' => $type],
                [
                    'title'       => $title,
                    'subtitle'    => $subtitle,
                    'category_id' => $cat?->id,
                    'limit'       => $limit ?? 6,
                    'sort'        => $sort,
                    'is_active'   => true,
                    'content'     => $content,
                ]
            );
        }

        PageCache::flush();

        $this->command->info(sprintf(
            'Demo 站点结构：栏目 %d 个（顶级 %d）、分组 %d 个、首页区块 %d 个',
            Category::count(),
            Category::whereNull('parent_id')->count(),
            Group::count(),
            PageBlock::count()
        ));
    }
}
