<?php

namespace App\Services\Seo;

use App\Contracts\UrlResolverInterface;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\SiteContext;

class SeoMetaResolver
{
    private const SYSTEM_DEFAULT_TITLE = 'Website';
    private const SYSTEM_DEFAULT_DESCRIPTION = '';

    /**
     * 请求级记忆化（STEP 09）：同一请求内解析结果不变。
     * 值为 false 表示「已查询、不存在」的负缓存，避免同请求内重复查库。
     * 由 AppServiceProvider::boot 逐请求复位（FPM/serve 进程复用 static）。
     */
    private static array $siteSeoMemo = [];     // site_id => SeoMeta|false
    private static array $contentSeoMemo = [];  // "site:content" => SeoMeta|false
    private static array $entitySeoMemo = [];   // "site:entity" => SeoMeta|false
    private static array $mediaPathMemo = [];   // media_id => ?string

    public function __construct(
        private UrlResolverInterface $urlResolver
    ) {}

    public static function resetRequestMemo(): void
    {
        self::$siteSeoMemo = [];
        self::$contentSeoMemo = [];
        self::$entitySeoMemo = [];
        self::$mediaPathMemo = [];
    }

    /** 批量预载 Content 级 SeoMeta（列表/图结构输出场景，消除 N+1） */
    public function preloadContentSeoMetas(iterable $contents): void
    {
        $idsBySite = [];
        foreach ($contents as $c) {
            $idsBySite[$c->site_id][] = $c->id;
        }
        foreach ($idsBySite as $siteId => $ids) {
            $found = SeoMeta::query()->where('site_id', $siteId)->whereIn('content_id', $ids)->get()
                ->keyBy('content_id');
            foreach ($ids as $id) {
                self::$contentSeoMemo["{$siteId}:{$id}"] = $found->get($id) ?? false;
            }
        }
    }

    /** 批量预载 Entity 级 SeoMeta */
    public function preloadEntitySeoMetas(iterable $entities): void
    {
        $idsBySite = [];
        foreach ($entities as $e) {
            $idsBySite[$e->site_id][] = $e->id;
        }
        foreach ($idsBySite as $siteId => $ids) {
            $found = SeoMeta::query()->where('site_id', $siteId)->whereIn('entity_id', $ids)->get()
                ->keyBy('entity_id');
            foreach ($ids as $id) {
                self::$entitySeoMemo["{$siteId}:{$id}"] = $found->get($id) ?? false;
            }
        }
    }

    /** 批量预载 Media path（OG 图片解析链） */
    public function preloadMediaPaths(iterable $mediaIds): void
    {
        $ids = collect($mediaIds)->filter()->unique()->values();
        $missing = $ids->reject(fn ($id) => array_key_exists((string) $id, self::$mediaPathMemo)
            || array_key_exists($id, self::$mediaPathMemo));
        if ($missing->isNotEmpty()) {
            Media::query()->whereIn('id', $missing)->get()->each(function (Media $m) {
                self::$mediaPathMemo[$m->id] = $m->path;
            });
        }
        foreach ($ids as $id) {
            if (! array_key_exists($id, self::$mediaPathMemo)) {
                self::$mediaPathMemo[$id] = null;
            }
        }
    }

    private function contentSeoMeta(int $siteId, int $contentId): ?SeoMeta
    {
        $key = "{$siteId}:{$contentId}";
        if (! array_key_exists($key, self::$contentSeoMemo)) {
            self::$contentSeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $siteId)
                ->where('content_id', $contentId)
                ->first() ?? false;
        }

