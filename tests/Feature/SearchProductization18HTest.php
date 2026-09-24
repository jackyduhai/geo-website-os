<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Entity;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\Search\SearchEngineInterface;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResults;
use App\Support\Search\SqliteFtsEngine;
use App\Support\SiteContext;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P-STEP 18H-1 — Search Productization 防回归。
 *
 * 覆盖 FTS5 新契约（旧实现为 LIKE + 全量取回 + 内存分页）：
 *   A) 引擎选择      SQLite 下默认解析为 SqliteFtsEngine；
 *   B) CJK bigram    词尾 / 词中 / 单字召回，多字查询要求 bigram 相邻（防单字 AND 误召回）；
 *   C) 排名          title 命中（权重 10）稳定排在 body 命中（权重 1）之前；
 *   D) 隔离          locale / site 双向隔离；
 *   E) 类型过滤 + DB 层分页；
 *   F) 增量同步      新建 / 删除 / draft→published 自动维护，无需人工 reindex；
 *   G) dirty 懒重建  准入变更标记 dirty，引擎查询前重建；
 *   H) 特殊字符安全  FTS 注入 / XSS / 通配符不破坏、不返回全部；
 *   I) HTTP 契约     搜索页渲染 <mark>、结果链接、noindex。
 */
class SearchProductization18HTest extends TestCase
{
    use RefreshDatabase;

