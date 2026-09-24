<?php

namespace App\Support\Search;

use App\Support\PublicUrl;
use App\Support\SearchResult;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 搜索引擎（P-STEP 18H-1，V1 默认引擎）。
 * ------------------------------------------------------------------
 * FTS 表 search_index 的 title / summary / body 是 {@see CjkTokenizer} 产出的 token 串，
 * 只负责「匹配 / BM25 排名 / 资源定位」；当前页命中定位后，回普通表 search_documents
 * 取原文组装结果（一次 IN 查询，无 N+1），因此人类看到的始终是原文。
 *
 *   - 站点 / 语言 / 类型过滤与 LIMIT/OFFSET 全部下推 FTS（DB 层分页，不全量取回）；
 *   - BM25 列权重 title > summary > body，再以资源类型 / 发布时间兜底，排序可解释；
 *   - 检索片段由 {@see Highlighter} 基于原文生成纯文本，视图再安全高亮。
 *
 * 查询前先 flushDirty()，把影响准入的批量变更（noindex / 栏目 / 关系 / 设置）懒重建一次。
 */
class SqliteFtsEngine implements SearchEngineInterface
{
    public function __construct(private SearchIndexSync $sync)
    {
    }

    public function name(): string
    {
        return 'sqlite-fts5';
    }

    public function isAvailable(): bool
    {
        if (DB::getDriverName() !== 'sqlite') {
            return false;
        }

        try {
            return DB::selectOne(
                "SELECT 1 AS ok FROM sqlite_master WHERE name = 'search_index' AND type = 'table'",
            ) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(SearchQuery $query): SearchResults
    {
        $this->sync->flushDirty($query->siteId);

        $match = self::matchExpression($query->term);
        if ($match === null || ! $this->isAvailable()) {
            return new SearchResults([], 0, $query->page, $query->perPage);
        }

        $bindings = [$match, $query->siteId, $query->locale];
        $where = 'search_index MATCH ? AND site_id = ? AND locale = ?';

        $types = $query->normalizedTypes();
        if ($types !== []) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $where .= " AND resource_type IN ($in)";
            array_push($bindings, ...$types);
        }

        $total = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM search_index WHERE $where",
            $bindings,
        )->n;

        // 当前页：资源定位 + BM25（列 6=title / 7=summary / 8=body；返回值越小越相关）。
        // 列权重 title=10 / summary=5 / body=1：标题一次命中抵正文十次，产品名 / 标题命中
        // 稳定排在正文提及之前（官方语义：列权重越大，该列命中越重要、越靠前）。
        $sql = "SELECT resource_type, resource_id, site_id, locale,
                       bm25(search_index, 0,0,0,0,0,0, 10.0, 5.0, 1.0, 0) AS score
                FROM search_index
                WHERE $where
                ORDER BY score ASC,
                         CASE resource_type WHEN 'product' THEN 0 WHEN 'service' THEN 1 ELSE 2 END,
                         published_at DESC
                LIMIT ? OFFSET ?";

        $hits = DB::select($sql, array_merge($bindings, [$query->perPage, $query->offset()]));

        if ($hits === []) {
            return new SearchResults([], $total, $query->page, $query->perPage);
        }

        // 回普通表取原文（一次组合 OR 查询），按定位建索引。
        $docs = DB::table('search_documents')
            ->where(function ($w) use ($hits): void {
                foreach ($hits as $h) {
                    $w->orWhere(function ($ww) use ($h): void {
                        $ww->where('resource_type', $h->resource_type)
                            ->where('resource_id', $h->resource_id)
                            ->where('site_id', $h->site_id)
                            ->where('locale', $h->locale);
                    });
                }
            })
            ->get()
            ->keyBy(fn ($d) => self::key($d->resource_type, $d->resource_id, $d->site_id, $d->locale));

        $items = [];
        foreach ($hits as $h) {
            $d = $docs->get(self::key($h->resource_type, $h->resource_id, $h->site_id, $h->locale));
            if ($d === null) {
                continue;
            }

            $items[] = new SearchResult(
                title: (string) $d->title,
                summary: (string) $d->summary,
                url: PublicUrl::url((string) $d->path),
                kind: (string) $d->resource_type,
                resourceId: (int) $d->resource_id,
                score: (float) $h->score,
                excerpt: Highlighter::excerpt((string) $d->summary, (string) $d->body, $query->term),
            );
        }

        return new SearchResults($items, $total, $query->page, $query->perPage);
    }

    private static function key(string $type, int $id, int $siteId, string $locale): string
    {
        return $type.'|'.$id.'|'.$siteId.'|'.$locale;
    }

    /**
     * 把检索词转为安全的 FTS5 MATCH 表达式：经 {@see CjkTokenizer} 得到 token——
     * 汉字 unigram/bigram 精确 phrase（相邻约束），拉丁 / 数字 phrase + 前缀（词干 / 复数），
     * token 间 AND。双引号转义，其余特殊字符被 phrase 当字面量，杜绝 FTS 查询注入。
     */
    public static function matchExpression(string $term): ?string
    {
        $tokens = CjkTokenizer::tokenize($term);
        if ($tokens === []) {
            return null;
        }

        return implode(' ', array_map(static function (string $t): string {
            $quoted = '"'.str_replace('"', '""', $t).'"';

            return CjkTokenizer::hasHan($t) ? $quoted : $quoted.'*';
        }, $tokens));
    }
}
