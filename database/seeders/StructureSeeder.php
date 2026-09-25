<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Support\Localization\LocaleContext;
use App\Support\PageCache;
use App\Support\PublicUrl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * 站点结构种子（Demo 数据层）：栏目树 + 分组 + 首页 Composition。
 *
 * 重要分层（P-STEP 18B / Blank System ≠ Demo Site；P-STEP 18I / TD-70）：
 *   - 出厂安装 geo:install 只迁移 + 中性默认 + BlankHomepageSeeder（中性 is_home Page、
 *     无区块，首页回落中性欢迎屏），得到行业中立空站；
 *   - 本 seeder 仅在显式 db:seed（DemoSeeder）时运行，负责往 is_home Page 挂载完整的
 *     「工业材料制造」Example 演示首页区块（双语静态文案 + 数据源自动投影）。
 *   因此这里出现的制造 / OEM / ODM / 车间 / 配方等文案都属于 Example Demo 数据，
 *   不会进入出厂空站。
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
                     'description' => '选型落地、应用案例与合作咨询参考。'],
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

        $this->buildHomepage();

        $this->command->info(sprintf(
            'Demo 站点结构：栏目 %d 个（顶级 %d）、分组 %d 个、首页 Composition 区块 %d 个',
            Category::count(),
            Category::whereNull('parent_id')->count(),
            Group::count(),
            PageBlock::where('page', 'page')->where('slot', 'main')->count()
        ));
    }

    /**
     * 首页 Composition（P-STEP 18I / TD-70）。
     * --------------------------------------------------
     * 1) 先经 BlankHomepageSeeder 建立中性 is_home Page（zh-CN/en）并清理旧装修；
     * 2) 为每个语言的 home Page 挂载注册的通用 block（page='page'、page_id、slot=main）：
     *    静态 block（hero/feature/stats/testimonial/faq/cta）用双语 Example 文案，
     *    数据源 block（service/product/content grid）由 BlockRegistry 按 locale 自动投影。
     * 幂等：重建前清空该 home Page 的 composition 区块，重跑结果一致。
     */
    protected function buildHomepage(): void
    {
        // 1) 中性 home Page + 清理旧 page='home' 装修区块。
        $this->call(BlankHomepageSeeder::class);

        $site = Site::where('slug', Site::DEFAULT_SLUG)->first();
        if (! $site) {
            return;
        }
        $knowledgeId = Category::where('slug', 'knowledge')->value('id');

        $originalLocale = App::getLocale();

        try {
            foreach (['zh-CN', 'en'] as $locale) {
                App::setLocale($locale);
                LocaleContext::set($locale);

                $home = Page::where('site_id', $site->id)
                    ->where('is_home', true)->where('locale', $locale)->first();
                if (! $home) {
                    continue;
                }

                // 幂等：清空该 home Page 现有 composition 区块后重建（sort 重新连续）。
                PageBlock::where('site_id', $site->id)
                    ->where('page_id', $home->id)->delete();

                $sort = 0;
                foreach ($this->homeBlockSpecs($locale, (int) $knowledgeId) as $type => $content) {
                    $sort += 10;
                    PageBlock::create([
                        'site_id'   => $site->id,
                        'page'      => 'page',
                        'page_id'   => $home->id,
                        'slot'      => 'main',
                        'type'      => $type,
                        'title'     => '',
                        'subtitle'  => '',
                        'content'   => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'limit'     => (int) ($content['limit'] ?? 6),
                        'sort'      => $sort,
                        'is_active' => true,
                    ]);
                }
            }
        } finally {
            App::setLocale($originalLocale);
            LocaleContext::clear();
            PageCache::flush();
        }
    }

    /**
     * 首页 block 规格（有序 type => content）。静态文案按 locale 返回；数据源 content
     * 中 source/limit 不随语言变化（卡片由 BlockRegistry 按当前 locale 投影）。
     */
    protected function homeBlockSpecs(string $locale, int $knowledgeId): array
    {
        $en = $locale === 'en';

        return [
            'hero' => $en ? [
                'eyebrow'  => 'INDUSTRIAL MATERIALS',
                'title'    => 'Coatings · Adhesives · Additives, Customized as One',
                'subtitle' => 'We develop and manufacture protective coatings, industrial adhesives and functional additives — from selection and sampling to stable supply and ODM/OEM customization.',
                'buttons'  => [
                    ['label' => 'Products', 'url' => PublicUrl::url('products/'), 'style' => 'primary'],
                    ['label' => 'Contact Us', 'url' => PublicUrl::url('contact/'), 'style' => 'secondary'],
                ],
            ] : [
                'eyebrow'  => 'INDUSTRIAL MATERIALS · 工业材料',
                'title'    => '工业涂料 · 胶粘剂 · 功能助剂 一体化定制',
                'subtitle' => '专注防护涂料、工业胶粘剂与功能性助剂的研发与生产，提供从选型、打样到稳定供货的产品与 ODM / OEM 定制方案。',
                'buttons'  => [
                    ['label' => '产品中心', 'url' => PublicUrl::url('products/'), 'style' => 'primary'],
                    ['label' => '联系我们', 'url' => PublicUrl::url('contact/'), 'style' => 'secondary'],
                ],
            ],

            'service_grid' => $en ? [
                'title' => 'Application Scenarios', 'subtitle' => 'Customized solutions by industry and operating condition',
                'source' => ['mode' => 'all'], 'limit' => 6,
            ] : [
                'title' => '应用场景', 'subtitle' => '按行业与工况提供定制化解决方案',
                'source' => ['mode' => 'all'], 'limit' => 6,
            ],

            'feature_grid' => $en ? [
                'title' => 'Core Capabilities', 'subtitle' => 'Integrated capability from R&D to delivery',
                'columns' => 4,
                'items' => [
                    ['icon' => 'beaker', 'title' => 'Custom R&D', 'text' => 'Develop formulations and processes to your operating conditions, with sampling and validation.'],
                    ['icon' => 'gear', 'title' => 'Stable Production', 'text' => 'Standardized lines and process control for consistent batch performance.'],
                    ['icon' => 'shield', 'title' => 'Strict QC', 'text' => 'Full inspection from incoming material to finished goods, with traceable specs.'],
                    ['icon' => 'truck', 'title' => 'Reliable Delivery', 'text' => 'Packaging and logistics solutions for ongoing, stable supply.'],
                ],
            ] : [
                'title' => '核心能力', 'subtitle' => '从研发到交付的一体化能力',
                'columns' => 4,
                'items' => [
                    ['icon' => 'beaker', 'title' => '定制研发', 'text' => '按客户工况定向开发配方与工艺，提供打样验证。'],
                    ['icon' => 'gear', 'title' => '稳定生产', 'text' => '标准化产线与工艺控制，保障批次性能一致。'],
                    ['icon' => 'shield', 'title' => '严格品控', 'text' => '从来料、过程到成品全项检测，指标可追溯。'],
                    ['icon' => 'truck', 'title' => '可靠交付', 'text' => '配套包装与物流方案，支持持续稳定供货。'],
                ],
            ],

            'product_grid' => $en ? [
                'title' => 'Products', 'subtitle' => 'A systematic product matrix for diverse needs',
                'source' => ['mode' => 'all'], 'limit' => 8,
            ] : [
                'title' => '产品中心', 'subtitle' => '系统化的产品矩阵，满足多样化需求',
                'source' => ['mode' => 'all'], 'limit' => 8,
            ],

            'stats' => $en ? [
                'items' => [
                    ['value' => '20+', 'unit' => '', 'label' => 'Years in industry'],
                    ['value' => '8000+', 'unit' => 'sqm', 'label' => 'In-house facility'],
                    ['value' => '30000+', 'unit' => 'tons', 'label' => 'Annual capacity'],
                    ['value' => '6', 'unit' => '', 'label' => 'Major product lines'],
                ],
            ] : [
                'items' => [
                    ['value' => '20+', 'unit' => '', 'label' => '年行业深耕'],
                    ['value' => '8000+', 'unit' => '㎡', 'label' => '自有厂区面积'],
                    ['value' => '30000+', 'unit' => '吨', 'label' => '年产能'],
                    ['value' => '6', 'unit' => '', 'label' => '大产品系列'],
                ],
            ],

            'content_grid' => $en ? [
                'title' => 'Knowledge & Selection',
                'category_id' => $knowledgeId, 'source' => ['mode' => 'latest'], 'limit' => 3,
            ] : [
                'title' => '知识与选型',
                'category_id' => $knowledgeId, 'source' => ['mode' => 'latest'], 'limit' => 3,
            ],

            'testimonial' => $en ? [
                'title' => 'What Customers Say',
                'items' => [
                    ['quote' => 'After the formulation was adjusted, stability under high-temperature conditions improved clearly, with excellent batch consistency.', 'name' => 'Mr. Wang', 'role' => 'Technical Lead', 'company' => 'An equipment manufacturer'],
                    ['quote' => 'Sampling to volume supply went smoothly, with on-time delivery and fast technical response.', 'name' => 'Ms. Li', 'role' => 'Purchasing Manager', 'company' => 'An engineering firm'],
                    ['quote' => 'The selection materials and parameter notes are clear and saved us a lot of communication.', 'name' => 'Mr. Zhang', 'role' => 'Project Director', 'company' => 'An automotive parts plant'],
                ],
            ] : [
                'title' => '客户评价',
                'items' => [
                    ['quote' => '配方调整后，产品在高温工况下的稳定性明显提升，批次一致性很好。', 'name' => '王工', 'role' => '技术负责人', 'company' => '某装备制造企业'],
                    ['quote' => '从打样到批量供货衔接顺畅，交付及时，技术响应也很快。', 'name' => '李经理', 'role' => '采购经理', 'company' => '某工程公司'],
                    ['quote' => '配套的选型资料和参数说明清晰，减少了很多沟通成本。', 'name' => '张总', 'role' => '项目总监', 'company' => '某汽车零部件厂'],
                ],
            ],

            'faq' => $en ? [
                'title' => 'Frequently Asked Questions',
                'items' => [
                    ['q' => 'Do you support custom formulations and OEM/ODM?', 'a' => 'Yes. We develop to your operating conditions and performance needs, from sampling and pilot trials to volume supply, with OEM/ODM support as required.'],
                    ['q' => 'What is the minimum order quantity?', 'a' => 'MOQ varies by product. Standard products are kept in stock with standard packaging; custom products are agreed separately. Please contact us to confirm.'],
                    ['q' => 'Do you provide samples for testing?', 'a' => 'Yes. Once requirements are confirmed, we can arrange samples for your performance and process validation.'],
                    ['q' => 'How long is the delivery lead time?', 'a' => 'Standard products ship quickly; custom products depend on sampling and production scheduling. We confirm lead times before ordering.'],
                ],
            ] : [
                'title' => '常见问题',
                'items' => [
                    ['q' => '是否支持定制配方与 OEM / ODM？', 'a' => '支持。可按你的工况与性能需求定向开发，提供打样、小试到批量供货，并可按需求做 OEM / ODM 配套。'],
                    ['q' => '起订量是多少？', 'a' => '不同产品起订量不同，常规产品有标准包装库存，定制产品以协商为准，具体请联系我们确认。'],
                    ['q' => '是否提供样品测试？', 'a' => '提供。确认需求后可安排样品，供你做性能与工艺验证。'],
                    ['q' => '交付周期一般多久？', 'a' => '常规产品可快速发货，定制产品以打样与排产周期为准，我们会在下单前明确交期。'],
                ],
            ],

            'cta' => $en ? [
                'title' => 'Have a product or cooperation need?',
                'subtitle' => 'Tell us your operating conditions and requirements, and we will arrange someone to contact you shortly.',
                'buttons' => [
                    ['label' => 'Get a Solution', 'url' => PublicUrl::url('contact/'), 'style' => 'primary'],
                    ['label' => 'Scenarios', 'url' => PublicUrl::url('solutions/'), 'style' => 'secondary'],
                ],
            ] : [
                'title' => '有产品或合作需求？',
                'subtitle' => '告诉我们你的工况与需求，我们会尽快安排专人与你联系。',
                'buttons' => [
                    ['label' => '获取方案', 'url' => PublicUrl::url('contact/'), 'style' => 'primary'],
                    ['label' => '应用场景', 'url' => PublicUrl::url('solutions/'), 'style' => 'secondary'],
                ],
            ],
        ];
    }
}
