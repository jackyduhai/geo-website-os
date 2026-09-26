<?php

namespace App\Support\Search;

use App\Models\Content;
use App\Models\Entity;
use App\Models\Site;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\PublicIndex;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\Support\Facades\DB;

/**
 * 搜索索引构建器（P-STEP 18H-1）。
 * ------------------------------------------------------------------
 * 把权威数据（contents / entities）按「公开渲染契约」投影为派生只读索引：
 *
 *   Authoritative Data → SearchIndexBuilder → search_documents / search_index
 *
 * 索引不是事实源：本类可随时全量 / 按站点重建（search:reindex），也可单条 upsert /
 * remove（供 {@see SearchIndexSync} 增量调用），不回写权威表。准入口径直接复用
 * {@see PublicIndex}（已发布 + 栏目启用 + 非 noindex + 当前站点），实体再经
 * {@see PublicUrl::entity()} 过滤，只收录真正拥有前台落地页的产品 / 场景。
 *
 * 写入全程用 DB 查询构造器（不经 Eloquent 模型），不触发模型事件、不产生事件循环。
 */
class SearchIndexBuilder
{
    /** 全量重建（所有站点）。返回写入文档总数。 */
    public function rebuildAll(): int
    {
        DB::table('search_documents')->delete();
        if ($this->ftsTableExists()) {
            DB::statement('DELETE FROM search_index');
        }

        $count = 0;
        foreach (Site::all() as $site) {
            SiteContext::withSite($site, function () use ($site, &$count): void {
                $count += $this->rebuildSite($site->id);
            });
        }

        return $count;
    }

    /** 重建单个站点（调用方须已处于该站点上下文）。返回写入文档数。 */
    public function rebuildSite(int $siteId): int
    {
        $this->clearSite($siteId);

        $rows = [];

        PublicIndex::contentQuery()->get()->each(function (Content $c) use (&$rows): void {
            $rows[] = $this->documentForContent($c);
        });

        PublicIndex::entityQuery()->get()->each(function (Entity $e) use (&$rows): void {
            $row = $this->documentForEntity($e);
            if ($row !== null) {
                $rows[] = $row;
            }
        });

        $this->insertRows($rows);

        return count($rows);
    }

    /**
     * 增量：重算单个内容行（先删，再按当前准入决定是否插入）。
     * 返回该行当前是否在索引中。
     */
    public function upsertContent(Content $c): bool
    {
        $this->removeDocument('content', (int) $c->id, (int) $c->site_id, (string) $c->locale);

        $fresh = PublicIndex::contentQuery()
            ->where($c->getTable().'.id', $c->id)
            ->forLocale((string) $c->locale)
            ->first();

        if ($fresh === null) {
            return false;
        }

        $this->insertRows([$this->documentForContent($fresh)]);

        return true;
    }

    /**
     * 增量：重算单个实体行（先删，再按「已发布 + 非 noindex + 有落地页」决定插入）。
     */
    public function upsertEntity(Entity $e): bool
    {
        $this->removeDocument((string) $e->type, (int) $e->id, (int) $e->site_id, (string) $e->locale);

        $fresh = PublicIndex::entityQuery()
            ->where($e->getTable().'.id', $e->id)
            ->forLocale((string) $e->locale)
            ->first();

        if ($fresh === null || $this->documentForEntity($fresh) === null) {
            return false;
        }

        $this->insertRows([$this->documentForEntity($fresh)]);

        return true;
    }

    /** 删除单个文档（普通表 + FTS）。 */
    public function removeDocument(string $type, int $id, int $siteId, string $locale): void
    {
        DB::table('search_documents')
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->where('site_id', $siteId)
            ->where('locale', $locale)
            ->delete();

        if ($this->ftsTableExists()) {
            DB::table('search_index')
                ->where('resource_type', $type)
                ->where('resource_id', $id)
                ->where('site_id', $siteId)
                ->where('locale', $locale)
                ->delete();
        }
    }

