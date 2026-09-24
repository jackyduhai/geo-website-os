<?php

namespace App\Support\Search;

/**
 * 搜索引擎契约（v1）。
 * ------------------------------------------------------------------
 * 上层只依赖本接口：V1 默认实现 {@see SqliteFtsEngine}（SQLite FTS5，零外部依赖），
 * 当 FTS5 不可用或运行在 MySQL / PostgreSQL 时由 DatabaseLikeEngine 兜底；未来可新增
 * Meilisearch / Elasticsearch / OpenSearch 实现而不改调用方。
 */
interface SearchEngineInterface
{
    /** 执行检索，返回引擎无关的 {@see SearchResults}。 */
    public function search(SearchQuery $query): SearchResults;

    /** 引擎标识（如 sqlite-fts5 / database-like），供审计与调试。 */
    public function name(): string;

    /** 当前环境下该引擎是否可用（决定容器选择与降级）。 */
    public function isAvailable(): bool;
}
