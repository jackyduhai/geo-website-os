<?php

namespace App\Services\Geo;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Catalog;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicIndex;
use App\Support\PublicUrl;
use App\Support\SiteContext;

/**
 * GEO Health —— 语义健康只读聚合层（P-STEP 18S Capability 2）。
 * ------------------------------------------------------------------
 * 定位：回答「当前 Site 的公开内容 / 实体 / SEO·GEO 输出能否被搜索引擎与 AI 正确
 * 理解、抓取、引用」。它不是基础设施 /health（见 Api\HealthController），也不是
 * 内容创作门禁（见 Gate\ContentGate，由 Dashboard 直接调用展示）。
 *
 * 数据流（纯只读）：
 *   DB(Entity/Content/EntityRelation/ContentEntity/SeoMeta)
 *     → 既有 PublicIndex / SeoMetaResolver / PublicUrl / SchemaBuilder / Catalog
 *     → 本类聚合状态（PASS/WARNING/FAIL/N/A + counts + affected）
 *     → Admin View。
 *
 * 红线（本类遵守）：
 *  - 无写库 / 无持久化 / 无缓存表 / 无 migration；每次请求实时聚合。
 *  - 不复制既有规则：OG/noindex 走 SeoMetaResolver，公开口径走 PublicIndex，
 *    落地页裁决走 PublicUrl，边口径与 GeoGraphBuilder 一致（两端均公开）。
 *  - 不做 0–100 综合分；只给状态 / 计数 / affected。
 *  - 全部按当前 SiteContext + LocaleContext 限定，不串站 / 不串语言。
 *  - Blank 空站（0 公开实体 + 0 公开内容）合法 N/A，不报假 Fail。
 */
class GeoHealthService
{
    public function __construct(
        private readonly SeoMetaResolver $seo
    ) {}

    /**
     * 生成当前站点的完整健康报告（只读）。
     *
     * @return array{
     *   site: array{id:int,name:string,locale:string},
     *   overall: string,
     *   blank: bool,
     *   checks: array<string,array{key:string,label:string,status:string,counts:array,affected:array}>
     * }
     */
    public function report(): array
    {
        $site = SiteContext::currentSite();
        $locale = LocaleContext::current() ?: LocaleRegistry::default();

        // 公开资源集合（PublicIndex 统一公开口径：published + 非 noindex + 栏目启用 + 当前站）。
        $entities = PublicIndex::entityQuery()->forLocale($locale)->get();
        $contents = PublicIndex::contentQuery()->forLocale($locale)->get();

        // 批量预载，消除 N+1（与 GeoGraphBuilder 同法）。
        $this->seo->preloadEntitySeoMetas($entities);
        $this->seo->preloadContentSeoMetas($contents);
        $this->seo->preloadMediaPaths(
            $entities->flatMap(fn (Entity $e) => [
                is_array($e->metadata) ? ($e->metadata['og_image'] ?? null) : null,
            ])->merge($contents->flatMap(fn (Content $c) => [$c->og_image_id, $c->cover_id]))
        );

        $checks = [
            'missing_og'   => $this->checkMissingOg($site, $locale, $entities, $contents),
            'public_url'   => $this->checkPublicUrl($entities),
            'jsonld'       => $this->checkJsonLd($entities, $contents),
            'noindex'      => $this->checkNoindex($site, $locale),
            'edges'        => $this->checkEdges($site, $locale, $entities),
        ];

        $applicable = array_filter($checks, fn ($c) => $c['status'] !== 'N/A');
        $blank = $entities->isEmpty() && $contents->isEmpty();

        $overall = $blank
            ? 'N/A'
            : (in_array('FAIL', array_column($checks, 'status'), true)
                ? 'FAIL'
                : (in_array('WARNING', array_column($checks, 'status'), true)
                    ? 'WARNING'
                    : 'PASS'));

        return [
            'site'    => ['id' => (int) $site->id, 'name' => (string) $site->name, 'locale' => $locale],
            'overall' => $overall,
            'blank'   => $blank,
            'checks'  => $checks,
        ];
    }