    /** 清空单站全部索引行。 */
    protected function clearSite(int $siteId): void
    {
        DB::table('search_documents')->where('site_id', $siteId)->delete();

        if ($this->ftsTableExists()) {
            DB::table('search_index')->where('site_id', $siteId)->delete();
        }
    }

    /** 批量写入（普通表 + FTS）。 */
    protected function insertRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('search_documents')->insert($rows);

        if ($this->ftsTableExists()) {
            DB::table('search_index')->insert(array_map(static fn (array $r): array => [
                'resource_type' => $r['resource_type'],
                'resource_id'   => $r['resource_id'],
                'site_id'       => $r['site_id'],
                'locale'        => $r['locale'],
                'slug'          => $r['slug'],
                'path'          => $r['path'],
                'title'         => implode(' ', CjkTokenizer::tokenize($r['title'])),
                'summary'       => implode(' ', CjkTokenizer::tokenize($r['summary'])),
                'body'          => implode(' ', CjkTokenizer::tokenize($r['body'])),
                'published_at'  => $r['published_at'],
            ], $rows));
        }
    }

    /** 内容 → 索引行（调用方保证该内容已满足 PublicIndex 准入）。 */
    public function documentForContent(Content $c): array
    {
        return [
            'resource_type' => 'content',
            'resource_id'   => (int) $c->id,
            'site_id'       => (int) $c->site_id,
            'locale'        => (string) $c->locale,
            'slug'          => (string) $c->slug,
            'path'          => $c->path(),
            'title'         => mb_substr((string) $c->title, 0, 200),
            'summary'       => (string) ($c->summary ?? ''),
            // 18R-2c：标签名 + 关联实体名并入可搜索文本，实现按 tag/实体名聚合（复用既有 FTS）。
            'body'          => self::plainText((string) ($c->body ?? '') . "\n"
                . $c->tags->pluck('name')->implode(' ') . "\n"
                . $c->entityLinks->load('entity')->pluck('entity.name')->filter()->implode(' ')),
            'published_at'  => $c->published_at?->format('Y-m-d H:i:s'),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    /** 实体 → 索引行；类型不可搜索或无公开落地页时返回 null。 */
    public function documentForEntity(Entity $e): ?array
    {
        // Registry gate：声明 searchable=false 的类型（organization/person/location/
        // topic/download_asset）直接不索引。case_study 虽 searchable=true，但 2a 未接通
        // PublicUrl（无 /cases 路由），下方 PublicUrl 门槛仍会 return null；2b 接通后自动索引。
        if (! EntityCapabilityRegistry::isSearchable($e->type)) {
            return null;
        }

        if (PublicUrl::entity($e) === null) {
            return null;
        }

        return [
            'resource_type' => (string) $e->type,
            'resource_id'   => (int) $e->id,
            'site_id'       => (int) $e->site_id,
            'locale'        => (string) $e->locale,
            'slug'          => (string) $e->slug,
            'path'          => match ($e->type) {
                Entity::TYPE_SERVICE    => '/solutions/'.$e->slug.'/',
                Entity::TYPE_CASE_STUDY => '/cases/'.$e->slug,
                default                 => '/products/'.$e->slug,
            },
            'title'         => mb_substr((string) $e->name, 0, 200),
            'summary'       => (string) ($e->summary ?? ''),
            'body'          => self::plainText((string) ($e->description ?? '')),
            'published_at'  => $e->published_at?->format('Y-m-d H:i:s'),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    /** 当前连接是否存在 FTS5 虚拟表 search_index。 */
    protected function ftsTableExists(): bool
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

    /**
     * 把 Markdown / HTML 正文转为供全文索引的纯文本（去标签 / 图片，链接保留锚文本，
     * 折叠空白）。标题 / 摘要本就是纯文本，仅 body 经此处理。
     */
    public static function plainText(string $raw): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $raw) ?? $raw;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[#>*_`~]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
