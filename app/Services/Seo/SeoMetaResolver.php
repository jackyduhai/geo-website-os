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
    public function __construct(
        private UrlResolverInterface $urlResolver
    ) {}

    /**
     * Resolve SEO meta for site-level
     */
    public function resolveSite(Site $site): SeoResult
    {
        $seoMeta = $this->findSiteLevelSeo($site);

        return new SeoResult(
            title: $seoMeta?->title ?? $site->name,
            description: $seoMeta?->description ?? $site->description,
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('home'),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $site->name),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $site->description),
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

        $seoMeta = SeoMeta::query()
            ->where('site_id', $content->site_id)
            ->where('content_id', $content->id)
            ->first();

        $fallback = $this->siteFallback($site);

        // OG Image: SeoMeta -> Content.og_image_id -> Content.cover_id -> Site
        $ogImage = $seoMeta?->og_image_path
            ?? $this->resolveContentOgImage($content)
            ?? $fallback['ogImage'];

        return new SeoResult(
            title: $seoMeta?->title ?? $content->seo_title ?? $content->title ?? $fallback['title'],
            description: $seoMeta?->description ?? $content->seo_desc ?? $content->summary ?? $fallback['description'],
            keywords: $seoMeta?->keywords ?? [],
            // Canonical: SeoMeta -> UrlResolver (no Content.canonical as formal layer)
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('content', [
                'type' => $content->type,
                'slug' => $content->slug,
            ]),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $content->seo_title ?? $content->title ?? $fallback['title']),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $content->seo_desc ?? $content->summary ?? $fallback['description']),
            ogImage: $ogImage,
            ogType: $seoMeta?->og_type ?? 'article',
            twitterCard: $seoMeta?->twitter_card ?? 'summary_large_image',
            noindex: $seoMeta?->noindex ?? ($content->noindex ?? false),
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

        $seoMeta = SeoMeta::query()
            ->where('site_id', $entity->site_id)
            ->where('entity_id', $entity->id)
            ->first();

        $fallback = $this->siteFallback($site);

        $metadata = $entity->metadata ?? [];
        $entityOgImage = $metadata['og_image'] ?? null;

        return new SeoResult(
            title: $seoMeta?->title ?? $entity->name ?? $fallback['title'],
            description: $seoMeta?->description ?? $entity->summary ?? $entity->description ?? $fallback['description'],
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? $this->urlResolver->generateCanonical('entity', [
                'type' => $entity->type,
                'slug' => $entity->slug,
            ]),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $entity->name ?? $fallback['title']),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $entity->summary ?? $entity->description ?? $fallback['description']),
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
     * Resolve content OG image via Media relationship
     */
    private function resolveContentOgImage(Content $content): ?string
    {
        // Try og_image_id first
        if ($content->og_image_id) {
            $media = Media::find($content->og_image_id);
            if ($media) {
                return $media->path;
            }
        }

        // Try cover_id second
        if ($content->cover_id) {
            $media = Media::find($content->cover_id);
            if ($media) {
                return $media->path;
            }
        }

        return null;
    }

    /**
     * Find site-level SeoMeta
     */
    private function findSiteLevelSeo(Site $site): ?SeoMeta
    {
        return SeoMeta::query()
            ->where('site_id', $site->id)
            ->whereNull('content_id')
            ->whereNull('entity_id')
            ->first();
    }

    /**
     * Get site-level default fallback values
     */
    private function siteFallback(Site $site): array
    {
        $seo = $this->findSiteLevelSeo($site);

        return [
            'title' => $seo?->title ?? $site->name,
            'description' => $seo?->description ?? $site->description,
            'ogImage' => $seo?->og_image_path ?? $site->logo,
        ];
    }
}
