<?php

namespace App\Support\Render;

use App\Models\Page;
use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoResult;
use App\Support\Blocks\BlockContract;
use App\Support\Blocks\BlockRegistry;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateRegistry;

/**
 * 组合 Page 渲染上下文（Landing 等，P-STEP 18G）。
 * 块来自 Page 持久化 PageBlock；SEO 走 {@see SeoMetaResolver::resolvePage}。
 * 与 18G-1 renderPage 输出等价（schema：organization / website / breadcrumb，
 * hasSchema block 额外产出对应 JSON-LD）。
 */
class PageRenderContext implements RenderContext
{
    public function __construct(private readonly Page $page)
    {
        $this->page->loadMissing('blocks');
    }

    public function page(): Page
    {
        return $this->page;
    }

    public function site(): Site
    {
        return SiteContext::currentSite();
    }

    public function locale(): string
    {
        return (string) $this->page->locale;
    }

    public function resourceType(): string
    {
        return 'page';
    }

    public function template(): TemplateDefinition
    {
        return TemplateRegistry::get($this->page->template) ?: TemplateRegistry::get('landing');
    }

    public function seo(): SeoResult
    {
        return app(SeoMetaResolver::class)->resolvePage($this->page);
    }

    public function hasHero(): bool
    {
        return $this->page->blocks->contains(fn ($b) => $b->is_active && $b->type === 'hero');
    }

    public function crumbs(): array
    {
        $seo = $this->seo();

        return [
            ['name' => __('ui.back_home'), 'url' => PublicUrl::home()],
            ['name' => $seo->title, 'url' => $seo->canonical],
        ];
    }

    public function subnav(): ?array
    {
        return null;
    }

    public function blocksForSlot(string $slot): array
    {
        return $this->page->blocks()
            ->where('slot', $slot)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->all();
    }

    public function viewContext(): array
    {
        return [
            'page' => $this->page,
            'site' => $this->site(),
        ];
    }

    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $nodes = [
            $schema->organization(),
            $schema->website(),
            $schema->breadcrumb($this->crumbs()),
        ];

        // hasSchema block（faq）产出与可见内容一致的 JSON-LD。
        $canonical = $this->seo()->canonical;
        foreach ($this->page->blocks()->where('is_active', true)->get() as $b) {
            $t = BlockRegistry::get($b->type);
            if ($t !== null && $t->hasSchema) {
                $faq = $schema->faqPageFromList($b->cfg()['items'] ?? [], $canonical);
                if ($faq !== null) {
                    $nodes[] = $faq;
                }
            }
        }

        return array_values(array_filter($nodes));
    }
}
