<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PageBlock;
use App\Models\Setting;

/**
 * v0.6（按《设计策略建议书 v1.0》）：
 *  1) 主题设置对齐 VI 标准色与中性阶（DB 值会覆盖 CSS 默认，必须同步改）
 *  2) 首页新增 scenes(场景自选)/params(参数级交付)/cooperation(合作方式)/faqs(首页FAQ)
 *  3) 区块 sort 重排为 S01–S10 叙事顺序
 *  4) problems / differentiators 默认收起（内容保留，其要点已并入参数级交付，后台可再开）
 * 幂等可重复执行；SQLite / PostgreSQL 兼容（无 after、无 hasColumn 依赖）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- 1. 主题设置对齐产品级中性默认（品牌蓝 / 辅助绿） ----------
        $theme = [
            'theme_primary'      => '#2563EB', // 品牌蓝
            'theme_primary_dark' => '#1D4ED8', // 蓝 hover
            'theme_accent'       => '#0E9F6E', // 辅助绿
            'theme_bg'           => '#FFFFFF',
            'theme_surface'      => '#FFFFFF',
            'theme_text'         => '#1F2937',
            'theme_text_muted'   => '#6B7280',
            'theme_radius'       => 8,
            'theme_container'    => 1200,
        ];
        foreach ($theme as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Setting::flush();

        // ---------- 2. 区块排序（S01–S10） ----------
        $sort = [
            'hero' => 10, 'scenes' => 20, 'capabilities' => 26, 'products' => 34,
            'params' => 44, 'stats' => 50, 'workshops' => 54, 'cooperation' => 60,
            'steps' => 64, 'knowledge' => 72, 'news' => 78, 'cta' => 84,
            'faqs' => 90, 'facts' => 94, 'problems' => 200, 'differentiators' => 201,
        ];
        foreach ($sort as $type => $order) {
            PageBlock::where('page', 'home')->where('type', $type)->update(['sort' => $order]);
        }

        // 合作流程统一为五步（旧区块存了四步条目，会覆盖默认值，这里统一写入）
        $stepItems = array_map(
            fn ($s) => ['icon' => null, 'title' => $s['title'], 'text' => $s['desc']],
            config('cooperation.steps', [])
        );
        if ($stepItems) {
            PageBlock::where('page', 'home')->where('type', 'steps')->update([
                'title'   => '五步完成从需求到批量交付',
                'content' => json_encode(['items' => $stepItems], JSON_UNESCAPED_UNICODE),
            ]);
        }
        PageBlock::where('page', 'home')->where('type', 'cta')
            ->whereIn('title', ['联系我们', '底部行动召唤'])
            ->update(['title' => '先拿技术资料，再谈方案与量产']);

        // ---------- 3. 新增区块（缺省才建，已配置过的不覆盖） ----------
        $newBlocks = [
            'scenes' => [
                'sort' => 20, 'title' => '先选应用场景，再看匹配的产品组合',
                'subtitle' => '按应用行业与工况分诊：每类场景都给出推荐产品组合，以及可复现的关键参数与工艺要点。',
            ],
            'params' => [
                'sort' => 44, 'title' => '把规格做到粘度、固含与固化时间，产线照着就能复现',
                'subtitle' => '不止提供产品，更给出标准化配比与工艺参数，多基地、批量生产都能稳定还原同一性能。',
            ],
            'cooperation' => [
                'sort' => 60, 'title' => '三种合作方式，从研发到稳定供货',
                'subtitle' => '无论装备制造、建筑工程、汽车零部件还是渠道经销商，都能找到对应的合作路径。',
            ],
            'faqs' => [
                'sort' => 90, 'title' => '合作前，你可能想先了解这些', 'subtitle' => '',
                'content' => json_encode([
                    'items' => [
                        ['title' => '可以先打样、再决定是否量产吗？', 'text' => '可以。支持先做定制研发与打样，待性能与工艺确认后再进入量产，降低试错成本。'],
                        ['title' => '是否支持按我们自己的品牌与规格生产？', 'text' => '支持。OEM/ODM 代工可按客户品牌、规格与包装形式生产，具体以沟通确认的需求为准。'],
                        ['title' => '起订量和交付周期是多少？', 'text' => '不同品类、规格与包装形式的起订量和排产周期不同，确认产品需求后会给出对应的起订量与排期评估。'],
                        ['title' => '可以供应哪些区域？', 'text' => '覆盖华东、华北、华南、华中、西南、西北、东北等全国主要工业区域。'],
                        ['title' => '产品质量与合规如何保障？', 'text' => '按标准化质量管理体系组织生产，执行标准化工艺、批批留样与溯源记录，合规资料随货齐全。'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
        foreach ($newBlocks as $type => $cfg) {
            $block = PageBlock::firstOrNew(['page' => 'home', 'type' => $type]);
            $block->sort = $cfg['sort'];
            if (! $block->exists) {
                $block->is_active = true;
                $block->limit = 6;
                $block->title = $cfg['title'];
                $block->subtitle = $cfg['subtitle'];
                $block->content = $cfg['content'] ?? null;
            }
            $block->save();
        }

        // ---------- 4. 旧区块默认收起（保留数据，后台可再开） ----------
        PageBlock::where('page', 'home')
            ->whereIn('type', ['problems', 'differentiators'])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        PageBlock::where('page', 'home')
            ->whereIn('type', ['scenes', 'params', 'cooperation', 'faqs'])
            ->delete();
    }
};
