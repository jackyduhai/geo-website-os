<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicIndex;
use App\Support\PublicUrl;
use App\Support\SearchResult;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 站内搜索（Content + Entity 统一搜索）。
 * ------------------------------------------------------------------
 * P-STEP 18F：搜索不再只覆盖 Content，也纳入拥有公开落地页的 Entity
 * （核心产品 → /products/{slug}、应用场景 → /solutions/{slug}/）。
 * 两类结果统一映射为 {@see SearchResult}，并在查询层经 PublicIndex 满足
 * Public Render Contract（已发布 / 非 noindex / 当前站点 / 当前语言），
 * 因此搜索不会引导用户到达 404、Draft 或他站页面。
 */
class SearchController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $locale = LocaleContext::current();
        $isEn = $locale !== LocaleRegistry::default();
        $results = collect();

        if (mb_strlen($q) >= 2) {
            // 1) 内容（文章 / 单页）
            PublicIndex::contentQuery()
                ->forLocale($locale)
                ->where(function ($w) use ($q) {
                    $w->where('title', 'like', "%{$q}%")
                      ->orWhere('summary', 'like', "%{$q}%")
                      ->orWhere('body', 'like', "%{$q}%");
                })
                ->orderByDesc('published_at')
                ->get()
                ->each(function ($c) use ($results): void {
                    $results->push(new SearchResult(
                        title: (string) $c->title,
                        summary: (string) ($c->summary ?? ''),
                        url: PublicUrl::content($c),
                        kind: 'content',
                    ));
                });

            // 2) 实体（仅纳入拥有公开落地页的产品 / 场景，避免结果链接 404）
            PublicIndex::entityQuery()
                ->forLocale($locale)
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('summary', 'like', "%{$q}%")
                      ->orWhere('description', 'like', "%{$q}%");
                })
                ->orderBy('sort_order')
                ->get()
                ->each(function ($e) use ($results): void {
                    $url = PublicUrl::entity($e);
                    if ($url === null) {
                        return;
                    }
                    $results->push(new SearchResult(
                        title: (string) $e->name,
                        summary: (string) ($e->summary ?? ''),
                        url: $url,
                        kind: (string) $e->type,
                    ));
                });
        }

        $page = max(1, (int) $request->get('page', 1));
        $items = new LengthAwarePaginator(
            $results->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            $results->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url()]
        );
        $items->withQueryString();

        return view('site.search', [
            'q'      => $q,
            'items'  => $items,
            'crumbs' => [['name' => $isEn ? 'Search' : '搜索', 'url' => PublicUrl::url('search')]],
            'schemas' => [],
            'seo' => [
                'title'       => $q !== ''
                    ? ($isEn ? "Search: {$q}" : "搜索：{$q}")
                    : ($isEn ? 'Search' : '站内搜索'),
                'description' => $isEn ? 'Site content search' : '站内内容检索',
                'canonical'   => PublicUrl::url('search'),
                'noindex'     => true,   // 搜索结果页不进索引
                'type'        => 'website',
            ],
        ]);
    }
}
