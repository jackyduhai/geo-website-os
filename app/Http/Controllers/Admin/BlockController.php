<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\Localization\LocaleRegistry;
use App\Support\SiteContext;

/**
 * 旧「首页整体装修」入口（P-STEP 18I / TD-70）。
 * --------------------------------------------------
 * 首页已正式进入 Page Composition（is_home Page + 注册通用 block），旧装修器
 * （page='home' 区块、hero mode、slides 上传等）退出运行时主链。本控制器仅保留
 * 菜单入口的兼容重定向：把 /admin/blocks 转到默认语言 is_home Page 的
 * Page Composition Manager（composer），不再提供旧 update / 上传逻辑。
 */
class BlockController extends Controller
{
    public function index()
    {
        $siteId = SiteContext::currentSiteId();

        $home = Page::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('is_home', true)
            ->where('locale', LocaleRegistry::default())
            ->first();

        if ($home) {
            return redirect()->route('admin.pages.composer', $home);
        }

        // 异常兜底：home Page 未初始化（不应发生，安装链路会创建），不抛 500。
        return redirect()->route('admin.pages.index')
            ->with('error', '首页尚未初始化，请重新运行 geo:install 或检查系统页面。');
    }
}