        return self::$contentSeoMemo[$key] ?: null;
    }

    private function entitySeoMeta(int $siteId, int $entityId): ?SeoMeta
    {
        $key = "{$siteId}:{$entityId}";
        if (! array_key_exists($key, self::$entitySeoMemo)) {
            self::$entitySeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $siteId)
                ->where('entity_id', $entityId)
                ->first() ?? false;
        }

        return self::$entitySeoMemo[$key] ?: null;
    }

    private function mediaPath(?int $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }
        if (! array_key_exists($mediaId, self::$mediaPathMemo)) {
            self::$mediaPathMemo[$mediaId] = Media::find($mediaId)?->path;
        }

        return self::$mediaPathMemo[$mediaId];
    }

    /**
     * Resolve SEO meta for site-level
     */
    public function resolveSite(Site $site): SeoResult
    {
        $seoMeta = $this->findSiteLevelSeo($site);

        return new SeoResult(
            title: $seoMeta?->title ?? $site->name ?? self::SYSTEM_DEFAULT_TITLE,
            description: $seoMeta?->description ?? $site->description ?? self::SYSTEM_DEFAULT_DESCRIPTION,
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('home'),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $site->name ?? self::SYSTEM_DEFAULT_TITLE),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $site->description ?? self::SYSTEM_DEFAULT_DESCRIPTION),
            ogImage: $seoMeta?->og_image_path ?? $site->logo,
            ogType: $seoMeta?->og_type ?? 'website',
            twitterCard: $seoMeta?->twitter_card ?? 'summary_large_image',
            noindex: $seoMeta?->noindex ?? false,
            nofollow: $seoMeta?->nofollow ?? false,
            robots: $seoMeta?->robots ?? [],
            schemaType: $seoMeta?->schema_type ?? null
        );
    }

    /**
     * Resolve SEO meta for content
     */
    public function resolveContent(Content $content): SeoResult
    {
        $site = SiteContext::currentSite();

        $seoMeta = $this->contentSeoMeta($content->site_id, $content->id);

        $fallback = $this->siteFallback($site);

        // OG Image: SeoMeta -> Content.og_image_id -> Content.cover_id -> Site
        $ogImage = $seoMeta?->og_image_path
            ?? $this->mediaPath($content->og_image_id)
            ?? $this->mediaPath($content->cover_id)
            ?? $fallback['ogImage'];

        // Title chain (P-STEP 07 后)：SeoMeta -> Content.title -> Site -> System
        // （legacy Content.seo_title 层随 P0-B backfill 完成后移除，见 ADR）
        $title = $seoMeta?->title
            ?? $content->title
            ?? $fallback['title'];

        // Handle empty strings (treat as null)
        if (empty($title)) {
            $title = $fallback['title'];
        }
        if (empty($title)) {
            $title = self::SYSTEM_DEFAULT_TITLE;
        }

        // Description chain (P-STEP 07 后)：SeoMeta -> Content.summary -> Site -> System
        $description = $seoMeta?->description
            ?? $content->summary
            ?? $fallback['description'];

        if (empty($description)) {
            $description = $fallback['description'];
        }
        if (empty($description)) {
            $description = self::SYSTEM_DEFAULT_DESCRIPTION;
        }

        return new SeoResult(
            title: $title,
            description: $description,
            keywords: $seoMeta?->keywords ?? [],
            // Canonical: SeoMeta -> UrlResolver (no Content.canonical as formal layer)
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('content', [
                'type' => $content->type,
                'slug' => $content->slug,
            ]),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $title),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $description),
            ogImage: $ogImage,
            ogType: $seoMeta?->og_type ?? 'article',
            twitterCard: $seoMeta?->twitter_card ?? 'summary_large_image',
            noindex: $seoMeta?->noindex ?? false,
            nofollow: $seoMeta?->nofollow ?? false,
            robots: $seoMeta?->robots ?? [],
            schemaType: $seoMeta?->schema_type ?? null
        );
    }

    /**
     * Resolve SEO meta for entity
     */
    public function resolveEntity(Entity $entity): SeoResult
    {
        $site = SiteContext::currentSite();

        $seoMeta = $this->entitySeoMeta($entity->site_id, $entity->id);

        $fallback = $this->siteFallback($site);

        $metadata = $entity->metadata ?? [];
        $entityOgImage = $metadata['og_image'] ?? null;

        // Title chain: SeoMeta -> Entity.name -> Site -> System
        $title = $seoMeta?->title
            ?? $entity->name
            ?? $fallback['title'];

        // Description chain: SeoMeta -> Entity.summary -> Entity.description -> Site -> System
        $description = $seoMeta?->description
            ?? $entity->summary
            ?? $entity->description
            ?? $fallback['description'];

        return new SeoResult(
            title: $title,
            description: $description,
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('entity', [
                'type' => $entity->type,
                'slug' => $entity->slug,
            ]),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $title),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $description),
            ogImage: $seoMeta?->og_image_path ?? $entityOgImage ?? $fallback['ogImage'],
            ogType: $seoMeta?->og_type ?? 'website',
            twitterCard: $seoMeta?->twitter_card ?? 'summary_large_image',
            noindex: $seoMeta?->noindex ?? false,
            nofollow: $seoMeta?->nofollow ?? false,
            robots: $seoMeta?->robots ?? [],
            schemaType: $seoMeta?->schema_type ?? null
        );
    }

    /**
     * Find site-level SeoMeta
     */
    private function findSiteLevelSeo(Site $site): ?SeoMeta
    {
        if (! array_key_exists($site->id, self::$siteSeoMemo)) {
            self::$siteSeoMemo[$site->id] = SeoMeta::query()
                ->where('site_id', $site->id)
                ->whereNull('content_id')
                ->whereNull('entity_id')
                ->first() ?? false;
        }

        return self::$siteSeoMemo[$site->id] ?: null;
    }

    /**
     * Get site-level default fallback values
     */
    private function siteFallback(Site $site): array
    {
        $seo = $this->findSiteLevelSeo($site);

        return [
            'title' => $seo?->title ?? $site->name ?? self::SYSTEM_DEFAULT_TITLE,
            'description' => $seo?->description ?? $site->description ?? self::SYSTEM_DEFAULT_DESCRIPTION,
            'ogImage' => $seo?->og_image_path ?? $site->logo,
        ];
    }
}
