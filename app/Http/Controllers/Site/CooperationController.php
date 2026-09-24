<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Narrative;
use App\Support\Pages;
use App\Support\PublicUrl;
use App\Support\Render\CompositionRenderer;
use App\Support\Render\SystemPageRenderContext;

/**
 * 合作方式（is_system Page + sys_cooperation）：多种合作模式 + 合作流程 + FAQ。
 *
 * P-STEP 18G-2b：合作页统一 SystemPageRenderContext（system_key 'cooperation'），
 * 主体 sys_cooperation；结构化数据来自 Catalog / Pages，起订量 / 交付周期未核定前
 * 对应字段隐藏，不输出占位符。SEO 经 resolver（page-level SeoMeta → 页面默认 → 站点）。
 */
class CooperationController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $coop = Catalog::cooperation();
        // 配置契约降级（P-STEP 04）：无业务数据时该业务页不渲染（404），不抛错
        if (empty($coop)) {
            abort(404);
        }
        $faqs = Pages::faqs('cooperation_faqs');
        // 该 URL 同时用于 canonical、HowTo / FAQ JSON-LD 与面包屑，属声明性地址，经 PublicUrl 裁决（TD-09）。
        $url  = PublicUrl::url('cooperation/');
        $lead = Narrative::lead('cooperation.lead', Pages::narrative('cooperation'));

        $company     = Catalog::company();
        $typeNames   = implode(LocaleContext::current() === 'en' ? ', ' : '、', array_map(fn ($t) => $t['name'], $coop['types'] ?? []));
        $typeCnt     = count($coop['types'] ?? []);
        $stepCnt     = count($coop['process'] ?? []);

        // 合作流程 → HowTo（步数由 process 数据驱动）
        $howTo = null;
        if (! empty($coop['process'])) {
            $howTo = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                '@id'      => $url . '#howto',
                'name'     => __('seo.cooperation_howto', ['count' => $stepCnt]),
                'step'     => array_map(function ($s, $i) {
                    return [
                        '@type'    => 'HowToStep',
                        'position' => $i + 1,
                        'name'     => $s['name'],
                        'text'     => $s['desc'],
                    ];
                }, array_values($coop['process']), array_keys($coop['process'])),
            ];
        }

        $coopTitle = __('seo.cooperation_title') . ($typeNames !== ''
            ? (LocaleContext::current() === 'en' ? ': ' : '：') . $typeNames
            : '');
        $coopDesc = __('seo.cooperation_desc', [
            'name' => $company['name'] ?? '',
            'types' => $typeNames,
            'count' => $typeCnt,
            'steps' => $stepCnt,
        ]);

        $coopPageSchema = $schema->webPage($url, __('seo.cooperation_title'), $coopDesc, 'WebPage');
        $faqPage = $schema->faqPageFromList($faqs, $url);

        // P-STEP 18G-2b：合作页走统一 Composition 管线（SystemPageRenderContext）。
        $resource = [
            'coop'    => $coop,
            'faqs'    => $faqs,
            'lead'    => $lead,
            'schemas' => array_values(array_filter([$coopPageSchema, $howTo, $faqPage])),
            'seo_default' => [
                'title'       => $coopTitle,
                'description' => $coopDesc,
            ],
        ];

        return app(CompositionRenderer::class)->render(
            new SystemPageRenderContext(SystemPageRenderContext::resolve('cooperation'), 'cooperation', $resource)
        );
    }
}
