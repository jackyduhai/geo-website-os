<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P-STEP 18C — Release Residual Audit 契约测试。
 *
 * 锁定本轮裁定的五项 v1.0 Required Debt，防止后续重构让「同一事实在两套系统
 * 各自维护 / 产出不同结果」回归：
 *   - TD-07 Organization 单一事实源（Site 聚合为主体唯一事实源，GEO 锚点对齐 Schema）
 *   - TD-05 Entity Public URL 体系冻结（哪些实体类型拥有真实前台落地页）
 *   - TD-06 Content 公开路径体系（/{栏目路径}/{slug}，无 /article/ 前缀）
 *   - TD-09 Schema @id / canonical / PublicUrl 在 HTTP 与 CLI 下 host/scheme 不分叉
 *   - TD-08b Entity / Site 写入驱动的整页缓存失效 + SiteContext 同进程 memo 刷新
 */
class ReleaseResidual18CTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['name' => 'Test Site', 'domain' => 'example.com']);
        SiteContext::setSite($this->site);
        Catalog::flush();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        Catalog::flush();
        PageCache::flush();
        parent::tearDown();
    }

    private function entity(string $type, string $slug, array $attributes = []): Entity
    {
        $entity = Entity::create(array_merge([
            'site_id' => $this->site->id,
            'type'    => $type,
            'slug'    => $slug,
            'name'    => ucfirst($slug),
            'status'  => 'published',
        ], $attributes));
        Catalog::flush();

        return $entity;
    }

    // ===================================================================
    // TD-07 Organization 单一事实源
    // ===================================================================

    public function test_geo_site_organization_anchor_uses_site_aggregate_and_matches_schema_id(): void
    {
        $graph = $this->get('/geo.json')->assertOk()->json();

        // GEO site 块必须给出与 SchemaBuilder 完全相同的主体 @id（Site 聚合唯一事实源）
        $this->assertSame('https://example.com/#organization', $graph['site']['organization']['@id']);
        $this->assertSame('Test Site', $graph['site']['organization']['name']);
        $this->assertSame('https://example.com/', $graph['site']['organization']['url']);

        // 首页 JSON-LD 的 Organization 主体锚点同一 @id（不允许 GEO / Schema 各一个组织对象）
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('https://example.com/#organization', $home);
    }

    public function test_site_organization_entity_is_anchored_via_same_as_without_changing_node_id(): void
    {
        $this->entity(Entity::TYPE_ORGANIZATION, 'main-co', [
            'metadata' => ['is_site_organization' => true],
        ]);

        $graph = $this->get('/geo.json')->assertOk()->json();
        $node = collect($graph['entities'])->firstWhere('slug', 'main-co');

        $this->assertSame('entity/organization/main-co', $node['id'], '关系边依赖的节点 id 不得改变');
        $this->assertSame(
            ['https://example.com/#organization'],
            $node['same_as'],
            '主体组织实体必须通过 same_as 锚定 Site 聚合主体，而非成为第二个组织事实'
        );
    }

    public function test_plain_organization_entity_is_not_marked_as_site_organization(): void
    {
        $this->entity(Entity::TYPE_ORGANIZATION, 'partner-co');

        $graph = $this->get('/geo.json')->assertOk()->json();
        $node = collect($graph['entities'])->firstWhere('slug', 'partner-co');

        $this->assertArrayNotHasKey('same_as', $node, '未标记为站点主体的组织实体不得伪造锚点');
    }

    public function test_organization_anchor_is_site_scoped_across_hosts(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);
        Entity::create([
            'site_id' => $siteB->id, 'type' => Entity::TYPE_ORGANIZATION, 'slug' => 'b-co',
            'name' => 'B Co', 'status' => 'published',
            'metadata' => ['is_site_organization' => true],
        ]);

        // Host B → 只解析到 B 的主体锚点
        $graphB = $this->get('http://b.example.com/geo.json')->assertOk()->json();
        $this->assertSame('https://b.example.com/#organization', $graphB['site']['organization']['@id']);
        $this->assertSame('Site B', $graphB['site']['organization']['name']);
        $bNode = collect($graphB['entities'])->firstWhere('slug', 'b-co');
        $this->assertSame(['https://b.example.com/#organization'], $bNode['same_as']);
        $rawB = json_encode($graphB, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('https://example.com/', $rawB, 'Site B 不得泄漏 Site A 的主体 / host');

        // 默认站点（A）→ 显式按 A 的规范 host 请求，只解析到 A 的主体锚点
        // （测试进程内 SiteContext 是 static memo，连续切 host 后须用真实 host 重解析，
        //  这也正是生产 FPM 每请求按 host 解析的真实路径）。
        $graphA = $this->get('http://example.com/geo.json')->assertOk()->json();
        $this->assertSame('https://example.com/#organization', $graphA['site']['organization']['@id']);
        $this->assertStringNotContainsString('b.example.com', json_encode($graphA, JSON_UNESCAPED_SLASHES));
    }

    // ===================================================================
    // TD-05 Entity Public URL 体系冻结
    // ===================================================================

    public function test_entity_public_url_contract_by_type(): void
    {
        // Catalog 投影需要先存在 organization 主体
        $this->entity(Entity::TYPE_ORGANIZATION, 'main-co');

        $coreProduct = $this->entity(Entity::TYPE_PRODUCT, 'core-prod', [
            'metadata' => ['core' => true],
        ]);
        $nonCoreProduct = $this->entity(Entity::TYPE_PRODUCT, 'side-prod', [
            'metadata' => ['core' => false],
        ]);
        $service = $this->entity(Entity::TYPE_SERVICE, 'svc-one');
        $person = $this->entity(Entity::TYPE_PERSON, 'alice');
        $location = $this->entity(Entity::TYPE_LOCATION, 'hq');
        $topic = $this->entity(Entity::TYPE_TOPIC, 'topic-one');

        // 核心产品：详情型，无尾斜杠
        $this->assertSame('https://example.com/products/core-prod', PublicUrl::entity($coreProduct));
        // 应用场景服务：目录型，带尾斜杠
        $this->assertSame('https://example.com/solutions/svc-one/', PublicUrl::entity($service));
        // 非核心产品 / 组织 / 人物 / 地点 / 主题：无独立前台页 → null（不得输出会 404 的 url）
        $this->assertNull(PublicUrl::entity($nonCoreProduct));
        $this->assertNull(PublicUrl::entity($person));
        $this->assertNull(PublicUrl::entity($location));
        $this->assertNull(PublicUrl::entity($topic));
    }

    // ===================================================================
    // TD-06 Content 公开路径体系（无 /article/ 前缀）
    // ===================================================================

    public function test_content_path_uses_category_hierarchy_without_article_prefix(): void
    {
        $knowledge = Category::create([
            'site_id' => $this->site->id, 'name' => 'Knowledge', 'slug' => 'knowledge',
            'type' => Category::TYPE_LIST, 'is_nav' => true, 'is_active' => true,
            'is_index' => true, 'sort' => 0,
        ]);
        $article = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'category_id' => $knowledge->id,
            'slug' => 'art-one', 'title' => 'Art One', 'status' => 'published',
            'published_at' => now(),
        ]);
        $page = Content::create([
            'site_id' => $this->site->id, 'type' => 'page', 'slug' => 'about',
            'title' => 'About', 'status' => 'published', 'published_at' => now(),
        ]);

        $this->assertSame('/knowledge/art-one', $article->path());
        $this->assertSame('https://example.com/knowledge/art-one', PublicUrl::content($article));
        $this->assertSame('/about', $page->path());
        $this->assertSame('https://example.com/about', PublicUrl::content($page));

        // v1.0 冻结：公开路径体系中不存在 /article/ 前缀段
        foreach ([$article->path(), $page->path()] as $p) {
            $this->assertStringNotContainsString('/article/', $p.'/');
            $this->assertStringStartsNotWith('/article', $p);
        }

        // Content schema 类型仅 Article / WebPage（产品已是 Entity，Content 无 Product 分支）
        $this->assertSame('Article', $article->schemaType());
        $this->assertSame('WebPage', $page->schemaType());
    }

    // ===================================================================
    // TD-09 Schema @id / canonical / PublicUrl host 统一
    // ===================================================================

    public function test_product_frontend_canonical_and_schema_use_public_url_without_host_fork(): void
    {
        $this->entity(Entity::TYPE_ORGANIZATION, 'main-co');
        $this->entity(Entity::TYPE_PRODUCT, 'core-prod', ['name' => 'Core Prod', 'metadata' => ['core' => true]]);

        $response = $this->get('/products/core-prod')->assertOk();
        $html = $response->getContent();

        // 测试进程 SAPI=cli：残留 url() 会产出 http://localhost，PublicUrl 产出站点 domain。
        // canonical 必须是真实前台路径 /products/{slug}（无尾斜杠、非单数 /product/）。
        $this->assertMatchesRegularExpression(
            '#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']https://example\.com/products/core-prod["\']#',
            $html,
            '产品详情 canonical 必须由 PublicUrl 裁决为站点 domain 下的 /products/{slug}'
        );
        $this->assertStringContainsString('https://example.com/products/core-prod', $html, 'JSON-LD / @id 必须与 canonical 同源');
        $this->assertStringNotContainsString('"@id":"https://example.com/product/', $html, '不得使用单数 /product/ 伪路径');

        // TD-09 作用域：声明性绝对 URL（canonical / og:* / JSON-LD）必须由 PublicUrl
        // 裁决到站点规范 host；导航 href、表单 action、favicon、logo 等功能性同源资源
        // 跟随请求 origin（真实 HTTP 下 origin === 规范 host，生产不分叉），不在此限。
        $this->assertMatchesRegularExpression(
            '#<meta property="og:image" content="https://example\.com/(storage/|img/og-default\.png)#',
            $html,
            'og:image 必须是站点规范 host 下的绝对 URL，不得用 asset() 跟随临时 origin'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<meta[^>]+content="http://localhost#',
            $html,
            'head 中声明性 meta URL 不得泄漏 CLI/请求 origin 分叉的 localhost host'
        );
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
        $this->assertNotEmpty($ld[1], '产品页必须输出 JSON-LD');
        foreach ($ld[1] as $block) {
            $this->assertStringNotContainsString('http://localhost', $block, 'JSON-LD 内 host 必须由 PublicUrl 统一裁决');
            $this->assertStringNotContainsString('https://example.com/product/', $block, 'JSON-LD 不得使用单数 /product/ 伪路径');
        }
    }

    public function test_catalog_degrades_gracefully_when_entities_table_missing(): void
    {
        // fresh install 极早期 / 异常库：entities 表尚未迁移时 Catalog 必须返回空而非抛 SQL 白屏
        Schema::drop('entities');
        Catalog::flush();

        $this->assertSame([], Catalog::company());
        $this->assertSame([], Catalog::products());
        $this->assertSame([], Catalog::scenes());
        $this->assertFalse(Catalog::hasProduction());
    }

    // ===================================================================
    // TD-08b PageCache 失效模型 + SiteContext memo 刷新
    // ===================================================================

    public function test_entity_save_and_delete_bump_current_site_page_cache_version(): void
    {
        $before = PageCache::version();

        $product = $this->entity(Entity::TYPE_PRODUCT, 'cache-prod');
        $this->assertSame($before + 1, PageCache::version(), '新建实体必须使当前站点整页缓存失效');

        $product->delete();
        $this->assertSame($before + 2, PageCache::version(), '删除实体必须使当前站点整页缓存失效');
    }

    public function test_entity_save_invalidates_only_its_own_site_cache(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.example.com',
            'status' => 'active', 'is_default' => false,
        ]);

        $versionB = SiteContext::withSite($siteB, static fn () => PageCache::version());
        $versionA = PageCache::version();

        // 在 A 站写入实体
        $this->entity(Entity::TYPE_PRODUCT, 'a-only-prod');

        $this->assertSame($versionA + 1, PageCache::version(), 'A 站写入必须失效 A 站缓存');
        $this->assertSame(
            $versionB,
            SiteContext::withSite($siteB, static fn () => PageCache::version()),
            'A 站写入不得失效 B 站整页缓存（缓存版本按站点分区）'
        );
    }

    public function test_site_save_invalidates_cache_and_refreshes_in_process_memo(): void
    {
        SiteContext::setSite($this->site);
        $before = PageCache::version();

        $this->site->name = 'Renamed Site';
        $this->site->save();

        // 契约是「站点保存至少失效一次整站整页缓存」。改名还会连带把 site_name 镜像
        // 写入 settings（TD-12 单一事实源），Setting 模型钩子再失效一次，故版本可能 +2；
        // 这是幂等且安全的重复失效（整站版本号），不产生脏数据，断言下界而非恰好一次。
        $this->assertGreaterThanOrEqual(
            $before + 1,
            PageCache::version(),
            '站点保存必须使整页缓存失效（含 domain / description 等非改名字段变更）'
        );
        $this->assertSame(
            'Renamed Site',
            SiteContext::currentSite()->name,
            '同进程（CLI / queue / nested）改名后 currentSite() 必须立即返回新值，不得读 stale memo'
        );
    }

    public function test_feed_endpoints_are_not_served_from_page_cache(): void
    {
        foreach (['/geo.json', '/sitemap.xml', '/llms.txt', '/feed.xml'] as $feed) {
            $first = $this->get($feed);
            $second = $this->get($feed);
            $this->assertNotSame(
                'HIT',
                $second->headers->get('X-Page-Cache'),
                "{$feed} 是机器可读 Feed，不得命中整页 HTML 缓存（首程={$first->headers->get('X-Page-Cache')}）"
            );
        }
    }
}
