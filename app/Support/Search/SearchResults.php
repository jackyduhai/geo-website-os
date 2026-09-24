<?php

namespace App\Support\Search;

use App\Support\SearchResult;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 搜索结果集契约（v1，引擎无关）。
 * ------------------------------------------------------------------
 * 引擎返回本对象：items 为统一 {@see SearchResult}，total 为满足条件的总数（DB /
 * 索引层 COUNT，而非全量取回）。paginator() 由总数 + 当前页生成分页器，分页不再
 * 在内存 slice。
 */
class SearchResults
{
    /**
     * @param  array<int,SearchResult>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /**
     * 生成分页器（保留 q 查询串）。
     *
     * @param  array<string,mixed>  $query  需要保留的查询参数（如 q）
     */
    public function paginator(string $path, array $query = []): LengthAwarePaginator
    {
        $paginator = new LengthAwarePaginator(
            $this->items,
            $this->total,
            $this->perPage,
            $this->page,
            ['path' => $path],
        );

        if ($query !== []) {
            $paginator->appends($query);
        }

        return $paginator;
    }
}
