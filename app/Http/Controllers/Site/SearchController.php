<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
use App\Support\Search\CjkTokenizer;
use App\Support\Search\SearchEngineInterface;
use App\Support\Search\SearchQuery;
use App\Support\SiteContext;
use Illuminate\Http\Request;

/**
 * 站内搜索（Content + Entity 统一搜索）。
 * ------------------------------------------------------------------
 * P-STEP 18H-1：控制器只构造引擎无关的 {@see SearchQuery} 并交给
 * {@see SearchEngineInterface}——SQLite 走 FTS5（CJK bigram 召回 / BM25 标题优先 /
 * DB 层分页），其他驱动回退 LIKE。索引在构建期已满足 Public Render Contract
 * （已发布 / 非 noindex / 当前站点 / 当前语言），搜索不会引导用户到达 404、Draft
 * 或他站页面。控制器不再自行 LIKE、全量取回或内存分页。
 */
class SearchController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request, SearchEngineInterface $engine)
    {
        $q = trim((string) $request->get('q', ''));
        $locale = LocaleContext::current();
        $page = max(1, (int) $request->get('page', 1));

        $results = $engine->search(new SearchQuery(
            term: $q,
            locale: $locale,
            siteId: (int) SiteContext::currentSiteId(),
            page: $page,
            perPage: self::PER_PAGE,
        ));

        // 分页链接保留检索词；path 用当前请求 URL（路由已含 locale 前缀）。
        $items = $results->paginator($request->url(), $q !== '' ? ['q' => $q] : []);

        return view('site.search', [
            'q'       => $q,
            'items'   => $items,
            'terms'   => CjkTokenizer::highlightTerms($q),
            'crumbs'  => [['name' => __('ui.search_h1'), 'url' => PublicUrl::url('search')]],
            'schemas' => [],
            'seo' => [
                'title'       => $q !== ''
                    ? __('seo.search_title_q', ['q' => $q])
                    : __('seo.search_title'),
                'description' => __('seo.search_desc'),
                'canonical'   => PublicUrl::url('search'),
                'noindex'     => true,   // 搜索结果页不进索引
                'type'        => 'website',
            ],
        ]);
    }
}
