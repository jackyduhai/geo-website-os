<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Support\PageCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 出厂中性首页（Blank System）种子。
 *
 * 历史 data migration 在每次 fresh migrate 时会为默认站点播种一套带行业倾向的
 * Example 首页装修区块（page_blocks，历史迁移不可改动、也不应删除）。但开源产品的
 * 出厂状态必须是行业中立的空站，因此 geo:install 在迁移与默认设置之后调用本种子，
 * 清空默认站点首页的垂直装修区块，让首页回落到 home.blade 的行业中立 Blank 欢迎屏。
 *
 * 行业 Example 装修（制造演示站的完整区块与文案）只在显式 db:seed 时由
 * DemoSeeder → StructureSeeder 重新构建，与出厂模板彻底分离（Blank System ≠ Demo Site）。
 *
 * 幂等：重复执行安全；仅清理 page='home' 的装修区块，不触碰栏目 / 内容 / 实体 / 设置。
 */
class BlankHomepageSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::where('slug', Site::DEFAULT_SLUG)->first();
        if (! $site) {
            return;
        }

        DB::table('page_blocks')
            ->where('site_id', $site->id)
            ->where('page', 'home')
            ->delete();

        PageCache::flush();
    }
}
