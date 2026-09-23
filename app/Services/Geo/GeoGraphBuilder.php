<?php

namespace App\Services\Geo;

use App\Models\Content;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Fact;
use App\Models\Setting;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicIndex;
use App\Support\PublicUrl;
use App\Support\SiteContext;

/**
 * GEO 机器可读知识结构（STEP 06：/geo.json，site-scoped）
 *
 * 定位：把正式数据模型一次性暴露给 AI / 生成引擎的统一可机器读取结构。
 * 数据源边界（冻结）：
 *   - 主体与属性：entities（type 冻结枚举）+ EntityRelation（正式关系，禁止隐式推断）
 *   - 内容：公开可索引 contents（{@see PublicIndex}：published + 启用栏目 + 非 noindex），
 *     title/summary 经 SeoMetaResolver 统一 Resolution
 *   - 事实：facts 表 is_public 行（正式事实库，含来源 source 与核定 reviewed_at——
 *     本结构只引用、不复制、不创造事实）
 *   - SEO：SeoMeta / SeoMetaResolver（title / description / noindex）
 *
 * Public Render Contract（P-STEP 17G）：
 *   - 节点 url 一律由 {@see PublicUrl} 裁决，只有存在真实前台落地页的实体
 *     （核心产品 / 场景服务）才输出 url；组织 / 人物 / 地点 / 主题、非核心产品、
 *     无场景服务仍是图谱节点，但不输出会 404 的 url。
 *   - noindex 内容 / 实体、draft、停用栏目内容、他站资源一律不进入本图。
 *
 * 禁止：为 GEO 重复 SEO 字段以外的旧字段、再造实体或事实、隐式 slug/名称匹配建关系。
 */
class GeoGraphBuilder
{
    public function __construct(private SeoMetaResolver $resolver) {}

    public function build(): array
    {
        $site = SiteContext::currentSite();

        $company = Catalog::company();

        return [
            '$schema'   => 'geo-os/graph/v1',
            'generated_at' => now()->toIso8601String(),
            'site'      => [
                'name'        => $this->siteOrgName(),
                'url'         => PublicUrl::home(),
                'description' => (string) ($company['summary'] ?? $site?->description ?? ''),
                'logo'        => (string) ($site?->logo ?? ''),
                // TD-07 单一事实源：站点主体组织锚点与 SchemaBuilder 的
                // {base}/#organization 同一 @id（Site 聚合为唯一事实源）。GEO 中
                // 代表主体的 organization 实体节点通过 same_as 指回此锚点，不再形成
                // 与 Schema 互不相干的第二个组织对象。
                'organization' => [
                    '@id'  => PublicUrl::home() . '#organization',
                    'name' => $this->siteOrgName(),
                    'url'  => PublicUrl::home(),
                ],
            ],
            'facts'     => $this->facts(),
            'entities'  => $this->entities(),
            'relations' => $this->relations(),
            'contents'  => $this->contents(),
        ];
    }

