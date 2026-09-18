<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PageBlock;

/**
 * 首页整体装修：补齐 stats/capabilities/workshops/steps 区块，
 * 写入默认条目（JSON 存 content），并按注册表统一排序。幂等，可重复执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'stats' => ['sort' => 20, 'title' => null, 'subtitle' => null, 'items' => []],
            'capabilities' => [
                'sort' => 30,
                'title' => '专注中式Sample Snack风味的调味研发与生产源头工厂',
                'subtitle' => null,
                'items' => [
                    ['icon' => 'sliders', 'title' => '配方定制', 'text' => '按餐饮品牌需求定向研发风味，非通货拼配'],
                    ['icon' => 'factory', 'title' => 'OEM / ODM 代工', 'text' => '从Sample Marinade、Sample Breading撒料到调理鸡肉的完整代工链路'],
                    ['icon' => 'repeat', 'title' => '打样到量产', 'text' => '需求对接 → 配方打样 → 试样确认 → 批量交付'],
                ],
            ],
            'products'  => ['sort' => 40],
            'workshops' => [
                'sort' => 50,
                'title' => '四大车间一体协同，风味从研发到量产闭环',
                'subtitle' => 'Sample Spice粉碎、预制调理肉、固体调味料、食用香精四大车间全面投产，支撑从配方到成品的一体化交付。',
                'items' => [
                    ['icon' => 'leaf', 'title' => 'Sample Spice粉碎车间'],
                    ['icon' => 'drumstick', 'title' => '预制调理肉车间'],
                    ['icon' => 'jar', 'title' => '固体调味料车间'],
                    ['icon' => 'flask', 'title' => '食用香精车间'],
                ],
            ],
            'steps' => [
                'sort' => 60,
                'title' => '四步完成从需求到批量交付',
                'subtitle' => null,
                'items' => [
                    ['title' => '需求对接', 'text' => '明确品类、风味方向、包装与用量，建立需求档案'],
                    ['title' => '配方打样', 'text' => '研发按需求调配，提供首轮样品与风味说明'],
                    ['title' => '试样确认', 'text' => '通常 2–3 轮试样，锁定风味与工艺参数'],
                    ['title' => '批量交付', 'text' => '四大车间排产，稳定供货并持续品质跟进'],
                ],
            ],
            'facts'     => ['sort' => 70],
            'knowledge' => ['sort' => 80],
            'news'      => ['sort' => 90],
            'cta'       => ['sort' => 100],
            'hero'      => ['sort' => 10],
        ];

        foreach ($defaults as $type => $cfg) {
            $block = PageBlock::firstOrNew(['page' => 'home', 'type' => $type]);
            $block->sort = $cfg['sort'];
            if (! $block->exists) {
                $block->is_active = true;
                $block->limit = $block->limit ?: 6;
                $block->title = $cfg['title'] ?? null;
                $block->subtitle = $cfg['subtitle'] ?? null;
            }
            if (! empty($cfg['items']) && blank($block->content)) {
                $block->content = json_encode(['items' => $cfg['items']], JSON_UNESCAPED_UNICODE);
            }
            $block->save();
        }
    }

    public function down(): void
    {
        PageBlock::where('page', 'home')
            ->whereIn('type', ['stats', 'capabilities', 'workshops', 'steps'])
            ->delete();
    }
};
