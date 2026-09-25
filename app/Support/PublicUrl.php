<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Entity;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;

/**
 * 公开资源 URL 的唯一裁决层（P-STEP 17G / Public Render Contract）。
 * ------------------------------------------------------------------
 * 历史上 canonical / geo.json / schema 各自拼 URL：SeoMetaResolver 经
 * GenericUrlResolver 硬编码 `https://{domain}/{单数类型}/{slug}`（产出 /product/、
 * /service/、/article/ 等并不存在、前台返回 404 的地址），而前台 Catalog 控制器用
 * url() 拼的是 /products/、/solutions/、/knowledge/ 真实路由，两套地址长期不一致。
 *
 * PublicUrl 把「某资源在前台是否有落地页、落地页 URL 是什么」收敛到一处，供
 * SeoMetaResolver（canonical）、GeoGraphBuilder（/geo.json）、SchemaBuilder（JSON-LD）
 * 共用，保证：数据库资源 → canonical → 前台路由 → sitemap / geo / llms / search
 * 全链路同一个真实可达 URL。
 *
 * 落地页契约（与 routes/web.php、ProductController / SolutionController 对齐）：
 *   - 核心产品（Catalog core 标志）→ /products/{slug}（详情型，无尾斜杠）
 *   - 应用场景（Catalog 中的 service）→ /solutions/{slug}/（目录型，带尾斜杠）
 *   - organization / person / location / topic、非核心产品、无场景服务：无独立
 *     前台落地页 → entity() 返回 null。它们仍是 GEO 图谱 / 关系中枢的节点，但不得
 *     对外输出一个会 404 的 url。
 *
 * Host / scheme：
 *   - 真实 HTTP（PHP-FPM、artisan serve 的 cli-server、mod_php）下用当前请求 origin
 *     （url('/')）。ResolveSite 中间件已保证「请求 host ↔ 当前站点」一致，因此多站
 *     点 / 本地 IP:port / 反代 http|https 下产出的地址都与实际访问同源、真实可达。
 *   - 命令行 / 队列 / 直接程序化解析（无入站请求）时回退当前站点 domain（https），
 *     再回退 config('app.url')，保证 CLI 生成的绝对链接仍按站点区分。
 *   - 用 runningInConsole() 区分：phpunit 进程 SAPI=cli 走 domain 分支（测试稳定），
 *     artisan serve 处理请求时 SAPI=cli-server，runningInConsole() 为 false，走请求
 *     origin 分支（与生产 FPM 行为一致）。
 */
class PublicUrl
{
    /** 站点根 URL（无尾斜杠）；真实 HTTP 用请求 origin，CLI 回退站点 domain / app.url。 */
    public static function base(): string
    {
        if (! app()->runningInConsole()) {
            // 纯 origin（scheme + host），不能用 url('/')：在 /en locale 路由下
            // url('/') 会被语言前缀污染（返回 .../en），叠加 localePrefix 后产生
            // /en/en/ 重复 canonical。request()->root() 只含 host（+子目录 baseUrl），
            // 不含 locale / path，是干净的请求源。
            return rtrim(request()->root(), '/');
        }

        $site = SiteContext::currentSite();
        if ($site && trim((string) $site->domain) !== '') {
            return 'https://' . rtrim($site->domain, '/');
        }

        $configured = rtrim((string) config('app.url'), '/');

        return $configured !== '' ? $configured : rtrim(url('/'), '/');
    }

    /** 当前语言 URL 前缀（默认语言为空，en 为 /en）。 */
    private static function localePrefix(): string
    {
        $locale = LocaleContext::current();
        $prefix = LocaleRegistry::prefix($locale);

        return $prefix !== '' ? '/' . $prefix : '';
    }

    /**
     * 当前语言首页的规范公开 URL（与 CanonicalizeSlash / sitemap 冻结契约一致）。
     * 默认语言为站点根「/」；带前缀语言首页无尾斜杠（en → /en，/en/ 301→/en）。
     */
    public static function home(): string
    {
        $prefix = self::localePrefix();

        return $prefix === ''
            ? self::base() . '/'
            : self::base() . $prefix;
    }

    /**
     * 站点级 WebSite 实体的全局锚点 @id（跨语言共享，不带 locale 前缀）。
     * 所有语言页面的 WebPage.isPartOf 都引用此锚点，保证指向图谱中真实存在的 WebSite 节点。
     */
    public static function websiteAnchor(): string
    {
        return self::base() . '/#website';
    }

    /**
     * 站点级 Organization 实体的全局锚点 @id（跨语言共享，不带 locale 前缀）。
     * GEO site.organization 锚点与主体节点 same_as 都引用此锚点，与 SchemaBuilder 的
     * Organization @id 完全一致，不形成第二个组织事实。
     */
    public static function organizationAnchor(): string
    {
        return self::base() . '/#organization';
    }

    /**
     * 站内任意路径的规范绝对 URL（TD-09 通用裁决口）。
     *
     * 语义与 Laravel `url($path)` 相同（保留调用方给定的首尾 / 尾斜杠形态），
     * 唯一区别是 host / scheme 走 {@see base()}：真实 HTTP 下等于请求 origin（与 url()
     * 同源），CLI / 队列 / SubRequest 下回退当前站点规范 domain。专供 sitemap / llms /
     * robots / RSS / canonical / JSON-LD 等「声明性绝对 URL」使用，杜绝 helper 层 host 分叉。
     * 导航 href、表单 action、重定向 Location 等跟随当前 origin 的功能性 URL 仍用 url()。
     */
    public static function url(string $path = '/'): string
    {
        return self::base() . self::localePrefix() . '/' . ltrim($path, '/');
    }

    /**
     * 内容的公开 URL：/{栏目完整路径}/{slug}（与 Content::path() 同源，绝对化）。
     * 知识文章即 /knowledge/{slug}；无栏目的单页为 /{slug}（catch-all 渲染）。
     */
    public static function content(Content $content): string
    {
        return self::base() . self::localePrefix() . $content->path();
    }

    /** 核心产品详情页（详情型，无尾斜杠）：/products/{slug}。 */
    public static function product(string $slug): string
    {
        return self::base() . self::localePrefix() . '/products/' . $slug;
    }

    /** 产品系列 / 目录型页（带尾斜杠）：/products/{line}/。 */
    public static function productLine(string $slug): string
    {
        return self::base() . self::localePrefix() . '/products/' . $slug . '/';
    }

    /** 应用场景详情页（目录型，带尾斜杠）：/solutions/{slug}/。 */
    public static function solution(string $slug): string
    {
        return self::base() . self::localePrefix() . '/solutions/' . $slug . '/';
    }

    /**
     * 实体的公开落地页 URL；无独立前台页（组织 / 人物 / 地点 / 主题 / 非核心产品 /
     * 无场景服务）时返回 null——调用方据此「不输出 url」，而不是输出一个会 404 的地址。
     */
    public static function entity(Entity $entity): ?string
    {
        return match ($entity->type) {
            Entity::TYPE_PRODUCT => Catalog::isCoreProduct($entity->slug)
                ? self::product($entity->slug)
                : null,
            Entity::TYPE_SERVICE => Catalog::scene($entity->slug) !== null
                ? self::solution($entity->slug)
                : null,
            default => null,
        };
    }
}
