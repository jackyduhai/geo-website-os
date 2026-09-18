<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 首页「产品体系」区块改为 simple 型：
 * 五大产品体系卡片由结构化产品数据（产品中心单一事实源）驱动，
 * 区块本身不再支持「来源栏目 / 条数 / 手动选稿」（此前这些控件在前台不生效，属死控件）。
 * 清理历史残留的 category_id 与手动选稿，避免后台展示与前台行为不一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        $blocks = DB::table('page_blocks')->where('type', 'products')->get();
        foreach ($blocks as $b) {
            $config = json_decode((string) ($b->content ?? '{}'), true) ?: [];
            unset($config['ids'], $config['category_id'], $config['limit']);
            DB::table('page_blocks')->where('id', $b->id)->update([
                'category_id' => null,
                'content'     => json_encode($config, JSON_UNESCAPED_UNICODE),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 无需回滚历史死控件配置
    }
};
