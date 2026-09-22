<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;

/**
 * 工厂与资质（独立一级页面，config/facts 驱动）
 * 硬规则：资质编号 / 执行标准号缺失时，资质区块整体隐藏；车间无实拍图时不渲染图片，
 * 不使用渲染图占位；销售区域用纯文字（无合规地图前不放地图）。
 */
class FactoryController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $company    = Catalog::company();
        // 配置契约降级（P-STEP 04）：无业务数据时该业务页不渲染（404），不抛错
        if (empty($company)) {
            abort(404);
        }
        // P-STEP 17G / Public Render Contract：只有公司名、无任何生产实质（车间 /
        // 厂区面积 / 年产能均空）的最小站点，/factory/ 必须 404，不渲染全 0 空壳，
        // 也不得被 sitemap / llms 收录为会 404 的 URL。
        if (! Catalog::hasProduction()) {
            abort(404);
        }
        $workshops  = Catalog::workshops();
        $regions    = Catalog::salesRegions();
        $certsReady = Catalog::certificationsReady();
        $steps      = config('pages.factory_steps', []);

        $workshopNames = implode('、', array_map(fn ($w) => $w['name'], $workshops));

        // 可运营叙事：页头导语（默认含投产时间，硬数据仍读 facts）
        $defaultLead = $workshopNames . '，' . count($workshops) . '个车间都在自己厂里。不外包，不做贸易。'
            . ($company['established_production_display'] ?? '') . '全面投产。';
        $lead = Narrative::lead('factory.lead', $defaultLead);

        // 5 条信任数据（全部源自 facts，不虚构）
        $stats = [
            ['num' => (int) ($company['tech_experience_years'] ?? 0), 'unit' => '年', 'label' => ($company['industry'] ?? '') . '领域经验'],
            ['num' => (int) ($company['area_sqm'] ?? 0), 'unit' => '㎡', 'label' => '自有生产厂区'],
            ['num' => (int) ($company['annual_capacity_tons'] ?? 0), 'unit' => '吨', 'label' => '年成品产能'],
            ['num' => count($workshops), 'unit' => '大', 'label' => '自有生产车间'],
            ['num' => count($regions), 'unit' => '大区', 'label' => '全国销售覆盖'],
        ];

        // 仅当存在真实车间图时才输出 ImageObject
        $imageObjects = [];
        foreach ($workshops as $w) {
            if (! empty($w['image'])) {
                $imageObjects[] = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'ImageObject',
                    'contentUrl' => asset($w['image']),
                    'caption'    => $w['image_alt'] ?? $w['name'],
                ];
            }
        }

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '工厂与资质', 'url' => url('/factory/')],
        ];

        return view('site.factory', [
            'company'    => $company,
            'workshops'  => $workshops,
            'regions'    => $regions,
            'stats'      => $stats,
            'steps'      => $steps,
            'lead'       => $lead,
            'certsReady' => $certsReady,
            'certs'      => Catalog::certifications(),
            'crumbs'     => array_slice($crumbs, 1),
            'schemas'    => array_values(array_filter(array_merge([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
            ], $imageObjects))),
            'seo' => [
                'title'       => '工厂与资质：' . ($company['area_display'] ?? '') . '厂区、' . count($workshops) . '大车间',
                'description' => ($company['name'] ?? '') . '自有' . ($company['area_display'] ?? '') . '厂区，设'
                    . $workshopNames . count($workshops) . '大车间，年产能' . ($company['annual_capacity_display'] ?? '')
                    . '，覆盖全国' . count($regions) . '大销售区域。',
                'canonical'   => url('/factory/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
