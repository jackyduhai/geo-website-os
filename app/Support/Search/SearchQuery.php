<?php

namespace App\Support\Search;

/**
 * 搜索查询契约（v1，引擎无关）。
 * ------------------------------------------------------------------
 * 上层（SearchController）只构造本对象并交给 SearchEngineInterface；未来从 SQLite
 * FTS5 换成 Meilisearch / Elasticsearch / OpenSearch 时，本结构与调用方都不重写。
 */
class SearchQuery
{
    /**
     * @param  string  $term     原始检索词（已 trim）
     * @param  string  $locale   当前前台语言（zh-CN / en）
     * @param  int     $siteId   当前站点（Site Scope，强制）
     * @param  array   $types    资源类型过滤（content/product/service/...），空=全部
     * @param  int     $page     页码（1 起）
     * @param  int     $perPage  每页条数（分页在引擎 / DB 层完成）
     */
    public function __construct(
        public readonly string $term,
        public readonly string $locale,
        public readonly int $siteId,
        public readonly array $types = [],
        public readonly int $page = 1,
        public readonly int $perPage = 10,
    ) {
    }

    /** SQL OFFSET。 */
    public function offset(): int
    {
        return (max(1, $this->page) - 1) * $this->perPage;
    }

    /** 去重、去空后的类型白名单。 */
    public function normalizedTypes(): array
    {
        return array_values(array_unique(array_filter(
            $this->types,
            fn ($t) => is_string($t) && trim($t) !== '',
        )));
    }
}
