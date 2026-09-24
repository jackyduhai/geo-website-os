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
 * 关于我们：企业简介 / 发展历程 / 企业文化（三子页，is_system Page + sys_about）。
 *
 * P-STEP 18G-2b：三子页统一 SystemPageRenderContext（system_key = profile /
 * history / culture），主体 sys_about 按 aboutPage 分支；业务事实来自 Catalog /
 * Pages，不复制进 Page；SEO 经 resolver（page-level SeoMeta → 页面默认 → 站点）。
 */
class AboutController extends Controller
{
    private const PAGES = ['profile', 'history', 'culture'];

    public function page(string $page, SchemaBuilder $schema)
    {
        abort_if(! in_array($page, self::PAGES, true), 404);

        $company = Catalog::company();
        $brand   = Catalog::brandLanguage();
        $copy    = Pages::about($page);

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
            'profile' => __('seo.about_profile_title'),
            'history' => __('seo.about_history_title'),
            'culture' => __('seo.about_culture_title'),
        ];

        // 关于类 SubNav：与顶部导航「关于我们」下拉同源（固定项 + 后台挂接的自定义二级项）
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

        $pageUrl = PublicUrl::url('about/' . $page . '/');
        $aboutPageSchema = $schema->webPage(
            $pageUrl,
            $titles[$page],
            $copy['meta_desc'] ?? '',
            'AboutPage'
        );
        $desc = $copy['meta_desc']
            ?? __('seo.about_desc_fallback', ['name' => $company['name'] ?? '']);

        $resource = [
            'company'   => $company,
            'brand'     => $brand,
            'copy'      => $copy,
            'workshops' => Catalog::workshops(),
            'regions'   => Catalog::salesRegions(),
            'lead'      => $lead,
            'bodyHtml'  => $bodyHtml,
            'nodes'     => $nodes,
            'subnav'    => $subnav,
            'pageKey'   => $page,
            'schemas'   => array_values(array_filter([$aboutPageSchema])),
            'seo_default' => [
                'title'       => $titles[$page] . '｜' . ($company['name'] ?? ''),
                'description' => $desc,
            ],
        ];

        return app(CompositionRenderer::class)->render(
            new SystemPageRenderContext(SystemPageRenderContext::resolve($page), $page, $resource)
        );
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
            $nodes[] = ['time' => $company['founded_display'], 'title' => __('seo.about_founded_title'), 'desc' => __('seo.about_founded_desc')];
        }
        if (! empty($company['established_production_display'])) {
            $nodes[] = ['time' => $company['established_production_display'], 'title' => __('seo.about_production_title'), 'desc' => __('seo.about_production_desc')];
        }

        return $nodes;
    }
}
