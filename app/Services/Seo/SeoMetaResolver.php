<?php

namespace App\Services\Seo;

use App\Contracts\UrlResolverInterface;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\Page;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
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
    private static array $pageSeoMemo = [];     // "site:page" => SeoMeta|false
    private static array $mediaPathMemo = [];   // media_id => ?string

    public function __construct(
        // P-STEP 17G 起 canonical 统一由 {@see PublicUrl} 按真实前台路由裁决；
        // GenericUrlResolver 硬编码的 /{单数类型}/{slug} 会产出 404 地址，已不再用于
        // canonical。保留该构造注入仅为兼容容器绑定与既有测试，待 #86 阶段移除。
        private UrlResolverInterface $urlResolver
    ) {}

    public static function resetRequestMemo(): void
    {
        self::$siteSeoMemo = [];
        self::$contentSeoMemo = [];
        self::$entitySeoMemo = [];
        self::$pageSeoMemo = [];
        self::$mediaPathMemo = [];
    }

    /** 批量预载 Content 级 SeoMeta（列表/图结构输出场景，消除 N+1） */
    public function preloadContentSeoMetas(iterable $contents): void
    {
        $idsBySite = [];
        foreach ($contents as $c) {
            $idsBySite[$c->site_id][] = $c->id;
        }
        $locale = LocaleContext::current();
        foreach ($idsBySite as $siteId => $ids) {
            $found = SeoMeta::query()->where('site_id', $siteId)->where('locale', $locale)
                ->whereIn('content_id', $ids)->get()->keyBy('content_id');
            foreach ($ids as $id) {
                self::$contentSeoMemo["{$siteId}:{$id}:{$locale}"] = $found->get($id) ?? false;
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
        $locale = LocaleContext::current();
        foreach ($idsBySite as $siteId => $ids) {
            $found = SeoMeta::query()->where('site_id', $siteId)->where('locale', $locale)
                ->whereIn('entity_id', $ids)->get()->keyBy('entity_id');
            foreach ($ids as $id) {
                self::$entitySeoMemo["{$siteId}:{$id}:{$locale}"] = $found->get($id) ?? false;
            }
        }
    }

    /**
     * 批量预载 Media 公开 URL（OG 图片解析链）。
     * 必须缓存 Media::url()（asset('storage/'.path) 的绝对公开地址），不能缓存
     * Media::path（disk 相对路径，如 covers/202609/x.png）：后者既缺 /storage 前缀
     * 也缺 host，写入 og:image / JSON-LD 后是不可解析的坏链（UAT Bug#3）。
     */
    public function preloadMediaPaths(iterable $mediaIds): void
    {
        $ids = collect($mediaIds)->filter()->unique()->values();
        $missing = $ids->reject(fn ($id) => array_key_exists((string) $id, self::$mediaPathMemo)
            || array_key_exists($id, self::$mediaPathMemo));
        if ($missing->isNotEmpty()) {
            Media::query()->whereIn('id', $missing)->get()->each(function (Media $m) {
                self::$mediaPathMemo[$m->id] = $m->url();
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
        $locale = LocaleContext::current();
        $key = "{$siteId}:{$contentId}:{$locale}";
        if (! array_key_exists($key, self::$contentSeoMemo)) {
            self::$contentSeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $siteId)
                ->where('content_id', $contentId)
                ->where('locale', $locale)
                ->first() ?? false;
        }

        return self::$contentSeoMemo[$key] ?: null;
    }

    private function entitySeoMeta(int $siteId, int $entityId): ?SeoMeta
    {
        $locale = LocaleContext::current();
        $key = "{$siteId}:{$entityId}:{$locale}";
        if (! array_key_exists($key, self::$entitySeoMemo)) {
            self::$entitySeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $siteId)
                ->where('entity_id', $entityId)
                ->where('locale', $locale)
                ->first() ?? false;
        }

        return self::$entitySeoMemo[$key] ?: null;
    }

    private function pageSeoMeta(int $siteId, int $pageId): ?SeoMeta
    {
        $locale = LocaleContext::current();
        $key = "{$siteId}:{$pageId}:{$locale}";
        if (! array_key_exists($key, self::$pageSeoMemo)) {
            self::$pageSeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $siteId)
                ->where('page_id', $pageId)
                ->where('locale', $locale)
                ->first() ?? false;
        }

        return self::$pageSeoMemo[$key] ?: null;
    }

    /**
     * 取媒体的公开 URL（OG 图片链）。返回 Media::url() 绝对地址而非 disk 相对
     * path——否则前台 og:image / JSON-LD image 会输出缺 host 与 /storage 前缀的坏链。
     */
    private function mediaPath(?int $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }
        if (! array_key_exists($mediaId, self::$mediaPathMemo)) {
            self::$mediaPathMemo[$mediaId] = Media::find($mediaId)?->url();
        }

        return self::$mediaPathMemo[$mediaId];
    }

    /**
     * Resolve SEO meta for site-level
     */
    public function resolveSite(Site $site): SeoResult
    {
        $seoMeta = $this->findSiteLevelSeo($site);
        [$siteName, $siteDesc] = $this->siteIdentity($site);

        return new SeoResult(
            title: $seoMeta?->title ?? ($siteName !== '' ? $siteName : self::SYSTEM_DEFAULT_TITLE),
            description: $seoMeta?->description ?? ($siteDesc !== '' ? $siteDesc : self::SYSTEM_DEFAULT_DESCRIPTION),
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? PublicUrl::home(),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? ($siteName !== '' ? $siteName : self::SYSTEM_DEFAULT_TITLE)),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? ($siteDesc !== '' ? $siteDesc : self::SYSTEM_DEFAULT_DESCRIPTION)),
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
            // Canonical: SeoMeta -> PublicUrl（真实前台路由；P-STEP 17G 收敛）
            canonical: $seoMeta?->canonical ?? PublicUrl::content($content),
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
        $entityOgImageId = isset($metadata['og_image']) && is_numeric($metadata['og_image'])
            ? (int) $metadata['og_image']
            : null;

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
            canonical: $seoMeta?->canonical ?? (PublicUrl::entity($entity) ?? PublicUrl::home()),
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $title),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $description),
            ogImage: $seoMeta?->og_image_path ?? $this->mediaPath($entityOgImageId) ?? $fallback['ogImage'],
            ogType: $seoMeta?->og_type ?? 'website',
            twitterCard: $seoMeta?->twitter_card ?? 'summary_large_image',
            noindex: $seoMeta?->noindex ?? false,
            nofollow: $seoMeta?->nofollow ?? false,
            robots: $seoMeta?->robots ?? [],
            schemaType: $seoMeta?->schema_type ?? null
        );
    }

    /**
     * Resolve SEO meta for a composed Page（P-STEP 18G）。
     * Title chain：page-level SeoMeta -> Page.title -> Site -> System；
     * Description / OG image 无 Page 字段，回退站点级；canonical 为 Page 公开 URL。
     */
    public function resolvePage(Page $page): SeoResult
    {
        $site = SiteContext::currentSite();
        $seoMeta = $this->pageSeoMeta($page->site_id, $page->id);
        $fallback = $this->siteFallback($site);

        $title = $seoMeta?->title
            ?? ((string) $page->title !== '' ? $page->title : $fallback['title']);
        if (empty($title)) {
            $title = self::SYSTEM_DEFAULT_TITLE;
        }

        $description = $seoMeta?->description ?? $fallback['description'];

        $defaultCanonical = $page->slug
            ? PublicUrl::url('/'.ltrim((string) $page->slug, '/'))
            : PublicUrl::home();

        return new SeoResult(
            title: $title,
            description: $description,
            keywords: $seoMeta?->keywords ?? [],
            canonical: $seoMeta?->canonical ?? $defaultCanonical,
            ogTitle: $seoMeta?->og_title ?? ($seoMeta?->title ?? $title),
            ogDescription: $seoMeta?->og_description ?? ($seoMeta?->description ?? $description),
            ogImage: $seoMeta?->og_image_path ?? $fallback['ogImage'],
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
        $locale = LocaleContext::current();
        $key = "{$site->id}:{$locale}";
        if (! array_key_exists($key, self::$siteSeoMemo)) {
            self::$siteSeoMemo[$key] = SeoMeta::query()
                ->where('site_id', $site->id)
                ->where('locale', $locale)
                ->whereNull('content_id')
                ->whereNull('entity_id')
                ->first() ?? false;
        }

        return self::$siteSeoMemo[$key] ?: null;
    }

    /**
     * Get site-level default fallback values
     */
    private function siteFallback(Site $site): array
    {
        $seo = $this->findSiteLevelSeo($site);
        [$siteName, $siteDesc] = $this->siteIdentity($site);

        return [
            'title' => $seo?->title ?? ($siteName !== '' ? $siteName : self::SYSTEM_DEFAULT_TITLE),
            'description' => $seo?->description ?? ($siteDesc !== '' ? $siteDesc : self::SYSTEM_DEFAULT_DESCRIPTION),
            'ogImage' => $seo?->og_image_path ?? $site->logo,
        ];
    }

    /**
     * 站点主体身份（Site 聚合，TD-07 唯一事实源）按当前语言解析。
     * zh：Site.name / Site.description；
     * en：优先 Site 级 Setting geo_org_en_name / seo_default_en_desc，缺省回退 Site 字段。
     *
     * @return array{0:string,1:string} [name, description]
     */
    private function siteIdentity(Site $site): array
    {
        $name = (string) ($site->name ?? '');
        $desc = (string) ($site->description ?? '');

        if (LocaleContext::current() === 'en') {
            $enName = Setting::get('geo_org_en_name', '');
            $enDesc = Setting::get('seo_default_en_desc', '');
            if (is_string($enName) && trim($enName) !== '') {
                $name = $enName;
            }
            if (is_string($enDesc) && trim($enDesc) !== '') {
                $desc = $enDesc;
            }
        }

        return [$name, $desc];
    }
}
