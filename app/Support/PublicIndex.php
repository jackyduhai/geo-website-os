<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Entity;
use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Builder;

/**
 * 公开可索引资源查询的唯一入口（P-STEP 17G / Public Render Contract）。
 * ------------------------------------------------------------------
 * 任何会「引导爬虫 / AI / 用户发现一个 URL」的公开产出——sitemap.xml、llms.txt、
 * /geo.json、RSS、站内搜索——都必须只收录满足公开渲染契约的资源：
 *
 *   1. 已发布（published 且到发布时间）；
 *   2. 归属启用栏目，或本身是无栏目的独立单页（停用栏目下的内容不得外泄）；
 *   3. 未被 SeoMeta 标记 noindex；
 *   4. 属于当前站点（BelongsToSite 的 SiteScope 自动限定）。
 *
 * 历史上各 Builder 各自为政：sitemap 过滤了 noindex，geo / llms / RSS / search
 * 没有，导致 noindex 文章、停用栏目文章仍出现在 geo.json / RSS / llms.txt 中。
 * 这里在「查询层」统一排除（而非取集合后 reject），因此对分页的站内搜索同样安全，
 * 不会破坏每页条数。
 *
 * 注意：内容 noindex 的唯一来源是 SeoMeta.content_id + noindex（contents.noindex
 * 列已在 2026_09_19_000003 迁移中移除）；实体 noindex 同理走 SeoMeta.entity_id。
 */
class PublicIndex
{
    /**
     * 可公开索引的内容查询（已含：published + not_slot 全局作用域 + 栏目启用 +
     * 非 noindex + 当前站点作用域）。调用方可继续 whereHas / 关键词 / 排序 / 分页。
     */
    public static function contentQuery(): Builder
    {
        return Content::published()
            ->where(function (Builder $q) {
                // 无栏目的独立单页可被收录；有栏目则要求栏目启用。
                $q->whereDoesntHave('category')
                  ->orWhereHas('category', fn (Builder $c) => $c->where('is_active', true));
            })
            ->whereNotIn('id', function ($sub) {
                $sub->select('content_id')
                    ->from((new SeoMeta())->getTable())
                    ->whereNotNull('content_id')
                    ->where('noindex', true)
                    ->when(SiteContext::currentSiteId(), fn ($q, $siteId) => $q->where('site_id', $siteId));
            });
    }

    /**
     * 可公开索引的实体查询（已含：published + 非 noindex + 当前站点作用域）。
     * 注意这只决定「是否进入公开 feed」；noindex 实体的前台详情页仍可直接访问（200），
     * 只是不被 sitemap / geo / llms 收录。
     */
    public static function entityQuery(): Builder
    {
        return Entity::published()
            ->whereNotIn('id', function ($sub) {
                $sub->select('entity_id')
                    ->from((new SeoMeta())->getTable())
                    ->whereNotNull('entity_id')
                    ->where('noindex', true)
                    ->when(SiteContext::currentSiteId(), fn ($q, $siteId) => $q->where('site_id', $siteId));
            });
    }

    /** 当前站点可索引实体的 slug 集合（供按 Catalog slug 投影的 Builder 做白名单过滤）。 */
    public static function indexableEntitySlugs(): array
    {
        return self::entityQuery()->pluck('slug')->all();
    }
}
