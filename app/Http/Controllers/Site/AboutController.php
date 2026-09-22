<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;

/**
 * 关于我们：企业简介 / 发展历程 / 企业文化（三子页，config facts + pages 成稿）
 */
class AboutController extends Controller
{
    private const PAGES = ['profile', 'history', 'culture'];

    public function page(string $page, SchemaBuilder $schema)
    {
        abort_if(! in_array($page, self::PAGES, true), 404);

        $company = Catalog::company();
        $brand   = Catalog::brandLanguage();
        $copy    = config('pages.about.' . $page, []);

        // 配置契约降级（P-STEP 04）：无业务数据时该业务页不渲染（404），不抛错
        if (empty($company) || empty($copy)) {
            abort(404);
        }

        // 可运营叙事：页头导语（三页）+ 企业简介正文（profile），缺省回退终稿文案
        $lead = Narrative::lead('about.' . $page, $copy['lead'] ?? '');
        $bodyHtml = null;
        if ($page === 'profile') {
            $defaultHtml = collect($copy['paragraphs'] ?? [])
                ->map(static fn ($p) => '<p>' . e($p) . '</p>')->implode('');
            $bodyHtml = Narrative::html('about.profile', $defaultHtml);
        }

        // 发展历程节点：config 未内置时从站点事实（成立 / 投产时间）数据驱动生成，
        // 出厂不内置任何虚构年份或行业节点。
        $nodes = $copy['nodes'] ?? [];
        if ($page === 'history' && empty($nodes)) {
            $nodes = $this->historyNodesFromFacts($company);
        }

        $titles = [
            'profile' => '企业简介',
            'history' => '发展历程',
            'culture' => '企业文化',
        ];

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '关于我们', 'url' => url('/about/profile/')],
            ['name' => $titles[$page], 'url' => url('/about/' . $page . '/')],
        ];

        // 关于类 SubNav：与顶部导航「关于我们」下拉同源（固定四项 + 后台挂接的自定义二级项）
        $cur = trim(request()->path(), '/');
        $aboutItems = [];
        foreach (\App\Providers\AppServiceProvider::mergedMenuChildren('about') as $ch) {
            $path = trim((string) parse_url($ch['url'], PHP_URL_PATH), '/');
            $slug = preg_match('#(?:^|/)(profile|history|culture|contact)$#', $path, $m) ? $m[1] : null;
            $external = (bool) ($ch['external'] ?? false);
            $customOn = ! $external && $path !== '' && ($cur === $path || str_starts_with($cur, $path . '/'));
            $aboutItems[] = [
                'name'     => $ch['name'],
                'slug'     => $slug,
                'url'      => $ch['url'],
                'external' => $external,
                'on'       => $slug !== null ? $slug === $page : $customOn,
            ];
        }
        $subnav = ['items' => $aboutItems, 'active' => $page];

        return view('site.about.' . $page, [
            'company' => $company,
            'brand'   => $brand,
            'copy'    => $copy,
            'workshops' => Catalog::workshops(),
            'regions'  => Catalog::salesRegions(),
            'crumbs'  => array_slice($crumbs, 1),
            'subnav'  => $subnav,
            'pageKey' => $page,
            'lead'    => $lead,
            'bodyHtml' => $bodyHtml,
            'nodes'   => $nodes,
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
            ])),
            'seo' => [
                'title'       => $titles[$page] . '｜' . ($company['name'] ?? ''),
                'description' => $copy['meta_desc']
                    ?? ('了解' . ($company['name'] ?? '') . '的企业概况、产品与服务。'),
                'canonical'   => url('/about/' . $page . '/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    /**
     * 从站点事实生成发展历程节点（成立 / 生产基地投产），行业中立、数据驱动。
     *
     * @param  array<string,mixed>  $company
     * @return array<int,array{time:string,title:string,desc:string}>
     */
    private function historyNodesFromFacts(array $company): array
    {
        $nodes = [];
        if (! empty($company['founded_display'])) {
            $nodes[] = ['time' => $company['founded_display'], 'title' => '公司成立', 'desc' => '公司注册成立，开始正式运营。'];
        }
        if (! empty($company['established_production_display'])) {
            $nodes[] = ['time' => $company['established_production_display'], 'title' => '生产基地投产', 'desc' => '生产或服务能力建成并投入使用。'];
        }

        return $nodes;
    }
}
