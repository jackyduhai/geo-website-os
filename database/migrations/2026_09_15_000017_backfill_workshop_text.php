<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\Facts;

return new class extends Migration
{
    /**
     * v0.9.7：四大车间区块升级为「图标+标题+说明」条目型，早期只存了标题。
     * 按车间名回填说明（来源仍为核定 Facts，不新增数字），避免前台说明缺失、后台预填不全。
     */
    public function up(): void
    {
        $descByName = [];
        foreach (Facts::workshops() as $w) {
            $descByName[$w['name']] = $w['desc'];
        }

        $block = DB::table('page_blocks')->where('page', 'home')->where('type', 'workshops')->first();
        if (! $block) {
            return;
        }
        $cfg = json_decode((string) $block->content, true);
        if (! is_array($cfg['items'] ?? null)) {
            return;
        }
        $changed = false;
        foreach ($cfg['items'] as &$it) {
            if (empty($it['text']) && isset($it['title'], $descByName[$it['title']])) {
                $it['text'] = $descByName[$it['title']];
                $changed = true;
            }
        }
        unset($it);
        if ($changed) {
            DB::table('page_blocks')->where('id', $block->id)
                ->update(['content' => json_encode($cfg, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // 说明回填无需回滚
    }
};
