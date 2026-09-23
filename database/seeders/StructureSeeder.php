<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\PageBlock;
use App\Support\Catalog;
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
        // 框架标题/副标题一律留空，由 blade 走翻译键（zh-CN/en 自动）；所有条目也一律
        // 留空，由 HomeBlockDefaults / HomeController 在渲染时按当前 locale 实时投影
        // （Catalog / 翻译键）。seeder 只在单一 locale 运行，不能把可翻译文案写进
        // 无 locale 的 page_blocks content，否则另一语言页被固定中文遮蔽。

        // type => [sort, categorySlug, limit, items[] or null]（title/subtitle/items 不写死）
        $blocks = [
            ['hero', 10, null, null, null],
            ['scenes', 20, null, null, null],
            ['capabilities', 26, null, null, null],
            ['products', 34, null, null, null],
            ['params', 44, null, null, null],
            ['stats', 50, null, null, null],
            ['workshops', 54, null, null, null],
            ['mid_banner', 57, null, 6, null],
            ['cooperation', 60, null, null, null],
            ['steps', 64, null, null, null],
            ['cases', 68, null, 3, null],
            ['knowledge', 72, 'knowledge', 6, null],
            ['news', 78, 'news', 6, null],
            ['cta', 84, null, null, null],
            ['faqs', 90, null, null, null],
            ['facts', 94, null, null, null],
        ];

        foreach ($blocks as [$type, $sort, $categorySlug, $limit, $items]) {
            $cat = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;
            $content = $items !== null
                ? json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
            PageBlock::updateOrCreate(
                ['page' => 'home', 'type' => $type],
                [
                    'title'       => '',
                    'subtitle'    => '',
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
