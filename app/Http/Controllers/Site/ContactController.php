<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;

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
        $lead = Narrative::lead('contact.lead', config('pages.narrative.contact.lead', ''));

        $localBusiness = array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            '@id'         => url('/contact/') . '#business',
            'name'        => $company['name'],
            'url'         => url('/'),
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
            ['name' => '首页', 'url' => url('/')],
            ['name' => '联系我们', 'url' => url('/contact/')],
        ];

        $contactBits = [];
        if (! empty($company['phone'])) {
            $contactBits[] = '合作热线 ' . $company['phone'];
        }
        if (! empty($company['address']['full'])) {
            $contactBits[] = '厂区位于' . $company['address']['full'];
        }
        $contactDesc = '联系' . $company['name'] . '：'
            . ($contactBits ? implode('，', $contactBits) . '。' : '')
            . '填写表单或通过页面上的联系方式与我们沟通，我们安排试样与定制方案。';

        return view('site.contact', [
            'company' => $company,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $localBusiness,
            ])),
            'seo' => [
                'title'       => '联系我们｜获取报价与样品',
                'description' => $contactDesc,
                'canonical'   => url('/contact/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
