<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Models\PageBlock;

/**
 * v0.7：
 *  1) 首页新增 S07「客户合作剪影（匿名）」区块（cases，sort=68）
 *  2) 清空 v0.6 写入的首页 FAQ 自定义条目（其中含未核实的 SC 资质表述），
 *     回落到 config/pages.home_faqs 的 8 条已核实 FAQ（控制器统一驱动）
 *  3) 知识中心建立三个二级栏目 Group（选型指南 / 工艺与配方 / 选型与应用），
 *     并把现有 3 篇知识文章按主题分配 group_id（文章 URL 仍保持扁平 /knowledge/{slug}）
 * 幂等可重复执行；SQLite / PostgreSQL 兼容。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. S07 cases 区块
        $cases = PageBlock::firstOrNew(['page' => 'home', 'type' => 'cases']);
        $cases->sort = 68;
        $cases->is_active = true;
        $cases->limit = 3;
        if (! $cases->exists) {
            $cases->title = '不同行业，都在用同一套稳定标准';
            $cases->subtitle = '为保护客户经营信息，以下均做匿名处理，仅呈现应用行业与所用产品组合。';
            $cases->content = null;
        }
        $cases->save();

        // 2. 首页 FAQ 回落到 config 默认（移除未核实表述）
        PageBlock::where('page', 'home')->where('type', 'faqs')->update(['content' => null]);

        // 3. 知识三栏目
        $knowledge = Category::where('slug', 'knowledge')->first();
        if ($knowledge) {
            $groups = [
                ['slug' => 'selection', 'name' => '选型指南', 'sort' => 10,
                 'description' => '如何认识与挑选涂料、胶粘剂与功能性助剂'],
                ['slug' => 'process', 'name' => '工艺与配方', 'sort' => 20,
                 'description' => '生产工艺、参数逻辑与配方定制过程'],
                ['slug' => 'business', 'name' => '选型与应用', 'sort' => 30,
                 'description' => '选型落地、代工合作与稳定供应参考'],
            ];
            $groupMap = [];
            foreach ($groups as $g) {
                $row = Group::firstOrNew(['category_id' => $knowledge->id, 'slug' => $g['slug']]);
                $row->name = $g['name'];
                $row->description = $g['description'];
                $row->sort = $g['sort'];
                $row->is_active = true;
                $row->save();
                $groupMap[$g['slug']] = $row->id;
            }

            // 现有文章按主题归档（仅当尚未分配时）
            $assign = [
                'how-to-choose-industrial-coatings' => 'selection',
                'adhesive-customization-process'   => 'process',
                'oem-cooperation-faq'              => 'business',
            ];
            foreach ($assign as $slug => $gSlug) {
                if (isset($groupMap[$gSlug])) {
                    Content::where('category_id', $knowledge->id)
                        ->where('slug', $slug)
                        ->whereNull('group_id')
                        ->update(['group_id' => $groupMap[$gSlug]]);
                }
            }
        }
    }

    public function down(): void
    {
        PageBlock::where('page', 'home')->where('type', 'cases')->delete();
    }
};
