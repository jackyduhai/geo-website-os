<?php

namespace App\Support\Search;

use App\Support\PublicUrl;
use App\Support\SearchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 通用数据库 LIKE 搜索引擎（P-STEP 18H-1，回退引擎）。
 * ------------------------------------------------------------------
 * 当运行环境不是 SQLite、或 SQLite 未编译 FTS5 时启用：在普通表 search_documents 上
 * 以 title / summary / body LIKE 检索。排序（标题优先于摘要优先于正文）、类型 / 站点 /
 * 语言过滤、LIMIT/OFFSET 同样全部下推到 SQL，保证 DB 层分页、不做全量内存分页。
 *
 * 相关性弱于 FTS5，但不引入任何额外部署依赖，是安装可在任意驱动成功的安全兜底。
 */
class DatabaseLikeEngine implements SearchEngineInterface
{
    public function __construct(private SearchIndexSync $sync)
    {
    }

    public function name(): string
    {
        return 'database-like';
    }

    public function isAvailable(): bool
    {
        try {
            return Schema::hasTable('search_documents');
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(SearchQuery $query): SearchResults
    {
        $this->sync->flushDirty($query->siteId);

        $term = trim($query->term);
        if ($term === '' || ! $this->isAvailable()) {
            return new SearchResults([], 0, $query->page, $query->perPage);
        }

        $like = '%'.$term.'%';

        $base = DB::table('search_documents')
            ->where('site_id', $query->siteId)
            ->where('locale', $query->locale)
            ->where(function ($w) use ($like): void {
                $w->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });

        $types = $query->normalizedTypes();
        if ($types !== []) {
            $base->whereIn('resource_type', $types);
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderByRaw(
                'CASE WHEN title LIKE ? THEN 0 WHEN summary LIKE ? THEN 1 ELSE 2 END',
                [$like, $like],
            )
            ->orderByDesc('published_at')
            ->limit($query->perPage)
            ->offset($query->offset())
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $items[] = new SearchResult(
                title: (string) $r->title,
                summary: (string) $r->summary,
                url: PublicUrl::url((string) $r->path),
                kind: (string) $r->resource_type,
                resourceId: (int) $r->resource_id,
                score: null,
                excerpt: Highlighter::excerpt((string) $r->summary, (string) $r->body, $term),
            );
        }

        return new SearchResults($items, $total, $query->page, $query->perPage);
    }
}
