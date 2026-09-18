<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PageBlock;

/**
 * v0.9：首页新增「中部横幅」装修区块（mid_banner，sort=57，位于四大车间之后、合作方式之前）。
 * 读取 Banner·投放位置=首页中部（home_mid）且启用、有图的条目；无图时区块自身不渲染。
 * 幂等可重复执行；SQLite / PostgreSQL 兼容。
 */
return new class extends Migration
{
    public function up(): void
    {
        $block = PageBlock::firstOrNew(['page' => 'home', 'type' => 'mid_banner']);
        $block->sort = 57;
        $block->limit = 6;
        // 默认开启但无图不渲染；若此前被人工关闭则保留其选择
        if (! $block->exists) {
            $block->is_active = true;
            $block->title = null;
            $block->subtitle = null;
            $block->content = null;
        }
        $block->save();
    }

    public function down(): void
    {
        PageBlock::where('page', 'home')->where('type', 'mid_banner')->delete();
    }
};
