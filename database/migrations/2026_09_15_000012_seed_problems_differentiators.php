<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PageBlock;

/**
 * v0.5：首页新增「客户痛点 → 解决方案 problems(sort35)」与
 * 「为什么选择我们 differentiators(sort55)」两个可装修区块。
 * 复用 items kind（后台可增删条目、选图标）。幂等，可重复执行；SQLite/PG 兼容。
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'problems' => [
                'sort' => 35,
                'title' => '你正在被这些问题卡住吗',
                'subtitle' => '从风味、研发、量产到合规，源头工厂逐项替你解决。',
                'items' => [
                    ['icon' => 'repeat',  'title' => '风味不稳定，批次间口味漂移', 'text' => '配方标准化 + 每批检测，锁定咸甜辣与香气参数，保证连锁复购口味一致'],
                    ['icon' => 'clock',   'title' => '自研配方慢，上新跟不上', 'text' => '研发按品类快速打样，通常 2–3 轮试样即可锁定目标风味'],
                    ['icon' => 'factory', 'title' => '小厂能打样，量产却掉链', 'text' => '自有四大车间承接从样品到吨级量产，产能与交期可控'],
                    ['icon' => 'shield',  'title' => '资质合规与溯源操心', 'text' => 'SC 生产许可、批批留样与溯源记录，合规资料随货齐全'],
                ],
            ],
            'differentiators' => [
                'sort' => 55,
                'title' => '为什么选择源头工厂，而不是通货拌料',
                'subtitle' => '差异不在价格，在产品定制能力与从样品到大货的一致性。',
                'items' => [
                    ['icon' => 'sliders', 'title' => '定向研发，非通货拼配', 'text' => '按你的品类与目标规格定制配方，不做千店一味的通用拌料'],
                    ['icon' => 'factory', 'title' => '打样到量产一体', 'text' => '研发与生产线同厂协同，样品规格与大货一致，避免换厂走样'],
                    ['icon' => 'package', 'title' => '产品体系协同', 'text' => '多品类产品一站配齐，减少对接成本'],
                    ['icon' => 'shield',  'title' => '稳定批次，全程溯源', 'text' => '标准化工艺、批批留样检测，供货稳定、来源可查'],
                ],
            ],
        ];

        foreach ($defaults as $type => $cfg) {
            $block = PageBlock::firstOrNew(['page' => 'home', 'type' => $type]);
            $block->sort = $cfg['sort'];
            if (! $block->exists) {
                $block->is_active = true;
                $block->limit = $block->limit ?: 6;
                $block->title = $cfg['title'];
                $block->subtitle = $cfg['subtitle'];
                $block->content = json_encode(['items' => $cfg['items']], JSON_UNESCAPED_UNICODE);
            }
            $block->save();
        }
    }

    public function down(): void
    {
        PageBlock::where('page', 'home')
            ->whereIn('type', ['problems', 'differentiators'])
            ->delete();
    }
};