    /**
     * 站点主体组织名（Site 聚合为唯一事实源，口径与 SchemaBuilder::orgName 一致）：
     * 默认语言取 geo_org_name、其他语言取 geo_org_en_name，缺省回退 Site.name。
     * 不读目录 organization Entity 的 name（它只是挂边节点，非主体事实源，见 TD-07）。
     */
    protected function siteOrgName(): string
    {
        $site = SiteContext::currentSite();

        if (LocaleContext::current() !== LocaleRegistry::default()) {
            return (string) (Setting::get('geo_org_en_name') ?: $site?->name ?? '');
        }

        return (string) (Setting::get('geo_org_name') ?: $site?->name ?? '');
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

    /**
     * 主体：公开可索引（published + 非 noindex）的实体，SEO 字段经 resolveEntity
     * 统一解析（批量预载消除 N+1）。无前台落地页的实体仍作为图谱 / 关系节点保留，
     * 只是不输出 url。
     */
    protected function entities(): array
    {
        $entities = PublicIndex::entityQuery()
            ->forLocale(LocaleContext::current())
            ->orderBy('type')->orderBy('slug')
            ->get();

        $this->resolver->preloadEntitySeoMetas($entities);
        $this->resolver->preloadMediaPaths($entities->map(
            fn (Entity $e) => is_array($e->metadata) ? ($e->metadata['og_image'] ?? null) : null
        ));

        return $entities
            ->map(fn (Entity $e) => $this->entityNode($e))
            ->all();
    }

    protected function entityNode(Entity $e): array
    {
        $seo = $this->resolver->resolveEntity($e);

        $node = [
            'id'          => 'entity/' . $e->type . '/' . $e->slug,
            'type'        => $e->type,
            'slug'        => $e->slug,
            'name'        => $seo->title,
            'summary'     => $seo->description,
            'noindex'     => false,
            'metadata'    => is_array($e->metadata) ? $e->metadata : [],
            'updated_at'  => $e->updated_at?->toIso8601String(),
        ];

        // 仅核心产品 / 场景服务有真实前台页；其余实体无 url，避免对外给出 404 地址。
        $url = PublicUrl::entity($e);
        if ($url !== null) {
            $node['url'] = $url;
        }

        // TD-07：被标记为站点主体的 organization 实体（Demo 目录中的主体公司节点，
        // 供 produces/offers 关系边挂载）通过 same_as 锚定 Site 聚合的唯一主体
        // {base}/#organization，节点 id 保持 entity/organization/{slug} 不断关系边，
        // 但不再是与 Schema 互不相干的第二个组织事实。
        $metadata = is_array($e->metadata) ? $e->metadata : [];
        if ($e->type === Entity::TYPE_ORGANIZATION
            && ! empty($metadata['is_site_organization'])) {
            $node['same_as'] = [PublicUrl::home() . '#organization'];
        }

        return $node;
    }

    /**
     * 正式关系：仅输出两端均为当前站点「公开可索引」主体的显式 EntityRelation
     * （批量取两端实体，消除 N+1）。任一端 draft / noindex / 他站则不输出该边。
     */
    protected function relations(): array
    {
        $siteId = SiteContext::currentSite()?->id;
        $locale = LocaleContext::current();
        $defaultLocale = LocaleRegistry::default();

        $relations = EntityRelation::query()
            ->where('site_id', $siteId)
            ->orderBy('sort_order')
            ->get();

        $entityIds = $relations->flatMap(fn ($r) => [$r->from_entity_id, $r->to_entity_id])->unique();

        // 边权威 id 指向默认语言行：取默认语言端（公开可索引）用于匹配。
        $zh = PublicIndex::entityQuery()
            ->forLocale($defaultLocale)
            ->whereIn('id', $entityIds)
            ->get()
            ->keyBy('id');

        // 当前语言端：按 translation_group 反查（默认语言时即 zh 本身）。
        $current = $locale === $defaultLocale ? $zh : PublicIndex::entityQuery()
            ->forLocale($locale)
            ->whereIn('translation_group', $zh->pluck('translation_group')->unique())
            ->get();
        $currentByGroup = $current->keyBy('translation_group');

        return $relations
            ->map(function (EntityRelation $r) use ($zh, $currentByGroup, $locale, $defaultLocale) {
                $fromZh = $zh->get($r->from_entity_id);
                $toZh   = $zh->get($r->to_entity_id);
                if (! $fromZh || ! $toZh) {
                    return null; // 默认语言端不公开
                }

                $from = $locale === $defaultLocale
                    ? $fromZh
                    : $currentByGroup->get($fromZh->translation_group);
                $to = $locale === $defaultLocale
                    ? $toZh
                    : $currentByGroup->get($toZh->translation_group);
                if (! $from || ! $to) {
                    return null; // 当前语言缺译 → 不输出该边
                }

                return [
                    'from'          => 'entity/' . $from->type . '/' . $from->slug,
                    'to'            => 'entity/' . $to->type . '/' . $to->slug,
                    'relation_type' => $r->relation_type,
                    'sort_order'    => $r->sort_order,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * 内容：公开可索引 content（published + 启用栏目 / 无栏目单页 + 非 noindex），
     * 标题 / 摘要走统一 SEO Resolution（批量预载消除 N+1）。url 与 canonical 统一由
     * PublicUrl 裁决（知识文章为 /knowledge/{slug}），并补内容图（OG 图优先于封面）。
     */
    protected function contents(): array
    {
        $contents = PublicIndex::contentQuery()
            ->forLocale(LocaleContext::current())
            ->orderByDesc('published_at')
            ->get();

        $this->resolver->preloadContentSeoMetas($contents);
        $this->resolver->preloadMediaPaths($contents->flatMap(
            fn (Content $c) => [$c->og_image_id, $c->cover_id]
        ));
        $contents->load(['category.parent', 'ogImage', 'cover']);

        return $contents
            ->map(function (Content $c) {
                $seo = $this->resolver->resolveContent($c);
                $url = PublicUrl::content($c);

                $node = [
                    'id'           => 'content/' . $c->type . '/' . $c->slug,
                    'type'         => $c->type,
                    'slug'         => $c->slug,
                    'title'        => $seo->title,
                    'description'  => $seo->description,
                    'url'          => $url,
                    'canonical'    => $url,
                    'noindex'      => false,
                    'published_at' => $c->published_at?->toIso8601String(),
                    'updated_at'   => $c->updated_at?->toIso8601String(),
                ];

                // 内容图只取内容自身的 OG 图 / 封面，缺省则不输出（不用站点 logo 冒充）。
                $image = $c->ogImage?->url() ?? $c->cover?->url();
                if ($image) {
                    $node['image'] = $image;
                }

                return $node;
            })
            ->values()
            ->all();
    }
}
