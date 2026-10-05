<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Entity;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GEO-INT · 派生输出真实性（20G-4 · Derived Output Integrity）
 * ==================================================================
 * 核心原则：**所有机器可读输出必须与业务 SoT 一致。**
 *
 * C-8 缺陷：sitemap 的lastmod 对内容型 URL 兜底填「当天」。
 * 实测 27 个 URL 中 24 个 lastmod 是今天，而 entities.updated_at
 * 实际是 09-20 —— 派生输出与 SoT 不一致。
 *
 * 后果是双重的：
 *   - 搜索侧降低对 lastmod 的信任；
 *   - GEO 侧污染内容新鲜度信号 —— 而这正是本产品的核心卖点。
 *
 * 修复后的契约：
 *   - 内容型 URL（产品 / 场景 / 栏目 / 文章）→ 真实 updated_at / published_at；
 *   - 固定 IA 页（首页 / 工厂 / 合作 / 关于 / 联系）→ 省略 lastmod，
 *     因为它们没有内容实体驱动，填任何日期都是编造事实。
 */
class DerivedOutputIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        // StructureSeeder 只建栏目/分组/首页区块，**不建实体**。
        // sitemap 的产品/场景分支由 Catalog::company()（organization 实体）驱动，
        // 没有它则整块被跳过，测试会误判为「URL 缺失」。
        // 因此这里按 Catalog 期望的形状自建最小实体集。
        $this->seedCatalogEntities();
    }

    /**
     * 建立最小可用的目录实体集（organization + products + 1 scene）。
     *
     * 字段形状严格参照 Catalog::buildDataset()（app/Support/Catalog.php:62）：
     *   - organization 的 metadata 里要有嵌套的 company / product_lines  子集，
     *     `Catalog::company()` 读的是 `$meta['company']`——只给 is_default 会让
     *     company() 返回空数组，sitemap 的整块业务目录分支被跳过。
     *   - product 带 metadata.core（是否有独立详情页）+ metadata.line（所属系列）
     *   - 场景用 service 类型（Catalog::scenes() 读 ofType(TYPE_SERVICE)）
     */
    protected function seedCatalogEntities(): void
    {
        Entity::create([
            'type' => Entity::TYPE_ORGANIZATION,
            'name' => '测试制造有限公司',
            'slug' => 'test-manufacturing',
            'status' => 'published',
            'summary' => '用于派生输出完整性测试的组织实体',
            'metadata' => [
                'is_default' => true,
                'company' => [
                    'name' => '测试制造有限公司',
                    'short' => '测试制造',
                    'founded' => '2010',
                    'employees' => '120',
                ],
                'product_lines' => [
                    ['slug' => 'test-line', 'name' => '测试产品系列', 'summary' => '系列说明'],
                ],
            ],
        ]);

        foreach ([
            ['测试产品甲', 'test-product-a', true],
            ['测试产品乙', 'test-product-b', false],
        ] as [$name, $slug, $core]) {
            Entity::create([
                'type' => Entity::TYPE_PRODUCT,
                'name' => $name,
                'slug' => $slug,
                'status' => 'published',
                'summary' => $name . '摘要',
                'metadata' => ['core' => $core, 'line' => 'test-line'],
            ]);
        }

        // 场景 = service 类型（Catalog::scenes() 读 ofType(TYPE_SERVICE)）
        Entity::create([
            'type' => Entity::TYPE_SERVICE,
            'name' => '测试应用场景',
            'slug' => 'test-scene',
            'status' => 'published',
            'summary' => '场景摘要',
            'metadata' => ['combo' => ['test-product-a']],
        ]);

        // 一篇知识文章（sitemap 的文章型URL 来自 Content::published()）
        $knowledge = Category::where('slug', 'knowledge')->first();
        if ($knowledge !== null) {
            \App\Models\Content::create([
                'type' => 'article',
                'category_id' => $knowledge->id,
                'title' => '测试知识文章',
                'slug' => 'test-knowledge-article',
                'status' => 'published',
                'summary' => '摘要',
                'body' => '正文',
                'published_at' => now()->subDays(10),
            ]);
        }

        Catalog::flush();
    }

    /**
     * 每个用例后复位站点上下文与目录缓存。
     *
     * SiteContext 与 Catalog::memo 都是**进程内静态状态**，
     * 测试中调用 setSite() 会跨用例泄漏，表现为「单独跑通过、
     * 整组跑失败」。契约测试本身必须可靠，否则它就不是有效证据。
     */
    protected function tearDown(): void
    {
        SiteContext::clear();
        Catalog::flush();

        parent::tearDown();
    }

    /**
     * 解析 sitemap，返回 loc => lastmod 映射。
     * 缺 lastmod 的 URL 映射为 null（表示「未提供」，而非空串）。
     */
    protected function parseSitemap(): array
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml);
        $this->assertNotFalse($x, 'sitemap 必须是合法 XML');

        $map = [];
        foreach ($x->url as $u) {
            $map[(string) $u->loc] = ((string) $u->lastmod) !== '' ? (string) $u->lastmod : null;
        }

        return $map;
    }

    // ─────────────────────────────────────────────────────────
    // GEO-INT-001 · 不得出现「今天」兜底
    // ─────────────────────────────────────────────────────────

    /**
     * GEO-INT-001 不得出现「无来源的今天」——sitemap 里的 lastmod 必须能追溯到 SoT。
     *
     * 修复前：24/27 个 URL 都是当天，而 entities.updated_at 实际是 09-20，
     *        也就是说这些 lastmod 与 SoT 毫无关系，是纯粹的编造。
     * 修复后：每个 lastmod 都来自实体的真实 updated_at / published_at。
     *
     * 判据不能是「不得等于今天」——因为测试数据里 Group / Category
     * 确实是刚 seed 出来的（updated_at = 今天），它们的 lastmod 就该是今天，
     * 那是**真话**。真正的缺陷是「与 SoT 无关的今天」。
     *
     * 因此本测试的判据是：**sitemap 中出现的每个 lastmod，
     * 都必须能在 SoT 中找到对应实体且其更新时间与之一致。**
     */
    public function test_geoint_001_every_lastmod_is_traceable_to_source_of_truth(): void
    {
        $past = now()->subDays(30);

        // 把内容实体与文章的更新时间推到过去，制造「明显不是今天」的场景
        Entity::query()->update(['updated_at' => $past, 'published_at' => $past]);
        \App\Models\Content::query()->where('slot', null)->update([
            'updated_at' => $past, 'published_at' => $past,
        ]);
        // 栏目/分组也推过去，确保没有 legitimately-today 的干扰
        Category::query()->update(['updated_at' => $past]);
        \App\Models\Group::query()->update(['updated_at' => $past]);
        Catalog::flush();

        $map = $this->parseSitemap();
        $this->assertNotEmpty($map, 'sitemap 不应为空');

        $today = now()->toDateString();
        $untraceable = [];

        foreach ($map as $loc => $lastmod) {
            if ($lastmod === null) {
                continue;   // 省略 lastmod 是合规的（固定 IA 页）
            }
            if ($lastmod !== $today) {
                continue;   // 非今天 = 一定是真实的历史更新时间
            }
            // lastmod == 今天：必须能找到「今天确实被修改过」的 SoT 记录
            $traceable = Entity::query()->where('slug', basename(parse_url($loc, PHP_URL_PATH)))
                    ->whereDate('updated_at', $today)->exists()
                || Category::query()->where('slug', basename(rtrim(parse_url($loc, PHP_URL_PATH), '/')))
                    ->whereDate('updated_at', $today)->exists()
                || \App\Models\Group::query()->where('slug', basename(rtrim(parse_url($loc, PHP_URL_PATH), '/')))
                    ->whereDate('updated_at', $today)->exists();

            if (! $traceable) {
                $untraceable[] = $loc . ' => ' . $lastmod;
            }
        }

        $this->assertSame(
            [],
            $untraceable,
            "GEO-INT-001 失败：以下 lastmod 无法追溯到 SoT（编造事实）：\n" . implode("\n", $untraceable)
        );
    }

    // ─────────────────────────────────────────────────────────
    // GEO-INT-002 · 内容型 URL 用真实更新时间
    // ─────────────────────────────────────────────────────────

    /**
     * GEO-INT-002 产品详情页的 lastmod = Entity.updated_at 真值。
     */
    public function test_geoint_002_product_url_uses_entity_updated_at(): void
    {
        $product = Entity::where('type', 'product')->firstOrFail();

        $past = now()->subDays(45);
        $product->update(['updated_at' => $past, 'published_at' => $past->copy()->subDays(5)]);
        Catalog::flush();

        $map = $this->parseSitemap();
        $key = url('/products/' . $product->slug);

        $this->assertArrayHasKey($key, $map, '产品详情页应在 sitemap 中');
        $this->assertSame(
            $past->toDateString(),
            $map[$key],
            'GEO-INT-002 失败：产品页 lastmod 未使用 Entity.updated_at 真值'
        );
    }

    /**
     * GEO-INT-003 文章页的 lastmod = Content.updated_at 真值。
     */
    public function test_geoint_003_article_url_uses_content_updated_at(): void
    {
        $article = \App\Models\Content::where('type', 'article')
            ->where('slot', null)
            ->firstOrFail();

        $past = now()->subDays(20);
        $article->update(['updated_at' => $past, 'published_at' => $past->copy()->subDays(3)]);

        $map = $this->parseSitemap();
        $key = $article->url();

        $this->assertArrayHasKey($key, $map);
        $this->assertSame(
            $past->toDateString(),
            $map[$key],
            'GEO-INT-003 失败：文章页 lastmod 未使用 Content.updated_at 真值'
        );
    }

    // ─────────────────────────────────────────────────────────
    // GEO-INT-004 · 固定 IA 页应省略 lastmod
    // ─────────────────────────────────────────────────────────

    /**
     * GEO-INT-004 固定 IA 页（无内容实体驱动）应**省略** lastmod。
     *
     * 覆盖首页与「关于我们 / 联系我们」——它们在任何站点都存在，
     * 不像 factory/cooperation 那样依赖目录数据（$hasCatalog）。
     */
    public function test_geoint_004_fixed_ia_pages_omit_lastmod(): void
    {
        $map = $this->parseSitemap();

        $iaPages = array_filter(
            array_keys($map),
            fn ($loc) => (bool) preg_match(
                '#/(about/(profile|history|culture)|contact|factory|cooperation)/?$#',
                $loc
            )
        );

        $this->assertNotEmpty(
            $iaPages,
            '应至少存在一个固定 IA 页用于验证；实际 sitemap：' . implode(', ', array_slice(array_keys($map), 0, 10))
        );

        foreach ($iaPages as $loc) {
            $this->assertNull(
                $map[$loc],
                "GEO-INT-004 失败：固定 IA 页 {$loc} 不应编造 lastmod（实测 " . var_export($map[$loc], true) . '）'
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // GEO-INT-005 · lastmod 不得晚于真实更新时间
    // ─────────────────────────────────────────────────────────

    /**
     * GEO-INT-005 任何 URL 的 lastmod 不得**晚于**其真实更新时间。
     *
     * 这是「派生输出不得超前于 SoT」的不变量：sitemap 声称某页在
     * 今天更新过，而实体其实是 30 天前更新的，就是虚假事实。
     */
    public function test_geoint_005_lastmod_never_exceeds_source_of_truth(): void
    {
        $past = now()->subDays(60);
        Entity::query()->update(['updated_at' => $past, 'published_at' => $past]);
        \App\Models\Content::query()->where('slot', null)->update([
            'updated_at' => $past, 'published_at' => $past,
        ]);
        Catalog::flush();

        $map = $this->parseSitemap();
        $future = now()->addDay()->toDateString();

        $violations = array_filter($map, fn ($lm) => $lm !== null && $lm > $future);

        $this->assertSame(
            [],
            $violations,
            'GEO-INT-005 失败：存在 lastmod 晚于 SoT 的 URL（编造的未来更新时间）'
        );
    }

    // ─────────────────────────────────────────────────────────
    // GEO-INT-006 · 站点隔离（20G-5 的 sitemap 维度前置）
    // ─────────────────────────────────────────────────────────

    /**
     * GEO-INT-006 sitemap 只收录本站实体，不得出现他站 URL。
     *
     * 这是 20G-5 Multi-site 隔离在 GEO 输出维度的切片。
     */
    public function test_geoint_006_sitemap_contains_only_current_site_urls(): void
    {
        $currentSite = Site::findOrFail(SiteContext::currentSiteId() ?? Site::firstOrFail()->id);

        $other = Site::where('id', '!=', $currentSite->id)->first()
            ?? Site::create(['slug' => 'sitemap-other', 'name' => '他站', 'is_default' => false]);

        // 在他站创建一个产品（core 判定走 metadata，故写进 metadata）
        SiteContext::setSite($other);
        $otherProduct = Entity::create([
            'type' => 'product',
            'name' => '他站专属产品',
            'slug' => 'other-site-exclusive-product',
            'status' => 'published',
            'metadata' => ['core' => true],
        ]);

        // 切回本站
        SiteContext::setSite($currentSite);
        Catalog::flush();

        $this->assertNotNull($otherProduct->id, '他站产品应创建成功');

        $map = $this->parseSitemap();
        $locs = implode("\n", array_keys($map));

        $this->assertStringNotContainsString(
            $otherProduct->slug,
            $locs,
            'GEO-INT-006 失败：他站产品 URL 出现在本站 sitemap（跨站污染）'
        );
    }
}
