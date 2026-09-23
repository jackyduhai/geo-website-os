<?php

namespace App\Support;

/**
 * 站内搜索统一结果项（Content / Entity 共用）。
 * ------------------------------------------------------------------
 * 搜索视图只需要标题、摘要与落地 URL；本值对象让两类来源呈现一致结构，
 * 视图沿用 `$p->url()` / `$p->title` / `$p->summary` 访问。
 */
class SearchResult
{
    public function __construct(
        public string $title,
        public string $summary,
        public string $url,
        public string $kind = '',
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }
}
