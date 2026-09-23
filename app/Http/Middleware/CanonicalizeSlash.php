<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Support\Catalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * URL 尾斜杠规范化（全站唯一一处，系统级规则，不在各控制器里分散处理）
 * ------------------------------------------------------------------
 * 交付包 URL 规范：
 *   - 目录型页面（列表 / 栏目 / 单页）以「/」结尾：/products/、/solutions/night-market/
 *   - 详情型页面不带尾斜杠：/products/{product}、/knowledge/{article}
 *
 * 固定路由通过 ->defaults('_slash', 1) 声明自己是目录型；未声明即详情型（无斜杠）。
 * 动态路由（knowledge.channel 同时承载频道列表与扁平文章、page 统一分发器同时承载
 * 栏目页与文章页）无法在路由层静态声明，故由 resolveWantsSlash() 按实体类型判定，
 * 判定顺序与 KnowledgeController::channel / PageController::dispatch 保持一致。
 * 本中间件据此 301 到规范地址，保证地址栏、canonical、sitemap 与内链一致、避免重复页面。
 */
class CanonicalizeSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        // 仅处理前台 HTML 文档的 GET/HEAD；放过后台、接口、feed/文件、根路径、片段
        $isDocRequest = ! $request->ajax() && ! $request->wantsJson();
        $lastSeg = (string) substr(strrchr($path, '/') ?: '', 1);
        $looksLikeFile = $lastSeg !== '' && str_contains($lastSeg, '.');
        $skipPrefix = str_starts_with($path, '/admin') || str_starts_with($path, '/api');

        // Laravel 测试客户端（MakesHttpRequests）内部用 trim(url($uri),'/') 生成请求目标，
        // 会把目录型 URL 的尾斜杠剥掉，若此处仍 301 会在测试里无限自跳、页面无法到达。
        // 真实 HTTP（浏览器 / curl / PHP 内置服务器）以 REQUEST_URI 原样保留尾斜杠，不受影响；
        // 尾斜杠规则另由 CanonicalizeSlashTest 用显式构造的 Request 做等价单测锁定。
        $skipCanonical = app()->runningUnitTests();

        if (! $skipCanonical && $request->isMethodSafe() && $isDocRequest && ! $looksLikeFile && ! $skipPrefix && $path !== '/') {
            $route = $request->route();
            $routeName = $route?->getName();
            // P-STEP 18F：en 组路由名带 .en 后缀（knowledge.channel.en / page.en /
            // products.show.en），统一去掉后缀再按基础路由名判定，避免回退到 _slash 默认值。
            $baseName = preg_replace('/\.en$/', '', (string) $routeName);

            $wantSlash = null;
            if ($baseName === 'knowledge.channel') {
                // 启用中的知识频道 = 目录型；其余 slug 是扁平文章，按分发器实体判定
                $channel = (string) $route->parameter('channel');
                $wantSlash = Group::knowledgeChannels()->firstWhere('slug', $channel) !== null
                    ? true
                    : self::resolveWantsSlash('knowledge/' . $channel);
            } elseif ($baseName === 'page') {
                // 统一分发器：栏目页目录型、文章详情型
                $wantSlash = self::resolveWantsSlash($path);
            } elseif ($baseName === 'products.show') {
                // 同一路由承载产品系列（目录型）与核心产品详情（详情型），按当前站 Catalog 判定
                $wantSlash = self::resolveProductWantsSlash((string) $route->parameter('param'));
            } else {
                $wantSlash = $route?->parameter('_slash') === 1;
            }

            // 动态路由解析不到实体时不跳转（交由控制器 404，避免给垃圾路径发 301）
            if ($wantSlash === null) {
                return $next($request);
            }

            if ($target = self::targetFor($path, $wantSlash)) {
                return $this->redirect($target, $request);
            }
        }

        return $next($request);
    }

    /**
     * 动态路径的实体级斜杠判定（与 PageController::dispatch 的匹配顺序一致）：
     *   true  = 目录型（列表 / 栏目页，带尾斜杠）
     *   false = 详情型（文章 / page 单页型栏目渲染的文章，无尾斜杠）
     *   null  = 无对应实体（不做跳转，交给控制器 404，避免给垃圾路径发 301）
     */
    public static function resolveWantsSlash(string $path): ?bool
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($segments)) {
            return null;
        }

        // 1) 内容优先：末段为已发布文章 slug 时即详情型（栏目路径不符时控制器会再 301 到规范地址）
        $slug = (string) end($segments);
        $content = Content::published()->where('slug', $slug)->first();
        if ($content) {
            return false;
        }

        // 2) 栏目树逐级匹配
        $parent = null;
        $node = null;
        foreach ($segments as $seg) {
            $q = Category::where('slug', $seg)->where('is_active', true);
            $q = $parent ? $q->where('parent_id', $parent->id) : $q->whereNull('parent_id');
            $node = $q->first();
            if (! $node) {
                return null;
            }
            $parent = $node;
        }

        // 单页型栏目（type=page）直接渲染其下文章，规范地址是文章 URL（无斜杠）；
        // list / product_list / external 为目录型（带尾斜杠）。
        return $node->isSinglePage() ? false : true;
    }

    /**
     * products.show 路由的实体级斜杠判定（该路由同时承载系列页与核心产品详情）：
     *   true  = 产品系列（目录型，/products/{line}/，带尾斜杠）
     *   false = 核心产品详情（详情型，/products/{product}，无尾斜杠）
     *   null  = 当前站点 Catalog 查无该实体（不跳转，交 ProductController::resolve 404）
     *
     * 数据源是站点隔离的 Catalog（Entity 投影），不读任何全局 slug 白名单。
     */
    public static function resolveProductWantsSlash(string $param): ?bool
    {
        if (Catalog::line($param) !== null) {
            return true;
        }
        if (Catalog::isCoreProduct($param)) {
            return false;
        }

        return null;
    }

    /**
     * 纯规则：给定请求路径与「是否目录型」，返回需要 301 的目标路径；无需跳转返回 null。
     * 单独抽出以便单测（功能测试客户端会剥尾斜杠，无法在 Feature 层覆盖此规则）。
     */
    public static function targetFor(string $path, bool $wantSlash): ?string
    {
        if ($path === '/') {
            return null;
        }
        $hasSlash = str_ends_with($path, '/');

        if ($wantSlash && ! $hasSlash) {
            return $path . '/';
        }
        if (! $wantSlash && $hasSlash) {
            return rtrim($path, '/');
        }

        return null;
    }

    private function redirect(string $target, Request $request): Response
    {
        $qs = $request->getQueryString();
        if ($qs !== null && $qs !== '') {
            $target .= '?' . $qs;
        }
        // 手工拼接绝对地址：Laravel UrlGenerator 会 trim($path,'/') 剥掉尾斜杠，
        // 直接用它会让「补斜杠」301 跳回无斜杠自身，形成死循环。这里必须原样保留尾斜杠。
        $absolute = $request->getScheme() . '://' . $request->getHttpHost() . '/' . ltrim($target, '/');

        return new Response('', 301, ['Location' => $absolute, 'Cache-Control' => 'no-store']);
    }
}