    private Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        Cache::store('file')->forget('search:dirty:sites');
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed(DefaultSettingSeeder::class);
        Catalog::flush();
        PageCache::flush();
    }

    // ---------- helpers ----------

    private function query(
        string $term,
        string $locale = 'zh-CN',
        array $types = [],
        int $page = 1,
        int $perPage = 10,
        ?int $siteId = null,
    ): SearchResults {
        return app(SearchEngineInterface::class)->search(new SearchQuery(
            term: $term,
            locale: $locale,
            siteId: $siteId ?? (int) $this->default->id,
            types: $types,
            page: $page,
            perPage: $perPage,
        ));
    }

    private function org(bool $bilingual = false): Entity
    {
        $o = Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'site-organization',
            'name' => '示例公司',
            'summary' => '示例公司简介',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        if ($bilingual) {
            $o->createTranslation('en', [
                'slug' => 'site-organization',
                'name' => 'Example Company',
                'summary' => 'Example company profile',
                'description' => 'Example company profile',
            ]);
        }
        Catalog::flush();
        PageCache::flush();

        return $o;
    }

    private function product(
        string $slug,
        string $name,
        string $summary = '',
        string $description = '',
    ): Entity {
        $p = Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => $slug,
            'name' => $name,
            'summary' => $summary,
            'description' => $description,
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['core' => true],
        ]);
        Catalog::flush();
        PageCache::flush();

        return $p;
    }

    private function article(string $slug, string $title, string $body = '正文内容'): Content
    {
        $c = Content::create([
            'site_id' => $this->default->id,
            'type' => 'article',
            'slug' => $slug,
            'title' => $title,
            'summary' => $title.'摘要',
            'body' => $body,
            'status' => 'published',
            'published_at' => now(),
        ]);
        Catalog::flush();
        PageCache::flush();

        return $c;
    }

    private function bilingual(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    // ---------- A) 引擎选择 ----------

    public function test_default_engine_is_sqlite_fts5(): void
    {
        $engine = app(SearchEngineInterface::class);
        $this->assertInstanceOf(SqliteFtsEngine::class, $engine);
        $this->assertSame('sqlite-fts5', $engine->name());
    }

    // ---------- B) CJK bigram 召回 ----------

    public function test_cjk_suffix_term_matches(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '工业防腐底漆');

        $r = $this->query('底漆');
        $this->assertGreaterThanOrEqual(1, $r->total);
        $this->assertStringContainsString('环氧富锌底漆', $r->items[0]->title);
    }

    public function test_cjk_mid_subterm_matches(): void
    {
        $this->org();
        $this->product('topcoat', '聚氨酯面漆 PC-200', '聚氨酯面漆', '');

        $this->assertGreaterThanOrEqual(1, $this->query('面漆')->total);
    }

    public function test_cjk_single_han_term_matches(): void
    {
        $this->org();
        $this->product('adhesive', '双组份结构胶 SA-A10', '结构胶', '');

        // 单字查询走 unigram，应能命中。
        $this->assertGreaterThanOrEqual(1, $this->query('胶')->total);
        // 多字（bigram）查询同样命中。
        $this->assertGreaterThanOrEqual(1, $this->query('结构胶')->total);
    }

    public function test_multichar_non_adjacent_terms_do_not_match(): void
    {
        $this->org();
        // 「环」与「漆」都出现但不相邻，bigram 中不存在「环漆」。
        $this->product('guard', '环氧型防护漆', '环氧型防护漆', '');

        // 多字查询同时要求 bigram「环漆」相邻 → 不命中（不能仅因两个单字都在就 AND 召回）。
        $this->assertSame(0, $this->query('环漆')->total);
        // 单字「漆」查询应命中（unigram），证明数据确实可被搜到、而非被漏索引。
        $this->assertGreaterThanOrEqual(1, $this->query('漆')->total);
    }

    // ---------- C) title-first 排名 ----------

    public function test_title_hit_outranks_body_hit(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');
        $this->product(
            'polyurethane',
            '聚氨酯面漆 PC-200',
            '聚氨酯面漆',
            '本产品可配套环氧类底漆使用',   // 仅正文提及「环氧」
        );

        $r = $this->query('环氧');
        $this->assertGreaterThanOrEqual(2, $r->total);
        $this->assertStringContainsString('环氧富锌底漆', $r->items[0]->title);
    }

    // ---------- D) locale / site 隔离 ----------

    public function test_locale_results_are_isolated(): void
    {
        $this->bilingual();
        $p = $this->org(true);
        $product = $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');
        $product->createTranslation('en', [
            'slug' => 'epoxy-primer',
            'name' => 'Zinc-Rich Epoxy Primer ZP-100',
            'summary' => 'Zinc-rich epoxy primer',
            'description' => 'Anti-corrosion primer',
        ]);
        Catalog::flush();
        PageCache::flush();

        $zh = $this->query('底漆', 'zh-CN');
        $this->assertGreaterThanOrEqual(1, $zh->total);
        $this->assertStringContainsString('环氧富锌底漆', $zh->items[0]->title);

        $en = $this->query('primer', 'en');
        $this->assertGreaterThanOrEqual(1, $en->total);
        $this->assertStringContainsString('Zinc-Rich Epoxy Primer', $en->items[0]->title);

        // 英文索引中不存在中文「底漆」token → 英文搜中文词为 0。
        $this->assertSame(0, $this->query('底漆', 'en')->total);
    }

    public function test_cross_site_search_is_isolated(): void
    {
        // Site A
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');

        // Site B（独立站点）
        $b = Site::create([
            'slug' => 'acme',
            'name' => 'Acme Global',
            'domain' => 'acme.test',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'description' => 'Acme.',
            'metadata' => ['organization' => 'Acme Global'],
        ]);
        SiteContext::setSite($b);
        $this->seed(DefaultSettingSeeder::class);
        Entity::create([
            'site_id' => $b->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'acme-organization',
            'name' => 'Acme Global',
            'summary' => 'Acme organization',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        Entity::create([
            'site_id' => $b->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => 'acme-part',
            'name' => 'Acme 专用部件',
            'summary' => 'Acme 部件',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['core' => true],
        ]);
        Catalog::flush();
        PageCache::flush();

        // B 只搜到自己。
        $this->assertGreaterThanOrEqual(1, $this->query('Acme', 'zh-CN', siteId: (int) $b->id)->total);
        // A 搜不到 B 的内容。
        $this->assertSame(0, $this->query('Acme', 'zh-CN', siteId: (int) $this->default->id)->total);
        // B 搜不到 A 的内容。
        $this->assertSame(0, $this->query('底漆', 'zh-CN', siteId: (int) $b->id)->total);

        SiteContext::setSite($this->default);
    }

    // ---------- E) 类型过滤 + DB 分页 ----------

    public function test_type_filter_restricts_results(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');
        $this->article('know-1', '底漆选型知识', '正文介绍底漆');

        $onlyProducts = $this->query('底漆', 'zh-CN', ['product']);
        $this->assertGreaterThanOrEqual(1, $onlyProducts->total);
        foreach ($onlyProducts->items as $item) {
            $this->assertSame('product', $item->kind);
        }

        $onlyContent = $this->query('底漆', 'zh-CN', ['content']);
        $this->assertGreaterThanOrEqual(1, $onlyContent->total);
        foreach ($onlyContent->items as $item) {
            $this->assertSame('content', $item->kind);
        }
    }

    public function test_pagination_is_done_at_db_layer(): void
    {
        $this->org();
        $this->product('p1', '防护底漆甲', '防护底漆甲', '');
        $this->product('p2', '防护底漆乙', '防护底漆乙', '');
        $this->product('p3', '防护底漆丙', '防护底漆丙', '');

        $page1 = $this->query('底漆', 'zh-CN', [], 1, 2);
        $this->assertSame(3, $page1->total);
        $this->assertCount(2, $page1->items);

        $page2 = $this->query('底漆', 'zh-CN', [], 2, 2);
        $this->assertCount(1, $page2->items);
    }

    // ---------- F) 增量同步 ----------

    public function test_new_entity_is_indexed_without_manual_reindex(): void
    {
        $this->org();
        $this->assertSame(0, $this->query('面漆')->total);

        $this->product('topcoat', '聚氨酯面漆 PC-200', '聚氨酯面漆', '');

        $this->assertGreaterThanOrEqual(1, $this->query('面漆')->total);
    }

    public function test_deleted_entity_is_removed_from_index(): void
    {
        $this->org();
        $p = $this->product('topcoat', '聚氨酯面漆 PC-200', '聚氨酯面漆', '');
        $this->assertGreaterThanOrEqual(1, $this->query('面漆')->total);

        $p->delete();
        Catalog::flush();
        PageCache::flush();

        $this->assertSame(0, $this->query('面漆')->total);
    }

    public function test_draft_entity_hidden_then_published_indexed(): void
    {
        $this->org();
        $p = Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => 'topcoat',
            'name' => '聚氨酯面漆 PC-200',
            'summary' => '聚氨酯面漆',
            'status' => Entity::STATUS_DRAFT,
            'sort_order' => 0,
            'metadata' => ['core' => true],
        ]);
        Catalog::flush();
        PageCache::flush();

        $this->assertSame(0, $this->query('面漆')->total);

        $p->status = Entity::STATUS_PUBLISHED;
        $p->published_at = now();
        $p->save();
        Catalog::flush();
        PageCache::flush();

        $this->assertGreaterThanOrEqual(1, $this->query('面漆')->total);
    }

    // ---------- G) dirty 懒重建 ----------

    public function test_dirty_site_is_lazy_rebuilt_before_search(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');
        $siteId = (int) $this->default->id;

        // 模拟准入变更标记 dirty + 索引被破坏。
        app(\App\Support\Search\SearchIndexSync::class)->markDirty($siteId);
        DB::table('search_documents')->where('site_id', $siteId)->delete();
        DB::statement('DELETE FROM search_index');

        // 引擎查询前 flushDirty → rebuildSite 恢复，结果正确。
        $r = $this->query('底漆');
        $this->assertGreaterThanOrEqual(1, $r->total);

        // dirty 标记已清除。
        $dirty = Cache::store('file')->get('search:dirty:sites', []);
        $this->assertNotContains($siteId, (array) $dirty);
    }

    // ---------- H) 特殊字符安全 ----------

    public function test_special_characters_do_not_break_or_inject(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');

        // 通配符 / 引号单独出现：不产生 token，返回空而非「全部」。
        $this->assertSame(0, $this->query('*')->total);
        $this->assertSame(0, $this->query('"')->total);

        // 经典 FTS 注入：OR 被当普通 token（phrase），不会改变布尔逻辑、不返回全部。
        $injected = $this->query('primer" OR "x');
        $this->assertLessThan(DB::table('search_documents')->count(), $injected->total);

        // <script> 片段：提取出 alert 作为普通词，不抛异常。
        $this->assertSame(0, $this->query('<script>alert(1)</script>')->total);
    }

    public function test_http_search_xss_term_is_escaped(): void
    {
        $term = '<script>alert(1)</script>';
        $r = $this->get('/search?q='.urlencode($term));
        $r->assertOk();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $r->getContent());
    }

    // ---------- I) HTTP 契约 ----------

    public function test_http_search_page_renders_marked_results_and_noindex(): void
    {
        $this->org();
        $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');

        $r = $this->get('/search?q='.urlencode('底漆'));
        $r->assertOk();
        $c = $r->getContent();
        // 高亮会在标题中插入 <mark>，先 strip_tags 还原连续文本再断言标题。
        $this->assertStringContainsString('环氧富锌底漆 ZP-100', strip_tags($c));
        $this->assertStringContainsString('<mark>底漆</mark>', $c);
        $this->assertStringContainsString('/products/epoxy-primer', $c);
        $this->assertStringContainsString('noindex', $c);

        // 空 q：正常渲染、无结果卡片。
        $empty = $this->get('/search');
        $empty->assertOk();
        $this->assertSame(0, preg_match_all('#class="post"#', $empty->getContent()));
    }

    public function test_http_en_search_renders_english_results(): void
    {
        $this->bilingual();
        $this->org(true);
        $p = $this->product('epoxy-primer', '环氧富锌底漆 ZP-100', '环氧富锌底漆', '');
        $p->createTranslation('en', [
            'slug' => 'epoxy-primer',
            'name' => 'Zinc-Rich Epoxy Primer ZP-100',
            'summary' => 'Zinc-rich epoxy primer',
            'description' => 'Anti-corrosion primer',
        ]);
        Catalog::flush();
        PageCache::flush();

        $r = $this->get('/en/search?q=primer');
        $r->assertOk();
        $c = $r->getContent();
        $this->assertStringContainsString('Zinc-Rich Epoxy Primer ZP-100', strip_tags($c));
        $this->assertStringContainsString('<mark>Primer</mark>', $c);
        $this->assertStringContainsString('/en/products/epoxy-primer', $c);
    }
}
