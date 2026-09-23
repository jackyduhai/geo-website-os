<?php

namespace App\Support\Render;

use App\Support\Blocks\BlockRegistry;
use Illuminate\Contracts\View\View;

/**
 * 统一组合渲染器（P-STEP 18G-2，路线 A）。
 * --------------------------------------------------
 * 遍历 Render Context 解析出的模板槽位与块，逐块经 {@see BlockRegistry} 渲染，
 * 组装为 site.composed 页面。Page / Entity（Listing 于 2b）共用本渲染器；
 * 控制器只负责取资源与构建 context，不再手工拼 SEO / 模板。
 */
class CompositionRenderer
{
    public function render(RenderContext $context): View
    {
        $template = $context->template();

        $slotsHtml = [];
        foreach ($template->slotNames() as $slotName) {
            $html = '';
            $slotContext = array_merge($context->viewContext(), ['slot' => $slotName]);
            foreach ($context->blocksForSlot($slotName) as $block) {
                $html .= BlockRegistry::render($block, $slotContext);
            }
            $slotsHtml[$slotName] = $html;
        }

        // viewContext 展开到顶层：SeoHeadComposer 需读 product / scene 推断资源级 hreflang。
        $data = array_merge($context->viewContext(), [
            'context' => $context,
            'template' => $template,
            'slotsHtml' => $slotsHtml,
            'subnav' => $context->subnav(),
            'schemas' => $context->schemaNodes(),
            'hasHero' => $context->hasHero(),
            'seo' => $this->seoArray($context),
        ]);

        return view('site.composed', $data);
    }

    /** SeoResult → SeoHeadComposer 部分键数组（其余键由 composer 归一化补全）。 */
    private function seoArray(RenderContext $context): array
    {
        $s = $context->seo();

        return [
            'title' => $s->title,
            'description' => $s->description,
            'canonical' => $s->canonical,
            'noindex' => $s->noindex,
            'type' => $s->ogType,
            'image' => $s->ogImage,
            'og_title' => $s->ogTitle,
            'og_description' => $s->ogDescription,
            'twitter_card' => $s->twitterCard,
        ];
    }
}
