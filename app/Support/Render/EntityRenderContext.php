<?php

namespace App\Support\Render;

use App\Models\Entity;
use App\Models\Page;
use App\Models\Site;
use App\Providers\AppServiceProvider;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoResult;
use App\Support\Catalog;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\Localization\LocaleContext;
use App\Support\Narrative;
use App\Support\Pages;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateRegistry;

/**
 * Entity Detail 渲染上下文（Product / Service，P-STEP 18G-2a）。
 * --------------------------------------------------
 * 固定槽（header / main 强结构）由当前 Entity + Catalog 读模型直驱，不可被 override；
 * 可组合槽（related）默认合成，命中 entity_id override Page 时整槽替换。
 *
 * TD-61：详情 SEO 统一走 {@see SeoMetaResolver::resolveEntity}，消费管理员为 Entity
 * 设置的 entity-level SeoMeta（title / desc / canonical / OG / noindex），控制器不再
 * 手工拼 SEO。Page 仅存结构 / 覆盖，绝不复制 Product 业务数据。
 */
class EntityRenderContext implements RenderContext
{
    private ?Page $override;

    private function __construct(
        private readonly Entity $entity,
        private readonly array $resource, // Catalog product 或 scene（已含 tagline / desc）
    ) {
        $this->override = Page::published()
            ->where('entity_id', $this->entity->id)
            ->where('locale', $this->locale())
            ->first();
    }

    /**
     * 按 Entity 构建 Detail 上下文。
     * 非公开（Product 非 core / Service 无场景 / 其他类型）返回 null，控制器据此 404。
     */
    public static function forEntity(Entity $entity): ?self
    {
        // Registry gate：声明 public=false 的类型（organization/person/location/topic/
        // download_asset）不尝试渲染详情页，控制器据此 404。case_study 虽 public=true，
        // 但 2a 未实现渲染分支，仍落到末尾 return null（2b 加渲染上下文）。
        if (! EntityCapabilityRegistry::isPublic($entity->type)) {
            return null;
        }

        if ($entity->type === Entity::TYPE_PRODUCT) {
            $product = Catalog::product($entity->slug);
            if (! $product || ! Catalog::isCoreProduct($entity->slug)) {
                return null;
            }
            $product['tagline'] = Narrative::lead(
                'products.detail.' . $entity->slug,
                $product['tagline'] ?? $product['summary'] ?? ''
            );

            return new self($entity, $product);
        }

        if ($entity->type === Entity::TYPE_SERVICE) {
            $scene = Catalog::scene($entity->slug);
            if (! $scene) {
                return null;
            }
            $scene['desc'] = Narrative::lead(
                'solutions.scene.' . $entity->slug,
                $scene['desc'] ?? ''
            );

            return new self($entity, $scene);
        }

        return null;
    }

    public function entity(): Entity
    {
        return $this->entity;
    }

    public function isService(): bool
    {
        return $this->entity->type === Entity::TYPE_SERVICE;
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
        return true; // entity_hero 固定槽
    }

    public function publicUrl(): string
    {
        return PublicUrl::entity($this->entity) ?? PublicUrl::home();
    }

    public function crumbs(): array
    {
        if ($this->isService()) {
            return [
                ['name' => __('nav.home'), 'url' => PublicUrl::home()],
                ['name' => __('nav.solutions'), 'url' => PublicUrl::url('solutions/')],
                ['name' => $this->resource['name'], 'url' => $this->publicUrl()],
            ];
        }

        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.products'), 'url' => PublicUrl::url('products/')],
        ];
        $line = $this->line();
        if ($line !== null) {
            $crumbs[] = [
                'name' => $line['name'] ?? __('nav.products'),
                'url' => PublicUrl::productLine((string) $this->resource['line']),
            ];
        }
        $crumbs[] = ['name' => $this->resource['name'], 'url' => $this->publicUrl()];

