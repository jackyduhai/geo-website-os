<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Facts;
use App\Support\Narrative;

/**
 * 合作方式：定制研发 / OEM·ODM 代工 / 经销合作 三模式 + 五步流程 + 6 条 FAQ。
 * 结构化数据来自 config/facts（facts.yaml），FAQ 为 config/pages 终稿；
 * MOQ、打样/交付周期未核定前对应字段隐藏，不输出占位符。
 */
class CooperationController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $coop = Facts::cooperation();
        $faqs = config('pages.cooperation_faqs', []);
        $url  = url('/cooperation/');
        $lead = Narrative::lead('cooperation.lead', config('pages.narrative.cooperation.lead', ''));

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '合作方式', 'url' => $url],
        ];

        // 五步流程 → HowTo
        $howTo = null;
        if (! empty($coop['process'])) {
            $howTo = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                '@id'      => $url . '#howto',
                'name'     => '从需求沟通到持续供货的五步合作流程',
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
                'description' => 'Example提供配方定制研发、OEM/ODM 代工与经销合作三种方式，自有四大车间、年产能约 8,000 吨，五步完成从需求沟通到稳定供货。',
                'canonical'   => $url,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
