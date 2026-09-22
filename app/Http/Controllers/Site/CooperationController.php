<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;
use App\Support\PublicUrl;

/**
 * 合作方式：多种合作模式 + 合作流程 + FAQ（结构化数据来自 Catalog / facts）。
 * 结构化数据来自 config/facts（facts.yaml），FAQ 为 config/pages 终稿；
 * 起订量 / 交付周期未核定前对应字段隐藏，不输出占位符。
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
        // 该 URL 同时用于 canonical、HowTo / FAQ JSON-LD 与面包屑，属声明性地址，经 PublicUrl 裁决（TD-09）。
        $url  = PublicUrl::url('cooperation/');
        $lead = Narrative::lead('cooperation.lead', config('pages.narrative.cooperation.lead', ''));

        $company     = Catalog::company();
        $typeNames   = implode('、', array_map(fn ($t) => $t['name'], $coop['types'] ?? []));
        $typeCnt     = count($coop['types'] ?? []);
        $stepCnt     = count($coop['process'] ?? []);

        $crumbs = [
            ['name' => '首页', 'url' => PublicUrl::home()],
            ['name' => '合作方式', 'url' => $url],
        ];

        // 合作流程 → HowTo（步数由 process 数据驱动）
        $howTo = null;
        if (! empty($coop['process'])) {
            $howTo = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                '@id'      => $url . '#howto',
                'name'     => '从需求沟通到合作落地的' . $stepCnt . '步合作流程',
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
                'title'       => '合作方式' . ($typeNames !== '' ? '：' . $typeNames : ''),
                'description' => ($company['name'] ?? '') . '提供' . $typeNames . '等'
                    . $typeCnt . '种合作方式，' . $stepCnt . '步完成从需求沟通到合作落地，欢迎联系洽谈。',
                'canonical'   => $url,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
