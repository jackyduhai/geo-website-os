<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 首页整体装修：补齐 stats/capabilities/workshops/steps 区块，
 * 写入默认条目（JSON 存 content），并按注册表统一排序。幂等，可重复执行。
 *
 * 开源化修正（P-STEP 02，原业务文案已中性化）：这里属于 Core 迁移层，
 * 只能携带通用缺省文案；业务文案属 Demo 数据，仅可由 DemoSeeder / 后台写入。
 * 未修改各字段结构，仅替换字符串值，不产生升级兼容风险。
 */
return new class extends Migration
{
    public function up()
    {
        $defaults = [
            'stats' => ['sort' => 20, 'title' => null, 'subtitle' => null, 'items' => []],
            'capabilities' => [
                'sort' => 30,
                'title' => '专注产品研发与生产的一体化源头工厂',
                'subtitle' => null,
                'items' => [
                    ['icon' => 'sliders', 'title' => '配方定制', 'text' => '按客户需求定向研发产品，非通货拼配'],
                    ['icon' => 'factory', 'title' => 'OEM / ODM 代工', 'text' => '从配方到成品的一体化代工链路'],
                    ['icon' => 'repeat', 'title' => '打样到量产', 'text' => '需求对接 → 配方打样 → 试样确认 → 批量交付'],
                ],
            ],
            'products'  => ['sort' => 40],
            'workshops' => [
                'sort' => 50,
                'title' => '生产线一体协同，从研发到量产闭环',
                'subtitle' => '各生产环节全面投产，支撑从配方到成品的一体化交付。',
                'items' => [
                    ['icon' => 'leaf', 'title' => '原料处理车间'],
                    ['icon' => 'drumstick', 'title' => '预制加工车间'],
                    ['icon' => 'jar', 'title' => '固体成品车间'],
                    ['icon' => 'flask', 'title' => '风味车间'],
                ],
            ],
            'steps' => [
                'sort' => 60,
                'title' => '四步完成从需求到批量交付',
                'subtitle' => null,
                'items' => [
                    ['title' => '需求对接', 'text' => '明确品类、规格方向、包装与用量，建立需求档案'],
                    ['title' => '配方打样', 'text' => '研发按需求调配，提供首轮样品与规格说明'],
                    ['title' => '试样确认', 'text' => '通常 2–3 轮试样，锁定配方与工艺参数'],
                    ['title' => '批量交付', 'text' => '各生产线排产，稳定供货并持续品质跟进'],
                ],
            ],
            'facts'     => ['sort' => 70],
            'knowledge' => ['sort' => 80],
            'news'      => ['sort' => 90],
            'cta'       => ['sort' => 100],
            'hero'      => ['sort' => 10],
        ];

        foreach ($defaults as $type => $cfg) {
            $existing = DB::table('page_blocks')->where('page', 'home')->where('type', $type)->first();

            if ($existing) {
                DB::table('page_blocks')->where('id', $existing->id)->update([
                    'sort' => $cfg['sort'],
                ]);
            } else {
                DB::table('page_blocks')->insert([
                    'page' => 'home',
                    'type' => $type,
                    'sort' => $cfg['sort'],
                    'is_active' => true,
                    'limit' => 6,
                    'title' => $cfg['title'] ?? null,
                    'subtitle' => $cfg['subtitle'] ?? null,
                    'content' => !empty($cfg['items']) ? json_encode(['items' => $cfg['items']], JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down()
    {
        DB::table('page_blocks')
            ->where('page', 'home')
            ->whereIn('type', ['stats', 'capabilities', 'workshops', 'steps'])
            ->delete();
    }
};
