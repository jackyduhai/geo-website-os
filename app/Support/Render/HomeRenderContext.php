<?php

namespace App\Support\Render;

use App\Models\Page;
use App\Services\Geo\SchemaBuilder;
use App\Support\Blocks\BlockRegistry;
use App\Support\Localization\LocaleContext;
use App\Support\SiteContext;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateRegistry;

/**
 * 首页渲染上下文（P-STEP 18I / TD-70）。
 * --------------------------------------------------
 * 首页不再由旧「首页整体装修」（page='home' PageBlock + HomeController 手工数据链）
 * 驱动，而是正式进入 Page Composition：
 *
 *   is_home Page ── CompositionRenderer ── home Template ── Page Blocks
 *
 * 块来自 Page 持久化 PageBlock（page_id）；SEO 走 {@see \App\Services\Seo\SeoMetaResolver::resolvePage}
 * （出厂 Page title 为空，自动回退站点级，等价旧 resolveSite）。
 *
 * 出厂 blank 首页 Page 无区块，由 site.composed 在 home 模板空 main 槽渲染中性欢迎屏
 * （site/home/_welcome），管理员一旦添加区块即替换，无需改代码。
 */
class HomeRenderContext extends PageRenderContext
{
    /**
     * 取当前站点 / 语言的首页载体 Page：优先持久化 is_home Page（installer 注入，承载
     * 管理员 Composition / page-level SEO）；缺失则构造未保存默认 Page，保证首页不因
     * 记录缺失而报错（是否展示内容仍由区块 / 欢迎屏决定）。
     */
    public static function resolve(): Page
    {
        $locale = LocaleContext::current();
        $site = SiteContext::currentSite();

        $page = Page::query()
            ->where('site_id', $site->id)
            ->where('is_home', true)
            ->where('locale', $locale)
            ->first();

        if ($page !== null) {
            return $page;
        }

        return new Page([
            'site_id'   => $site->id,
            'template'  => 'home',
            'slug'      => null,
            'is_home'   => true,
            'is_system' => false,
            'system_key' => null,
            'status'    => Page::STATUS_PUBLISHED,
            'locale'    => $locale,
            'title'     => '',
        ]);
    }

    public function resourceType(): string
    {
        return 'home';
    }

    public function template(): TemplateDefinition
    {
        return TemplateRegistry::get('home') ?: parent::template();
    }

    public function subnav(): ?array
    {
        return null;
    }

    /**
     * 首页 schema：organization + website（首页是面包屑根，不输出仅指向自己的
     * BreadcrumbList）；hasSchema block（faq）产出与可见内容一致的 JSON-LD。
     */
    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $nodes = [
            $schema->organization(),
            $schema->website(),
        ];

        $canonical = $this->seo()->canonical;
        foreach ($this->page()->blocks()->where('is_active', true)->get() as $b) {
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