    // ---------------------------------------------------------------
    // Check A — Missing OG（区分 explicit / fallback / missing）
    // ---------------------------------------------------------------
    // OG 在前台永远非空输出（resolver 有完整 fallback），因此本检查不报"输出缺 og:"，
    // 而报"最终依赖兜底、无显式 SEO 覆盖 / 无独立 OG 图 / 无 OG 描述"。fallback 合法 →
    // 永远 WARNING（人工补全提示），绝不造假 FAIL。
    private function checkMissingOg(Site $site, string $locale, $entities, $contents): array
    {
        $affected = [];
        $explicit = 0;
        $imageFallback = 0;
        $missingDesc = 0;

        $explicitEntityIds = SeoMeta::where('site_id', $site->id)->where('locale', $locale)
            ->whereNotNull('entity_id')->pluck('entity_id')->all();
        $explicitContentIds = SeoMeta::where('site_id', $site->id)->where('locale', $locale)
            ->whereNotNull('content_id')->pluck('content_id')->all();

        $fallbackImage = (string) ($site->logo ?? '');

        foreach ($entities as $e) {
            $seo = $this->seo->resolveEntity($e);
            $hasRow = in_array($e->id, $explicitEntityIds, true);
            if ($hasRow) {
                $explicit++;
            }
            $img = (string) ($seo->ogImage ?? '');
            $relayed = ! $hasRow
                || ($img !== '' && $img === $fallbackImage)
                || ($seo->ogDescription === null || trim((string) $seo->ogDescription) === '');

            if (! $hasRow) {
                $affected[] = ['type' => 'entity', 'id' => $e->id, 'label' => $e->name, 'reason' => '未做 SEO 覆盖（当前使用站点兜底 OG）', 'url' => route('admin.seo-metas.index', ['entity_id' => $e->id])];
            } elseif ($img === '' || $img === $fallbackImage) {
                $imageFallback++;
                $affected[] = ['type' => 'entity', 'id' => $e->id, 'label' => $e->name, 'reason' => 'OG 图回退站点 logo（无独立分享图）', 'url' => route('admin.seo-metas.index', ['entity_id' => $e->id])];
            }
            if ($seo->ogDescription === null || trim((string) $seo->ogDescription) === '') {
                $missingDesc++;
                $affected[] = ['type' => 'entity', 'id' => $e->id, 'label' => $e->name, 'reason' => '缺少 OG 描述', 'url' => route('admin.seo-metas.index', ['entity_id' => $e->id])];
            }
        }

        foreach ($contents as $c) {
            $seo = $this->seo->resolveContent($c);
            $hasRow = in_array($c->id, $explicitContentIds, true);
            if ($hasRow) {
                $explicit++;
            }
            if (! $hasRow) {
                $affected[] = ['type' => 'content', 'id' => $c->id, 'label' => $c->title, 'reason' => '未做 SEO 覆盖（当前使用站点兜底 OG）', 'url' => route('admin.seo-metas.index', ['content_id' => $c->id])];
            } elseif ((string) ($seo->ogImage ?? '') === '') {
                $imageFallback++;
                $affected[] = ['type' => 'content', 'id' => $c->id, 'label' => $c->title, 'reason' => 'OG 图回退站点 logo（无独立分享图）', 'url' => route('admin.seo-metas.index', ['content_id' => $c->id])];
            }
            if ($seo->ogDescription === null || trim((string) $seo->ogDescription) === '') {
                $missingDesc++;
            }
        }

        $total = $entities->count() + $contents->count();
        $status = $total === 0 ? 'N/A' : ($affected === [] ? 'PASS' : 'WARNING');

        return [
            'key' => 'missing_og', 'label' => 'Missing OG',
            'status' => $status,
            'counts' => ['public_total' => $total, 'explicit' => $explicit, 'image_fallback' => $imageFallback, 'missing_description' => $missingDesc],
            'affected' => array_values($affected),
        ];
    }

    // ---------------------------------------------------------------
    // Check B — Published Product Without Public URL
    // ---------------------------------------------------------------
    // 对公开产品实体调用 PublicUrl::entity()（真实前台路由裁决，不拼 /products/{slug}）。
    // published + core + PublicUrl=null → FAIL（应 200 却无落地页）；
    // 非核心产品无独立页 → by design，N/A（不计 Fail）。
    private function checkPublicUrl($entities): array
    {
        $products = $entities->where('type', Entity::TYPE_PRODUCT)->values();
        $missing = [];
        $nonCore = 0;

        foreach ($products as $p) {
            $isCore = Catalog::isCoreProduct($p->slug);
            $url = PublicUrl::entity($p);
            if (! $isCore) {
                $nonCore++;
                continue;
            }
            if ($url === null) {
                $missing[] = ['type' => 'entity', 'id' => $p->id, 'label' => $p->name, 'slug' => $p->slug, 'reason' => '核心产品应存在详情页，但 PublicUrl 未解析到落地页'];
            }
        }

        $status = $products->isEmpty()
            ? 'N/A'
            : ($missing === [] ? 'PASS' : 'FAIL');

        return [
            'key' => 'public_url', 'label' => 'Published Product Without Public URL',
            'status' => $status,
            'counts' => ['public_products' => $products->count(), 'non_core_no_page' => $nonCore, 'missing_public_url' => count($missing)],
            'affected' => array_values($missing),
        ];
    }

