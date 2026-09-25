<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Support\PageCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * 出厂空白首页种子（P-STEP 18I / TD-70 重审）。
 * --------------------------------------------------
 * 历史行为：仅清空旧首页装修（page='home' PageBlock），首页回落 Blade 极简欢迎屏，
 * 且管理员无法通过 Admin 从零重建（违反「零代码建站」契约）。
 *
 * 现行为（首页正式进入 Page Composition）：
 *   1. 清理旧首页装修区块（page='home'，历史 data migration / 旧 demo 残留）；
 *   2. 为默认站点创建中性 is_home Page（zh-CN 权威行 + en 翻译行，home 模板、无
 *      slug、published、title 为空），**不植入任何品牌 / 行业 / 产品业务事实、不挂载
 *      区块**；
 *   3. blank 语义：清空该首页 Page 已持久化的区块，使出厂首页只渲染中性欢迎屏
 *      （site/home/_welcome），管理员可随后在后台零代码搭建。
 *
 * 幂等：updateOrCreate / 按 site 清理，重复执行安全。Demo 首页由 StructureSeeder
 * 独立往该 Page 挂载区块（Blank System ≠ Demo Site）。
 */
class BlankHomepageSeeder extends Seeder
{
    /**
     * @param  Site|null  $target  目标站点；缺省回退默认站点（geo:install 调用兼容）。
     *                            Admin 建站（SiteController::store）显式传入新站。
     */
    public function __construct(private ?Site $target = null)
    {
    }

    public function run(): void
    {
        $site = $this->target ?? Site::where('slug', Site::DEFAULT_SLUG)->first();
        if (! $site) {
            return;
        }

        // 结束时恢复 locale，避免测试进程内污染后续渲染（同 SystemPageSeeder）。
        $originalLocale = App::getLocale();

        try {
            // 1) 清理旧首页装修区块（page='home'，无 page_id 的旧模型）。
            PageBlock::where('site_id', $site->id)->where('page', 'home')->delete();

            // 2) 中性 is_home Page（zh-CN 权威行 + en 翻译行，共享 translation_group）。
            $zhHome = $this->ensureHomePage($site, 'zh-CN');
            $this->ensureHomePage($site, 'en', $zhHome->translation_group);

            // 3) blank 语义：清空首页 Page 持久化区块，出厂只显示中性欢迎屏。
            PageBlock::where('site_id', $site->id)
                ->where('page_id', $zhHome->id)->delete();
            $enHome = $zhHome->translation('en');
            if ($enHome) {
                PageBlock::where('site_id', $site->id)
                    ->where('page_id', $enHome->id)->delete();
            }
        } finally {
            App::setLocale($originalLocale);
            PageCache::flush();
        }
    }

    private function ensureHomePage(Site $site, string $locale, ?string $group = null): Page
    {
        App::setLocale($locale);

        $values = [
            'template'   => 'home',
            'slug'       => null,
            'is_home'    => true,
            'is_system'  => false,
            'system_key' => null,
            'status'     => Page::STATUS_PUBLISHED,
            'title'      => '',
        ];
        if ($group !== null) {
            $values['translation_group'] = $group;
        }

        return Page::updateOrCreate(
            ['site_id' => $site->id, 'is_home' => true, 'locale' => $locale],
            $values
        );
    }
}
