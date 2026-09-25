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
 * 联系我们（is_system Page + 可组合 block）。
 *
 * P-STEP 18G-2b：联系页统一 SystemPageRenderContext（system_key 'contact'），
 * 但主体为可编辑普通 block——header rich_text + main contact_info / form_reference
 * （由 SystemPageSeeder 注入、管理员可增删改，Scenario A）；LocalBusiness 经纬度
 * 未核定前整段省略 geo，不估算坐标。SEO 经 resolver（page-level SeoMeta → 页面默认
 * → 站点）。
 */
class ContactController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        // P-STEP 18I / TD-100：联系页已 Composition 化（contact_info 取 Site settings、
        // form_reference 取默认表单），不再依赖 company fact 才可访问。company 仅用于
        // LocalBusiness schema 与完整联系事实，缺失时这些段落省略、页面仍渲染（零代码建站）。
        $company = Catalog::company() ?: [];
        $lead = Narrative::lead('contact.lead', Pages::narrative('contact'));

        $contactUrl = PublicUrl::url('contact/');
        $localBusiness = empty($company) ? [] : array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            '@id'         => $contactUrl . '#business',
            'name'        => $company['name'],
            'url'         => PublicUrl::home(),
            'telephone'   => $company['phone'] ?? null,
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $company['address']['street'] ?? null,
                'addressLocality' => $company['address']['city'] ?? null,
                'addressRegion'   => $company['address']['province'] ?? null,
                'addressCountry'  => $company['address']['country'] ?? null,
            ],
            'areaServed' => Catalog::salesRegions(),
        ]);

        $isEn = LocaleContext::current() === 'en';
        $contactBits = [];
        if (! empty($company['phone'])) {
            $contactBits[] = __('seo.contact_hotline', ['phone' => $company['phone']]);
        }
        // address.full 为共享单语中文，英文页暂不附加（避免中英混杂）。
        if (! $isEn && ! empty($company['address']['full'])) {
            $contactBits[] = __('seo.contact_address', ['address' => $company['address']['full']]);
        }
        $contactDesc = __('seo.contact_desc', [
            'name' => $company['name'] ?? '',
            'bits' => $contactBits ? implode($isEn ? ' ' : '，', $contactBits) . ($isEn ? ' ' : '。') : '',
        ]);

        $contactPageSchema = $schema->webPage(
            $contactUrl,
            __('seo.contact_title'),
            $contactDesc,
            'ContactPage',
            $contactUrl . '#business'
        );

        // P-STEP 18G-2b：联系页走统一 Composition 管线；blocks 由 Page 持久化。
        $resource = [
            'company' => $company,
            'lead'    => $lead,
            'schemas' => array_values(array_filter([$contactPageSchema, $localBusiness])),
            'seo_default' => [
                'title'       => __('seo.contact_title'),
                'description' => $contactDesc,
            ],
        ];

        return app(CompositionRenderer::class)->render(
            new SystemPageRenderContext(SystemPageRenderContext::resolve('contact'), 'contact', $resource)
        );
    }
}
