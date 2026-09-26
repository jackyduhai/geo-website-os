<?php

namespace App\Support\Render;

use App\Models\Entity;
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
 * 客户案例（CaseStudy）详情渲染上下文（P-STEP 18R-2b）。
 * --------------------------------------------------
 * case_study 是 Entity 子类型，但详情叙事（challenge/solution/result + 关联产品 +
 * 客户 Organization）与 Product/Service 差异显著，不复用 EntityRenderContext 的产品
 * 目录分支；本上下文只负责「取资源 → 装配固定槽块 → 交 CompositionRenderer」，
 * 块（case_detail / bottom_cta）仍统一经 BlockRegistry 渲染，不另起渲染管线。
 *
 * SEO 统一走 {@see SeoMetaResolver::resolveEntity}（与 Product/Service 同源）。
 */
class CaseRenderContext implements RenderContext
{
    public function __construct(
        private readonly Entity $entity,
        private readonly array $resource = [], // 已装配：industry/scenario/challenge/solution/result/products/customer
    ) {}

    public function entity(): Entity
    {
        return $this->entity;
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
        return 'entity';
    }

    public function template(): TemplateDefinition
    {
        return TemplateRegistry::get('detail');
    }

    public function seo(): SeoResult
    {
        return app(SeoMetaResolver::class)->resolveEntity($this->entity);
    }

    public function hasHero(): bool
    {
        return false; // case_detail 块内含页头叙事，布局不再额外占位
    }

    public function publicUrl(): string
    {
        return PublicUrl::entity($this->entity) ?? PublicUrl::home();
    }

    public function crumbs(): array
    {
        return [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.cases'), 'url' => PublicUrl::url('cases/')],
            ['name' => $this->entity->name, 'url' => $this->publicUrl()],
        ];
    }

    public function subnav(): ?array
    {
        return null;
    }

    public function blocksForSlot(string $slot): array
    {
        return match ($slot) {
            'header' => [],
            'main' => [new VirtualBlock('case_detail', [])],
            'related' => [new VirtualBlock('bottom_cta', [])],
            default => [],
        };
    }

    public function viewContext(): array
    {
        return array_merge([
            'entity' => $this->entity,
            'site'   => $this->site(),
            'case'   => $this->resource,
        ]);
    }

    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $url = $this->publicUrl();

        $nodes = [
            $schema->organization(),
            $schema->breadcrumb($this->crumbs()),
            $schema->webPage($url, $this->entity->name, (string) ($this->entity->summary ?? ''), 'WebPage'),
            $schema->entity($this->entity), // @type=CaseStudy
        ];

        return array_values(array_filter($nodes));
    }
}