    // ---------------------------------------------------------------
    // Check C — JSON-LD Emission（builder-level 覆盖；HTTP 证据见测试）
    // ---------------------------------------------------------------
    // 对每个公开资源跑 SchemaBuilder（entity/article），统计"应产出 Schema 的页型 →
    // 是否产出非 null 节点"。Home（Organization+WebSite）恒产出。
    // Render-path / HTTP-level（ld+json 实际出现在响应 HTML）由 Feature 测试真实 GET 验证。
    private function checkJsonLd($entities, $contents): array
    {
        $builder = app(SchemaBuilder::class);
        $byType = [
            'home' => ['expected' => 1, 'emitted' => 1], // Organization + WebSite 恒产出
            'product' => ['expected' => 0, 'emitted' => 0],
            'service' => ['expected' => 0, 'emitted' => 0],
            'case_study' => ['expected' => 0, 'emitted' => 0],
            'article' => ['expected' => 0, 'emitted' => 0],
        ];
        $missing = [];

        foreach ($entities as $e) {
            $type = $e->type;
            if (! in_array($type, [Entity::TYPE_PRODUCT, Entity::TYPE_SERVICE, Entity::TYPE_CASE_STUDY], true)) {
                continue;
            }
            $bucket = $type === Entity::TYPE_PRODUCT ? 'product' : ($type === Entity::TYPE_SERVICE ? 'service' : 'case_study');
            $byType[$bucket]['expected']++;
            $node = $builder->entity($e, $this->seo->resolveEntity($e));
            if ($node !== null) {
                $byType[$bucket]['emitted']++;
            } else {
                $missing[] = ['type' => 'entity', 'id' => $e->id, 'label' => $e->name, 'reason' => "公开 {$type} 未产出 JSON-LD 节点"];
            }
        }

        foreach ($contents as $c) {
            $byType['article']['expected']++;
            $node = $builder->article($c, $this->seo->resolveContent($c));
            if ($node !== null) {
                $byType['article']['emitted']++;
            } else {
                $missing[] = ['type' => 'content', 'id' => $c->id, 'label' => $c->title, 'reason' => '公开内容未产出 JSON-LD 节点'];
            }
        }

        $applicable = $entities->whereIn('type', [Entity::TYPE_PRODUCT, Entity::TYPE_SERVICE, Entity::TYPE_CASE_STUDY])->count() + $contents->count();
        $status = $applicable === 0 ? 'N/A' : ($missing === [] ? 'PASS' : 'WARNING');

        return [
            'key' => 'jsonld', 'label' => 'JSON-LD Emission',
            'status' => $status,
            'counts' => $byType,
            'affected' => array_values($missing),
        ];
    }

