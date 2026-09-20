<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\PageBlock;
use Illuminate\Database\Seeder;

/**
 * 站点结构种子：栏目树 + 分组 + 首页区块
 *
 * 结构规则：
 *   栏目 category ── 可进导航、有独立页面（列表 / 单页 / 产品）
 *   分组 group   ── 栏目内的二级归集，用于内容分类，不单独成页
 *
 * 设计依据：官网 GEO 搭建方案中的站点树，已剥离与本主体无关的内容。
 */
class StructureSeeder extends Seeder
{
    public function run(): void
    {
        // v0.9.22：栏目树只保留真正由 CMS 内容驱动的知识中心、新闻动态。
        // 关于/产品/工厂/合作/联系等结构化页面由静态路由 + facts/pages 单一事实源
        // 驱动（可运营叙事走「页面文案」叙事插槽），不再建被静态路由遮蔽的影子栏目。
        $tree = [
            [
                'name'        => '知识中心',
                'slug'        => 'knowledge',
                'type'        => 'list',
                'description' => '选型指南、工艺参数与应用案例类内容，供采购与技术选型参考。',
                'sort'        => 30,
                // v0.9.11：知识子栏目以锁定 IA 三栏目为唯一来源（数据驱动前台/导航/sitemap/llms）
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

            foreach ($node['children'] ?? [] as $child) {
                Category::updateOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'parent_id'   => $parent->id,
                        'name'        => $child['name'],
                        'type'        => $child['type'],
                        'description' => $child['description'] ?? null,
                        'sort'        => $child['sort'],
                        'is_nav'      => true,
                        'is_active'   => true,
                    ]
                );
            }

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

        // ---------- 首页区块（顺序即前台呈现顺序） ----------
        $blocks = [
            ['hero',      '', '', 1, null],
            ['facts',     '主体事实',   '可直接核验的基础信息',            2, null],
            // products 区块为手工/装修驱动，不绑定栏目（结构化产品走 facts 静态页）
            ['products',  '产品体系',   '覆盖不同应用场景的产品系列',      3, null],
            ['knowledge', '知识中心',   '选型与工艺应用参考',              4, 'knowledge'],
            ['news',      '新闻动态',   '公司动态与行业信息',              5, 'news'],
            ['cta',       '联系我们',   '配方定制、打样与 OEM/ODM 代工咨询', 6, null],
        ];
        $sort = 0;
        foreach ($blocks as $b) {
            $cat = $b[4] ? Category::where('slug', $b[4])->first() : null;
            PageBlock::updateOrCreate(
                ['page' => 'home', 'type' => $b[0]],
                [
                    'title'       => $b[1],
                    'subtitle'    => $b[2],
                    'category_id' => $cat?->id,
                    'limit'       => 6,
                    'sort'        => $sort += 10,
                    'is_active'   => true,
                ]
            );
        }

        $this->command->info(sprintf(
            '站点结构：栏目 %d 个（顶级 %d）、分组 %d 个、首页区块 %d 个',
            Category::count(),
            Category::whereNull('parent_id')->count(),
            Group::count(),
            PageBlock::count()
        ));
    }
}
