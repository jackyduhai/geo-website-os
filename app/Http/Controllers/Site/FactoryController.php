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
 * 工厂与资质（is_system Page + sys_factory）。
 *
 * P-STEP 18G-2b：工厂页统一 SystemPageRenderContext（system_key 'factory'），
 * 主体 sys_factory；硬规则保留——资质编号 / 执行标准号缺失时资质区块整体隐藏；
 * 车间无实拍图时不渲染图片（用统一线性图标）；销售区域纯文字。
 *
 * 可见性门禁：只有公司名、无任何生产实质（车间 / 面积 / 产能均空）的最小站点，
 * /factory/ 必须 404（Catalog::hasProduction），不渲染全 0 空壳，也不被 sitemap /
 * llms 收录为会 404 的 URL。
 */
class FactoryController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $company = Catalog::company();
        // 配置契约降级（P-STEP 04）：无业务数据时该业务页不渲染（404），不抛错
        if (empty($company)) {
            abort(404);
        }
        // P-STEP 17G / Public Render Contract：无生产实质的最小站点 /factory/ 404。
        if (! Catalog::hasProduction()) {
            abort(404);
        }
        $workshops  = Catalog::workshops();
        $regions    = Catalog::salesRegions();
        $certsReady = Catalog::certificationsReady();
        $steps      = Pages::factorySteps();

        $workshopNames = implode('、', array_map(fn ($w) => $w['name'], $workshops));

        // 可运营叙事：页头导语（默认含投产时间，硬数据仍读 facts）
        $isEn = LocaleContext::current() === 'en';
        $prodYear = ! empty($company['established_production'])
            ? substr((string) $company['established_production'], 0, 4) : '';
        $defaultLead = $isEn
            ? ((count($workshops) > 0 ? count($workshops) . ' in-house production facilities, ' : '')
                . 'with stable production and delivery capacity'
                . ($prodYear !== '' ? ', fully operational since ' . $prodYear : '') . '.')
            : (($workshopNames !== '' ? $workshopNames . '等' . count($workshops) . '处自有生产设施，' : '')
                . '具备稳定的生产与交付能力'
                . (! empty($company['established_production_display']) ? '，' . $company['established_production_display'] . '全面投产' : '') . '。');
        $lead = Narrative::lead('factory.lead', $defaultLead);

        // 5 条信任数据（全部源自 facts，不虚构）；单位 / 标签按 locale。
        $stats = [
            ['num' => (int) ($company['tech_experience_years'] ?? 0), 'unit' => __('seo.factory_unit_year'), 'label' => $isEn
                ? __('seo.factory_stat_exp_label')
                : __('seo.factory_stat_exp_label', ['industry' => $company['industry'] ?? ''])],
            ['num' => (int) ($company['area_sqm'] ?? 0), 'unit' => '㎡', 'label' => __('seo.factory_stat_area_label')],
            ['num' => (int) ($company['annual_capacity_tons'] ?? 0), 'unit' => __('seo.factory_unit_ton'), 'label' => __('seo.factory_stat_capacity_label')],
            ['num' => count($workshops), 'unit' => __('seo.factory_unit_workshop'), 'label' => __('seo.factory_stat_workshops_label')],
            ['num' => count($regions), 'unit' => __('seo.factory_unit_region'), 'label' => __('seo.factory_stat_regions_label')],
        ];

        // 只展示真实非 0 的信任数据，缺失事实不渲染对应数据条（与首页 buildStats 一致）
        $stats = array_values(array_filter($stats, static fn ($r) => (int) $r['num'] > 0));

        // SEO 标题 / 描述只拼接真实存在的事实，缺失项跳过；en 用原始数值，不用中文 display。
        $areaText = $isEn ? number_format((int)($company['area_sqm'] ?? 0)) . ' ㎡' : ($company['area_display'] ?? '');
        $capacityText = $isEn
            ? number_format((int)($company['annual_capacity_tons'] ?? 0)) . ' ' . __('seo.factory_unit_ton')
            : ($company['annual_capacity_display'] ?? '');
        $seoTitleParts = [];
        if ((int)($company['area_sqm'] ?? 0) > 0) { $seoTitleParts[] = __('seo.factory_area_part', ['area' => $areaText]); }
        if (count($workshops) > 0) { $seoTitleParts[] = __('seo.factory_workshop_part', ['count' => count($workshops)]); }

        $descParts = [__('seo.factory_desc_head', ['name' => $company['name'] ?? ''])];
        if ((int)($company['area_sqm'] ?? 0) > 0) { $descParts[] = __('seo.factory_desc_area', ['area' => $areaText]); }
        if ($isEn) {
            if (count($workshops) > 0) { $descParts[] = __('seo.factory_desc_workshops', ['count' => count($workshops)]); }
        } elseif ($workshopNames !== '') {
            $descParts[] = __('seo.factory_desc_workshops', ['names' => $workshopNames, 'count' => count($workshops)]);
        }
        if (($isEn && (int)($company['annual_capacity_tons'] ?? 0) > 0) || (! $isEn && ! empty($company['annual_capacity_display']))) {
            $descParts[] = __('seo.factory_desc_capacity', ['capacity' => $capacityText]);
        }
        if (count($regions) > 0) { $descParts[] = __('seo.factory_desc_regions', ['count' => count($regions)]); }

        $factoryTitle = __('seo.factory_title') . ($seoTitleParts
            ? ($isEn ? ': ' : '：') . implode($isEn ? ', ' : '、', $seoTitleParts) : '');
        $factoryDesc = implode($isEn ? ', ' : '，', $descParts) . ($isEn ? '.' : '。');

        // 仅当存在真实车间图时才输出 ImageObject
        $imageObjects = [];
        foreach ($workshops as $w) {
            if (! empty($w['image'])) {
                $img = (string) $w['image'];
                $imageObjects[] = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'ImageObject',
                    // JSON-LD 声明性图片地址：外链原样，站内资源经 PublicUrl 裁决规范 host（TD-09）
                    'contentUrl' => preg_match('#^https?://#', $img) ? $img : PublicUrl::url(ltrim($img, '/')),
                    'caption'    => $w['image_alt'] ?? $w['name'],
                ];
            }
        }

        $factoryUrl = PublicUrl::url('factory/');
        $factoryPageSchema = $schema->webPage($factoryUrl, $factoryTitle, $factoryDesc, 'WebPage');

        // P-STEP 18G-2b：工厂页走统一 Composition 管线（SystemPageRenderContext）。
        $resource = [
            'company'    => $company,
            'workshops'  => $workshops,
            'regions'    => $regions,
            'stats'      => $stats,
            'steps'      => $steps,
            'lead'       => $lead,
            'certsReady' => $certsReady,
            'certs'      => Catalog::certifications(),
            'schemas'    => array_values(array_filter(array_merge([$factoryPageSchema], $imageObjects))),
            'seo_default' => [
                'title'       => $factoryTitle,
                'description' => $factoryDesc,
            ],
        ];

        return app(CompositionRenderer::class)->render(
            new SystemPageRenderContext(SystemPageRenderContext::resolve('factory'), 'factory', $resource)
        );
    }
}
