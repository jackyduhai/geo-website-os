<?php

namespace App\Support;

/**
 * 站内搜索统一结果项（Content / Entity 共用）。
 * ------------------------------------------------------------------
 * 搜索视图只需要标题、摘要与落地 URL；本值对象让两类来源呈现一致结构，
 * 视图沿用 `$p->url()` / `$p->title` / `$p->summary` 访问。
 *
 * P-STEP 18H-1：扩展为「引擎无关」的结果契约——新增 resourceId / score / excerpt；
 * {@see $kind} 即 resource_type（content / product / service / ...）。title 与
 * summary 始终为纯文本（建索引时已 strip_tags），高亮由
 * {@see \App\Support\Search\Highlighter} 安全处理，本对象不携带任何未转义 HTML。
 */
class SearchResult
{
    public function __construct(
        public string $title,
        public string $summary,
        public string $url,
        public string $kind = '',
        public ?int $resourceId = null,
        public ?float $score = null,
        public string $excerpt = '',
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    /** 视图展示摘要：优先命中片段 excerpt，回退 summary（均为纯文本）。 */
    public function snippet(): string
    {
        return $this->excerpt !== '' ? $this->excerpt : $this->summary;
    }
}
