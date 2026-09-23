<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Narrative;
use App\Support\Pages;
use App\Support\PublicUrl;

/**
 * 联系我们：左侧公司信息 / 右侧统一咨询表单（移动端表单提前）。
 * LocalBusiness：经纬度未核定前整段省略 geo，不估算坐标。本页不输出 BottomCTA。
 */
class ContactController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $company = Catalog::company();
        // 配置契约降级（P-STEP 04）：无业务数据时该业务页不渲染（404），不抛错
        if (empty($company)) {
            abort(404);
        }
        $lead = Narrative::lead('contact.lead', Pages::narrative('contact'));

        $localBusiness = array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            '@id'         => PublicUrl::url('contact/') . '#business',
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

        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.contact'), 'url' => PublicUrl::url('contact/')],
        ];

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
            'name' => $company['name'],
            'bits' => $contactBits ? implode($isEn ? ' ' : '，', $contactBits) . ($isEn ? ' ' : '。') : '',
        ]);

        return view('site.contact', [
            'company' => $company,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $schema->webPage(PublicUrl::url('contact/'), __('seo.contact_title'), $contactDesc, 'ContactPage', PublicUrl::url('contact/') . '#business'),
                $localBusiness,
            ])),
            'seo' => [
                'title'       => __('seo.contact_title'),
                'description' => $contactDesc,
                'canonical'   => PublicUrl::url('contact/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
