<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\RequestScopedState;
use App\Support\SiteContext;
use App\Support\SystemAuthorization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台「当前管理站点」上下文。
 *
 * 超级管理员可通过顶部站点切换器（写入 session 的 admin_site_slug）显式选择要管理的
 * 站点；选定后，后台所有经 SiteScope 的列表 / 写入都落在该站点。普通前台 HTTP 不读取
 * 该 session（SiteResolver 仅在 admin 前缀接受显式切站），非超管也不允许借 session 切站。
 *
 * 选定站点后必须 reapply() 请求级状态（主题 / 插件 / Setting / Catalog / SeoMeta 等
 * static memo），否则同进程连续请求会读到上一站点缓存（P-STEP 14 / D.4）。
 *
 * 管理上下文允许选中 inactive / maintenance 站点（停用的站点也必须能被后台管理），
 * 因此这里不复用只查 active 的 SiteResolver::findBySlug()。
 */
class SetAdminSiteContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user
            && SystemAuthorization::canCrossSite($user)
            && $request->hasSession()
            && is_string($slug = $request->session()->get('admin_site_slug'))
            && $slug !== '') {
            $site = Site::where('slug', $slug)->first();

            if ($site) {
                SiteContext::setSite($site);
                RequestScopedState::reapply();
            }
        }

        return $next($request);
    }
}
