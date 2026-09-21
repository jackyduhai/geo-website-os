<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;

/**
 * 合作方式：定制研发 / OEM·ODM 代工 / 经销合作 等合作模式 + 合作流程 + FAQ。
 * 结构化数据来自 config/facts（facts.yaml），FAQ 为 config/pages 终稿；
 * MOQ、打样/交付周期未核定前对应字段隐藏，不输出占位符。
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
        $faqs = config('pages.cooperation_faqs', []);
        $url  = url('/cooperation/');
        $lead = Narrative::lead('cooperation.lead', config('pages.narrative.cooperation.lead', ''));

        $company     = Catalog::company();
        $typeNames   = implode('、', array_map(fn ($t) => $t['name'], $coop['types'] ?? []));
        $typeCnt     = count($coop['types'] ?? []);
        $stepCnt     = count($coop['process'] ?? []);
        $workshopCnt = count(Catalog::workshops());

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '合作方式', 'url' => $url],
        ];

        // 合作流程 → HowTo（步数由 process 数据驱动）
        $howTo = null;
        if (! empty($coop['process'])) {
            $howTo = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                '@id'      => $url . '#howto',
                'name'     => '从需求沟通到持续供货的' . $stepCnt . '步合作流程',
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

        return view('site.cooperation', [
            'coop'    => $coop,
            'faqs'    => $faqs,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $howTo,
                $schema->faqPageFromList($faqs, $url),
            ])),
            'seo' => [
                'title'       => '合作方式：定制研发、OEM/ODM 代工与经销',
                'description' => ($company['name'] ?? '') . '提供' . $typeNames . $typeCnt
                    . '种合作方式，自有' . $workshopCnt . '大车间、年产能'
                    . ($company['annual_capacity_display'] ?? '') . '，' . $stepCnt . '步完成从需求沟通到稳定供货。',
                'canonical'   => $url,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
