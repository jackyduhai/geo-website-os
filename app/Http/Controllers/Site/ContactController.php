<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Facts;
use App\Support\Narrative;

/**
 * 联系我们：左侧公司信息 / 右侧统一咨询表单（移动端表单提前）。
 * LocalBusiness：经纬度未核定前整段省略 geo，不估算坐标。本页不输出 BottomCTA。
 */
class ContactController extends Controller
{
    public function show(SchemaBuilder $schema)
    {
        $company = Facts::company();
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
            'areaServed' => Facts::salesRegions(),
        ]);

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '联系我们', 'url' => url('/contact/')],
        ];

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
                'title'       => '联系我们｜获取样品与定制方案',
                'description' => '联系Sample CityExample Food：全国合作热线 400-000-0000，厂区位于Sample Province省Sample City市沈河区 Example Street 39。填写表单或致电，我们安排寄样与定制方案。',
                'canonical'   => url('/contact/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
