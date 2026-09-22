<?php

namespace App\Support\Http;

use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台「本站上下文」内部子请求（P-STEP 17E）。
 *
 * 主题预览 / 插件路由实时探测需要在后台请求内，以**当前管理站点**的身份真实走一遍
 * 前台 HTTP 栈（ResolveSite → EnsurePluginEnabled / 主题解析 → 控制器），从而：
 *   - 插件：得到「本站启用应 200 / 他站或停用应 404」的真实运行时判定，而不是只看开关；
 *   - 主题：配合 ThemeManager::preview() 在不改激活态的前提下渲染指定主题首页。
 *
 * 子请求按当前站点 domain 构造 Host（domain 为空时用 localhost → 回退默认站），
 * 并在返回后恢复当前后台请求的 SiteContext / 请求级状态（前台 ResolveSite 的 finally
 * 会 clear 上下文）。JSON 探测带 Accept: application/json、HTML 预览带 X-Requested-With，
 * 二者都使整页缓存 BYPASS，绝不把探测 / 预览响应写入页面缓存。
 */
class SubRequest
{
    /**
     * 以当前管理站点身份发起一次前台 GET 子请求。
     *
     * @param  string  $uri  前台相对路径（如某插件的 plugins/{slug}/ping，或站点首页 /）
     * @param  bool    $json true=JSON 探测（Accept: application/json）；false=HTML 预览（ajax 绕缓存）
     */
    public static function getOnCurrentSite(string $uri, bool $json = true): Response
    {
        $site = SiteContext::currentSite();
        $host = ($site && trim((string) $site->domain) !== '') ? $site->domain : 'localhost';
        $url = 'http://' . $host . '/' . ltrim($uri, '/');

        $request = Request::create($url, 'GET');
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        } else {
            // HTML 预览：标记为 ajax，使 CachePage 跳过读取 / 写入整页缓存。
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        $response = app()->handle($request);

        // 前台子请求的 ResolveSite::finally 会 clear SiteContext；恢复后台当前站点上下文，
        // 使后续后台视图渲染仍处于正确的管理站点（per-site 隔离不被内部探测打乱）。
        if ($site) {
            SiteContext::setSite($site);
            RequestScopedState::reapply();
        }

        return $response;
    }
}