        return $crumbs;
    }

    public function subnav(): ?array
    {
        // 仅 Product 详情有产品体系 subnav；Solution 无。
        return $this->isService() ? null : $this->productSubnav((string) ($this->resource['line'] ?? ''));
    }

    // ---------------------------------------------------------------
    // Catalog 数据装配（等价复刻原 ProductController::show / SolutionController::show）
    // ---------------------------------------------------------------

    public function line(): ?array
    {
        $lineSlug = $this->resource['line'] ?? null;

        return $lineSlug !== null ? Catalog::line((string) $lineSlug) : null;
    }

    public function related(): array
    {
        return $this->isService() ? [] : Catalog::relatedProducts($this->resource, 5);
    }

    public function scenes(): array
    {
        return $this->isService() ? [] : Catalog::scenesOfProduct($this->resource);
    }

    public function combo(): array
    {
        return $this->isService() ? Catalog::sceneCombo($this->resource) : [];
    }

    public function keyProduct(): ?array
    {
        if (! $this->isService() || empty($this->resource['key_param_product'])) {
            return null;
        }

        return Catalog::product((string) $this->resource['key_param_product']);
    }

    /** @return array{0:?array,1:?array} [prev, next] */
    public function adjacent(): array
    {
        if (! $this->isService()) {
            return [null, null];
        }
        $adjacent = Catalog::adjacentScenes($this->resource);

        return [$adjacent[0] ?? null, $adjacent[1] ?? null];
    }

    public function faqs(): array
    {
        return $this->isService()
            ? Pages::faqs('scene_faqs', $this->entity->slug)
            : Pages::faqs('product_faqs', $this->entity->slug);
    }

    // ---------------------------------------------------------------
    // 槽位 → 块（固定 / 默认 / override）
    // ---------------------------------------------------------------

    public function blocksForSlot(string $slot): array
    {
        return match ($slot) {
            'header' => [new VirtualBlock('entity_hero', [])],
            'main' => $this->fixedMainBlocks(),
            'related' => $this->composableRelatedBlocks(),
            default => [],
        };
    }

    private function fixedMainBlocks(): array
    {
        if ($this->isService()) {
            // 痛点·组合 → 关键参数 → 使用流程
            return [
                new VirtualBlock('entity_relations', ['part' => 'pain_combo']),
                new VirtualBlock('entity_specifications', []),
                new VirtualBlock('entity_steps', []),
            ];
        }

        // 使用步骤 → 适用场景 → 规格
        return [
            new VirtualBlock('entity_steps', []),
            new VirtualBlock('entity_relations', ['part' => 'scenes']),
            new VirtualBlock('entity_specifications', []),
        ];
    }

    private function composableRelatedBlocks(): array
    {
        // 方案 ii：override Page 命中且 related 槽有 active blocks → 整槽替换默认。
        if ($this->override !== null) {
            $over = $this->override->blocks()
                ->where('slot', 'related')
                ->where('is_active', true)
                ->orderBy('sort')
                ->get();
            if ($over->isNotEmpty()) {
                return $over->all();
            }
        }

        $faqBlock = $this->faqVirtualBlock();

        if ($this->isService()) {
            // FAQ → 相邻场景 → bottom CTA
            return array_values(array_filter([
                $faqBlock,
                new VirtualBlock('entity_relations', ['part' => 'adjacent']),
                new VirtualBlock('bottom_cta', []),
            ]));
        }

        // 相关产品 → FAQ → bottom CTA
        return array_values(array_filter([
            new VirtualBlock('entity_relations', ['part' => 'related']),
            $faqBlock,
            new VirtualBlock('bottom_cta', []),
        ]));
    }

    private function faqVirtualBlock(): ?VirtualBlock
    {
        $faqs = array_values(array_filter(
            $this->faqs(),
            fn ($f) => ! empty($f['q']) && ! empty($f['a'])
        ));
        if ($faqs === []) {
            return null;
        }

        if ($this->isService()) {
            $title = __('ui.scene_faq_h2', ['name' => $this->resource['name']]);
        } else {
            $name = $this->resource['short_name'] ?? $this->resource['name'];
            $title = __('ui.pf_faq_h2', ['name' => $name]);
        }

        return new VirtualBlock('faq', ['title' => $title, 'items' => $faqs, 'tint' => true]);
    }

    // ---------------------------------------------------------------
    public function viewContext(): array
    {
        $base = ['entity' => $this->entity, 'site' => $this->site()];

        if ($this->isService()) {
            [$prev, $next] = $this->adjacent();

            return array_merge($base, [
                'scene' => $this->resource,
                'combo' => $this->combo(),
                'keyProduct' => $this->keyProduct(),
                'prev' => $prev,
                'next' => $next,
                // TD-63 grid context
                'catalog' => $this->resource,
            ]);
        }

        return array_merge($base, [
            'product' => $this->resource,
            'line' => $this->line(),
            'related' => $this->related(),
            'scenes' => $this->scenes(),
            // TD-63 grid context
            'catalog' => $this->resource,
            'lineSlug' => $this->resource['line'] ?? null,
        ]);
    }

    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $url = $this->publicUrl();
        $faqs = $this->faqs();

        if ($this->isService()) {
            $nodes = [
                $schema->organization(),
                $schema->breadcrumb($this->crumbs()),
                $schema->webPage($url, $this->resource['name'], $this->resource['desc'] ?? '', 'WebPage'),
            ];
            if ($faqs !== []) {
                $nodes[] = $schema->faqPageFromList($faqs, $url);
            }

            return array_values(array_filter($nodes));
        }

        $nodes = [
            $schema->organization(),
            $schema->breadcrumb($this->crumbs()),
            $schema->webPage($url, $this->resource['name'], $this->resource['tagline'], 'ItemPage', $url . '#product'),
            $this->productSchema(),
        ];
        if (! empty($this->resource['params'])) {
            $nodes[] = $this->howTo();
        }
        if ($faqs !== []) {
            $nodes[] = $schema->faqPageFromList($faqs, $url);
        }

        return array_values(array_filter($nodes));
    }

    private function productSchema(): array
    {
        $company = Catalog::company();
        $brandName = $this->locale() === 'en'
            ? ($company['name'] ?? '')
            : (! empty($company['brand']) ? $company['brand'] : ($company['name'] ?? ''));

        $props = [];
        foreach (($this->resource['key_params'] ?? []) as $kp) {
            $props[] = ['@type' => 'PropertyValue', 'name' => $kp['label'], 'value' => $kp['value']];
        }

        // 禁 offers / aggregateRating / review（无真实交易与评价数据）
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => PublicUrl::product($this->entity->slug) . '#product',
            'name' => $this->resource['name'],
            'description' => $this->resource['tagline'],
            'category' => $this->line()['name'] ?? null,
            'brand' => $brandName !== '' ? ['@type' => 'Brand', 'name' => $brandName] : null,
            'manufacturer' => ['@id' => PublicUrl::base() . '/#organization'],
            'additionalProperty' => $props,
        ]);
    }

    private function howTo(): array
    {
        $steps = [];
        foreach (($this->resource['params'] ?? []) as $i => $p) {
            $text = $p['value'];
            if (! empty($p['note'])) {
                $text .= '（' . $p['note'] . '）';
            }
            $steps[] = [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $p['step'],
                'text' => $text,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            '@id' => PublicUrl::product($this->entity->slug) . '#howto',
            'name' => __('seo.product_howto_name', ['name' => $this->resource['name']]),
            'step' => $steps,
        ];
    }

    // ---------------------------------------------------------------
    // Product 体系 SubNav（复刻 ProductController::subnav，与顶部导航下拉同源）
    // ---------------------------------------------------------------

    private function productSubnav(?string $active): array
    {
        $cur = trim(request()->path(), '/');

        $customOn = function (string $url, bool $external) use ($cur) {
            if ($external) {
                return false;
            }
            $p = trim((string) parse_url($url, PHP_URL_PATH), '/');

            return $p !== '' && ($cur === $p || str_starts_with($cur, $p . '/'));
        };

        $slugByUrl = [];
        foreach (Catalog::productLines() as $l) {
            $slugByUrl[trim((string) url('/products/' . $l['slug'] . '/'), '/')] = $l['slug'];
        }

        $items = [[
            'name' => __('nav.all_products'), 'slug' => null,
            'url' => url('/products/'), 'on' => $cur === 'products',
        ]];
        foreach (AppServiceProvider::mergedMenuChildren('products') as $ch) {
            $norm = trim((string) $ch['url'], '/');
            $slug = $slugByUrl[$norm] ?? null;
            $items[] = [
                'name' => $ch['name'],
                'slug' => $slug,
                'url' => $ch['url'],
                'external' => (bool) ($ch['external'] ?? false),
                'on' => $slug !== null
                    ? $slug === $active
                    : $customOn($ch['url'], (bool) ($ch['external'] ?? false)),
            ];
        }

        return ['items' => $items, 'active' => $active];
    }
}
