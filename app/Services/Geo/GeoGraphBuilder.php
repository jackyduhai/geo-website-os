<?php

namespace App\Services\Geo;

use App\Models\Content;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Fact;
use App\Services\Seo\SeoMetaResolver;
use App\Support\SiteContext;

/**
 * GEO 机器可读知识结构（STEP 06：/geo.json，site-scoped）
 *
 * 定位：把正式数据模型一次性暴露给 AI / 生成引擎的统一可机器读取结构。
 * 数据源边界（冻结）：
 *   - 主体与属性：entities（type 冻结枚举）+ EntityRelation（正式关系，禁止隐式推断）
 *   - 内容：published contents（title/summary 经 SeoMetaResolver 统一 Resolution）
 *   - 事实：facts 表 is_public 行（正式事实库，含来源 source 与核定 reviewed_at——
 *     本结构只引用、不复制、不创造事实）
 *   - SEO：SeoMeta / SeoMetaResolver（title / description / canonical / noindex）
 *
 * 禁止：为 GEO 重复 SEO 字段以外的旧字段、再造实体或事实、隐式 slug/名称匹配建关系。
 */
class GeoGraphBuilder
{
    public function __construct(private SeoMetaResolver $resolver) {}

    public function build(): array
    {
        $site = SiteContext::currentSite();

        return [
            '$schema'   => 'geo-os/graph/v1',
            'generated_at' => now()->toIso8601String(),
            'site'      => [
                'name'        => (string) ($site?->name ?? ''),
                'url'         => $site && $site->domain ? 'https://' . $site->domain . '/' : url('/'),
                'description' => (string) ($site?->description ?? ''),
                'logo'        => (string) ($site?->logo ?? ''),
            ],
            'facts'     => $this->facts(),
            'entities'  => $this->entities(),
            'relations' => $this->relations(),
            'contents'  => $this->contents(),
        ];
    }

    /** 正式事实库公开行：事实 + 来源 + 核定/复核时间，口径与可见页面一致 */
    protected function facts(): array
    {
        return Fact::publicRows()->map(fn ($f) => [
            'key'         => $f->key,
            'label'       => $f->label,
            'value'       => $f->value,
            'group'       => $f->group,
            'source'      => $f->source,
            'reviewed_at' => $f->reviewed_at?->toDateString(),
            'review_due'  => $f->review_due?->toDateString(),
        ])->values()->all();
    }

    /** 主体：全部 published entity，SEO 字段经 resolveEntity 统一解析 */
    protected function entities(): array
    {
        return Entity::query()
            ->where('status', Entity::STATUS_PUBLISHED)
            ->orderBy('type')->orderBy('slug')
            ->get()
            ->map(fn (Entity $e) => $this->entityNode($e))
            ->all();
    }

    protected function entityNode(Entity $e): array
    {
        $seo = $this->resolver->resolveEntity($e);

        return [
            'id'          => 'entity/' . $e->type . '/' . $e->slug,
            'type'        => $e->type,
            'slug'        => $e->slug,
            'name'        => $seo->title,
            'summary'     => $seo->description,
            'url'         => $seo->canonical,
            'noindex'     => $seo->noindex,
            'metadata'    => is_array($e->metadata) ? $e->metadata : [],
            'updated_at'  => $e->updated_at?->toIso8601String(),
        ];
    }

    /** 正式关系：仅输出两端均为当前站点主体的显式 EntityRelation */
    protected function relations(): array
    {
        $siteId = SiteContext::currentSite()?->id;

        return EntityRelation::query()
            ->where('site_id', $siteId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (EntityRelation $r) => [
                'from'          => $this->entityRef($r->from_entity_id),
                'to'            => $this->entityRef($r->to_entity_id),
                'relation_type' => $r->relation_type,
                'sort_order'    => $r->sort_order,
            ])
            ->filter(fn ($r) => $r['from'] !== null && $r['to'] !== null)
            ->values()
            ->all();
    }

    protected function entityRef(int $entityId): ?string
    {
        $e = Entity::query()->where('status', Entity::STATUS_PUBLISHED)->find($entityId);

        return $e ? 'entity/' . $e->type . '/' . $e->slug : null;
    }

    /** 内容：published content，标题/摘要走统一 SEO Resolution（SeoMeta → Content → Site） */
    protected function contents(): array
    {
        return Content::published()
            ->orderByDesc('published_at')
            ->get()
            ->map(function (Content $c) {
                $seo = $this->resolver->resolveContent($c);

                return [
                    'id'           => 'content/' . $c->type . '/' . $c->slug,
                    'type'         => $c->type,
                    'slug'         => $c->slug,
                    'title'        => $seo->title,
                    'description'  => $seo->description,
                    'url'          => $c->url(),
                    'canonical'    => $seo->canonical,
                    'noindex'      => $seo->noindex,
                    'published_at' => $c->published_at?->toIso8601String(),
                    'updated_at'   => $c->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
