<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\PublicIndex;
use Illuminate\Http\Request;

/**
 * 站内搜索
 */
class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $items = null;

        if (mb_strlen($q) >= 2) {
            // PublicIndex 在查询层排除 noindex / 停用栏目 / 他站内容，对分页安全
            // （不会像取集合后 reject 那样破坏每页条数）。
            $items = PublicIndex::contentQuery()
                ->with('category')
                ->where(function ($w) use ($q) {
                    $w->where('title', 'like', "%{$q}%")
                      ->orWhere('summary', 'like', "%{$q}%")
                      ->orWhere('body', 'like', "%{$q}%");
                })
                ->orderByDesc('published_at')
                ->paginate(10)
                ->withQueryString();
        }

        return view('site.search', [
            'q'      => $q,
            'items'  => $items,
            'crumbs' => [['name' => '搜索', 'url' => url('/search')]],
            'schemas' => [],
            'seo' => [
                'title'       => $q !== '' ? "搜索：{$q}" : '站内搜索',
                'description' => '站内内容检索',
                'canonical'   => url('/search'),
                'noindex'     => true,   // 搜索结果页不进索引
                'type'        => 'website',
            ],
        ]);
    }
}
