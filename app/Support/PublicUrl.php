<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Entity;

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
            return rtrim(url('/'), '/');
        }

        $site = SiteContext::currentSite();
        if ($site && trim((string) $site->domain) !== '') {
            return 'https://' . rtrim($site->domain, '/');
        }

        $configured = rtrim((string) config('app.url'), '/');

        return $configured !== '' ? $configured : rtrim(url('/'), '/');
    }

    /** 首页（带尾斜杠，与首页 canonical 冻结契约一致）。 */
    public static function home(): string
    {
        return self::base() . '/';
    }

    /**
     * 内容的公开 URL：/{栏目完整路径}/{slug}（与 Content::path() 同源，绝对化）。
     * 知识文章即 /knowledge/{slug}；无栏目的单页为 /{slug}（catch-all 渲染）。
     */
    public static function content(Content $content): string
    {
        return self::base() . $content->path();
    }

    /**
     * 实体的公开落地页 URL；无独立前台页（组织 / 人物 / 地点 / 主题 / 非核心产品 /
     * 无场景服务）时返回 null——调用方据此「不输出 url」，而不是输出一个会 404 的地址。
     */
    public static function entity(Entity $entity): ?string
    {
        return match ($entity->type) {
            Entity::TYPE_PRODUCT => Catalog::isCoreProduct($entity->slug)
                ? self::base() . '/products/' . $entity->slug
                : null,
            Entity::TYPE_SERVICE => Catalog::scene($entity->slug) !== null
                ? self::base() . '/solutions/' . $entity->slug . '/'
                : null,
            default => null,
        };
    }
}
