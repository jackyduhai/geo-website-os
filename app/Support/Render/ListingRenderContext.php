<?php

namespace App\Support\Render;

use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoResult;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateRegistry;

/**
 * 动态 Listing 渲染上下文（P-STEP 18G-2b）。
 * --------------------------------------------------
 * 承载数据驱动的聚合页（产品系列 / 知识频道），无持久化 Page：
 *  - main 固定槽由对应 sys_* 系统块直驱（数据由控制器从 Catalog / Group 准备，
 *    经 $resource 注入），不复制业务事实；
 *  - SEO 经 {@see SeoMetaResolver::resolveListing} 统一构造（title / desc /
 *    canonical 来自系列 / 频道事实 + 站点 fallback），不另造第二套 SEO。
 */
class ListingRenderContext implements RenderContext
{
    public function __construct(
        private readonly string $listingKey,   // 'product_line' | 'knowledge_channel'
        private readonly array $resource = [],
    ) {}

    public function listingKey(): string
    {
        return $this->listingKey;
    }

    public function site(): Site
    {
        return SiteContext::currentSite();
    }

    public function locale(): string
    {
        return LocaleContext::current();
    }

    public function resourceType(): string
    {
        return 'listing';
    }

    public function template(): TemplateDefinition
    {
        return TemplateRegistry::get('listing');
    }

    public function seo(): SeoResult
    {
        $sd = $this->resource['seo_default'] ?? [];

        return app(SeoMetaResolver::class)->resolveListing(
            (string) ($sd['title'] ?? ''),
            isset($sd['description']) ? (string) $sd['description'] : null,
            (string) ($this->resource['canonical'] ?? PublicUrl::home())
        );
    }

    public function hasHero(): bool
    {
        return true; // sys_* 系统块内含 page-hero
    }

    public function crumbs(): array
    {
        return $this->resource['crumbs'] ?? [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
        ];
    }

    public function subnav(): ?array
    {
        return $this->resource['subnav'] ?? null;
    }

    public function blocksForSlot(string $slot): array
    {
        return match ($slot) {
            'main' => [new VirtualBlock((string) ($this->resource['system_block'] ?? 'sys_products'), [])],
            default => [],
        };
    }

    public function viewContext(): array
    {
        return array_merge(
            ['site' => $this->site(), 'aboutPage' => $this->listingKey],
            $this->resource
        );
    }

    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $nodes = array_merge(
            [$schema->organization(), $schema->breadcrumb($this->crumbs())],
            $this->resource['schemas'] ?? []
        );

        return array_values(array_filter($nodes));
    }
}
