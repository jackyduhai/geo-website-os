<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Geo\FeedController;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\Geo\GeoHealthService;
use App\Services\Geo\LlmsBuilder;
use App\Services\Geo\SitemapBuilder;
use Illuminate\View\View;

/**
 * GEO 工具：产出预览、对接状态、同步日志
 */
class GeoController extends Controller
{
    public function tools(): View
    {
        return view('admin.geo.tools', [
            'feeds' => [
                ['kind' => 'sitemap', 'name' => 'sitemap.xml', 'url' => url('/sitemap.xml'), 'desc' => '全部可索引 URL，提交给搜索平台'],
                ['kind' => 'llms', 'name' => 'llms.txt', 'url' => url('/llms.txt'), 'desc' => 'AI 友好的站点事实与导航文本'],
                ['kind' => 'robots', 'name' => 'robots.txt', 'url' => url('/robots.txt'), 'desc' => '爬虫规则，26 个生成式/AI 爬虫显式放行'],
                ['kind' => 'rss', 'name' => 'feed.xml', 'url' => url('/feed.xml'), 'desc' => '最近 30 条内容 RSS'],
            ],
            'syncEnabled' => Setting::get('sync_geoflow_enabled') === '1',
            'pullEnabled' => Setting::get('sync_pull_enabled') === '1',
            'token'       => (string) Setting::get('sync_geoflow_token', ''),
        ]);
    }

    public function preview(string $kind, SitemapBuilder $sitemap, LlmsBuilder $llms): View
    {
        abort_unless(in_array($kind, ['sitemap', 'llms', 'robots', 'rss'], true), 404);

        $feed = app(FeedController::class);
        $content = match ($kind) {
            'sitemap' => $sitemap->build(),
            'llms'    => $llms->build(),
            'robots'  => $feed->robots()->getContent(),
            'rss'     => $feed->rss()->getContent(),
        };

        return view('admin.geo.preview', compact('kind', 'content'));
    }

    public function syncLogs(): View
    {
        return view('admin.geo.sync-logs', [
            'logs' => SyncLog::latest()->paginate(40),
        ]);
    }

    /**
     * GEO 语义健康看板（P-STEP 18S Capability 2）：纯只读聚合当前站点的
     * OG / 落地页 / JSON-LD / noindex / 图谱边五项检查。不写库、不可公开。
     */
    public function health(GeoHealthService $health): View
    {
        return view('admin.geo.health', [
            'report' => $health->report(),
        ]);
    }
}
