<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Category;
use App\Models\Group;
use App\Models\PageBlock;

/**
 * v0.9.11 系统对齐：
 *  1) 移除 v0.5 遗留、已并入「参数级交付」的两个停用首页装修区块
 *     problems / differentiators（前台早已不渲染，后台类型表也不再注册，这里清掉残留行）。
 *  2) 知识中心改由 groups 表数据驱动后，软停用与锁定 IA 重复、且零文章的三个旧空栏目
 *     craft/application/industry（仅在确无文章时停用，可逆；后台重新启用或新建即恢复显示）。
 * 幂等，SQLite/PG 兼容。
 */
return new class extends Migration
{
    public function up(): void
    {
        PageBlock::where('page', 'home')
            ->whereIn('type', ['problems', 'differentiators'])
            ->delete();

        $knowledge = Category::where('slug', 'knowledge')->first();
        if ($knowledge) {
            Group::where('category_id', $knowledge->id)
                ->whereIn('slug', ['craft', 'application', 'industry'])
                ->whereDoesntHave('contents')
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        // 不反向复活遗留区块；旧分组如需恢复由后台手动启用，避免回滚把空栏目重新挂到导航。
    }
};