    // ---------------------------------------------------------------
    // Check D — noindex Leakage
    // ---------------------------------------------------------------
    // PublicIndex 已从公开 feed 排除 noindex 资源；本检查反向找"已 published 却被
    // SeoMeta 标记 noindex"的矛盾态（可能是有意，也可能是泄漏）。draft/archived/admin/
    // private 本就不公开，不纳入。无法区分意图 → 报 WARNING（人工确认），不报 Fail。
    private function checkNoindex(Site $site, string $locale): array
    {
        $affected = [];

        $noindexEntityIds = SeoMeta::where('site_id', $site->id)->where('locale', $locale)
            ->whereNotNull('entity_id')->where('noindex', true)->pluck('entity_id')->all();
        if ($noindexEntityIds !== []) {
            $rows = Entity::withoutSiteScope()->where('site_id', $site->id)
                ->whereIn('id', $noindexEntityIds)->where('status', Entity::STATUS_PUBLISHED)->get();
            foreach ($rows as $e) {
                $affected[] = ['type' => 'entity', 'id' => $e->id, 'label' => $e->name, 'reason' => '已发布实体却被标记 noindex（请确认是否有意）'];
            }
        }

        $noindexContentIds = SeoMeta::where('site_id', $site->id)->where('locale', $locale)
            ->whereNotNull('content_id')->where('noindex', true)->pluck('content_id')->all();
        if ($noindexContentIds !== []) {
            $rows = Content::withoutSiteScope()->where('site_id', $site->id)
                ->whereIn('id', $noindexContentIds)->where('status', 'published')->get();
            foreach ($rows as $c) {
                $affected[] = ['type' => 'content', 'id' => $c->id, 'label' => $c->title, 'reason' => '已发布内容却被标记 noindex（请确认是否有意）'];
            }
        }

        $status = $affected === []
            ? ((Entity::withoutSiteScope()->where('site_id', $site->id)->where('status', Entity::STATUS_PUBLISHED)->where('locale', $locale)->count()
                + Content::withoutSiteScope()->where('site_id', $site->id)->where('status', 'published')->where('locale', $locale)->count()) === 0
                ? 'N/A' : 'PASS')
            : 'WARNING';

        return [
            'key' => 'noindex', 'label' => 'noindex Leakage',
            'status' => $status,
            'counts' => ['published_but_noindex' => count($affected)],
            'affected' => array_values($affected),
        ];
    }

    // ---------------------------------------------------------------
    // Check E — GEO Edge Count（与 GeoGraphBuilder 两端公开口径一致，不 count(*)）
    // ---------------------------------------------------------------
    // Entity→Entity：EntityRelation（当前站，两端实体均 published+当前 locale）；
    // Content→Entity：ContentEntity（当前站，content 端公开、entity 存在）。
    // 自动排除他站（site_id 过滤）/ 未公开端 / orphan pivot（端不存在）。
    private function checkEdges(Site $site, string $locale, $entities): array
    {
        // --- Entity → Entity edges（两端均公开才算有效边）---
        $relations = EntityRelation::where('site_id', $site->id)->get();
        $endpointIds = $relations->flatMap(fn (EntityRelation $r) => [$r->from_entity_id, $r->to_entity_id])->unique();
        $publicIds = Entity::withoutSiteScope()->where('site_id', $site->id)
            ->whereIn('id', $endpointIds)->where('status', Entity::STATUS_PUBLISHED)
            ->where('locale', $locale)->pluck('id')->flip();

        $entityToEntity = 0;
        $byType = [];
        $edgeNodeIds = collect();
        foreach ($relations as $r) {
            if (! isset($publicIds[$r->from_entity_id]) || ! isset($publicIds[$r->to_entity_id])) {
                continue; // 任一端未公开 → 不计（与 GeoGraphBuilder 一致）
            }
            $entityToEntity++;
            $byType[$r->relation_type] = ($byType[$r->relation_type] ?? 0) + 1;
            $edgeNodeIds->push($r->from_entity_id, $r->to_entity_id);
        }

        // --- Content → Entity edges（content 端公开）---
        $ceRows = ContentEntity::where('site_id', $site->id)->get();
        $publicContentIds = PublicIndex::contentQuery()->forLocale($locale)->pluck('id')->flip();
        $publicEntityIds = $entities->pluck('id')->flip();
        $contentToEntity = 0;
        foreach ($ceRows as $ce) {
            if (! isset($publicContentIds[$ce->content_id]) || ! isset($publicEntityIds[$ce->entity_id])) {
                continue;
            }
            $contentToEntity++;
        }

        // --- 孤立公开实体（无任何边）---
        $edgeNodeIds = $edgeNodeIds->unique();
        $orphans = $entities->reject(fn (Entity $e) => $edgeNodeIds->contains($e->id))->values();

        $hasAny = $entityToEntity > 0 || $contentToEntity > 0;
        $status = $entities->isEmpty()
            ? 'N/A'
            : ($orphans->count() > 0 ? 'WARNING' : 'PASS');

        return [
            'key' => 'edges', 'label' => 'GEO Edge Count',
            'status' => $status,
            'counts' => [
                'entity_to_entity' => $entityToEntity,
                'content_to_entity' => $contentToEntity,
                'by_type' => $byType,
                'orphan_entities' => $orphans->count(),
            ],
            'affected' => $orphans->map(fn (Entity $e) => [
                'type' => 'entity', 'id' => $e->id, 'label' => $e->name,
                'reason' => '公开实体未挂载任何关系边',
            ])->values()->all(),
        ];
    }
}
