<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Content;
use App\Models\Fact;
use App\Models\Setting;
use App\Services\Gate\ContentGate;
use Illuminate\View\View;

/**
 * 仪表盘：内容统计、待办、GEO 健康度自检
 */
class DashboardController extends Controller
{
    public function index(ContentGate $gate): View
    {
        $stats = [
            'published' => Content::where('status', 'published')->count(),
            'draft'     => Content::where('status', 'draft')->count(),
            'archived'  => Content::where('status', 'archived')->count(),
            'article'   => Content::ofType('article')->count(),
            'page'      => Content::ofType('page')->count(),
            'product'   => Content::ofType('product')->count(),
            'category'  => Category::count(),
            'factGap'   => Fact::where('is_public', false)->count(),
        ];

        // 复核到期：已发布且 review_due 已过期或 30 天内到期
        $reviewDue = Content::where('status', 'published')
            ->whereNotNull('review_due')
            ->where('review_due', '<=', now()->addDays(30)->toDateString())
            ->orderBy('review_due')
            ->limit(10)
            ->get();

        // GEO 健康度：对已发布内容跑一遍门禁，找出历史遗留不合规项
        $gateFailures = [];
        foreach (Content::where('status', 'published')->with('category')->get() as $c) {
            $r = $gate->check($c);
            if (! $r['passed']) {
                $gateFailures[] = ['content' => $c, 'errors' => $r['errors']];
            }
        }

        $settingGaps = array_filter([
            Setting::get('icp_number') ? null : 'ICP 备案号未填写',
            Setting::get('contact_email') ? null : '业务邮箱未填写',
            Setting::get('geo_org_logo') ? null : 'Organization Logo 未上传',
        ]);

        $recentLogs = AuditLog::with('user')->latest()->limit(10)->get();

        return view('admin.dashboard', compact(
            'stats', 'reviewDue', 'gateFailures', 'settingGaps', 'recentLogs'
        ));
    }
}
