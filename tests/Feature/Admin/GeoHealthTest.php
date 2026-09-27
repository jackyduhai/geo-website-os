<?php

namespace Tests\Feature\Admin;

use App\Models\Content;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Models\User;
use App\Services\Geo\GeoHealthService;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18S Capability 2：GEO Health Dashboard（语义健康只读聚合）。
 *
 * 数据流：DB(Entity/Content/EntityRelation/ContentEntity/SeoMeta) → 既有
 * PublicIndex/SeoMetaResolver/PublicUrl/SchemaBuilder → GeoHealthService → Admin View。
 * 本测试验证：鉴权、Blank 空站 N/A、Demo 站五检查、noindex 泄漏、边计数与孤立实体、
 * 核心产品落地页、HTTP 真实 JSON-LD 输出、多站隔离、只读（渲染不改库）。
 */
class GeoHealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    private function useSite(Site $site): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    private function makeEntity(Site $site, string $type, string $slug, array $attrs = []): Entity
    {
        return Entity::create(array_merge([
            'site_id' => $site->id,
            'type' => $type,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'summary' => "Summary for {$slug}",
            'description' => "Desc for {$slug}",
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'translation_group' => (string) \Illuminate\Support\Str::uuid(),
            'metadata' => [],
            'sort_order' => 0,
        ], $attrs));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.geo.health'))->assertRedirect(route('admin.login'));
    }

    public function test_blank_site_is_na_without_false_failures(): void
    {
        // 全新空站点：0 公开实体 / 0 公开内容 → 合法 N/A，不报假 Fail。
        $fresh = Site::create([
            'name' => 'Blank', 'slug' => 'blank-site', 'domain' => 'blank.test',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->useSite($fresh);

        $page = $this->actingAs($this->super)->get(route('admin.geo.health'))->assertOk();
        $report = SiteContext::withSite($fresh, fn () => app(GeoHealthService::class)->report());

        $this->assertTrue($report['blank']);
        $this->assertSame('N/A', $report['overall']);
        foreach ($report['checks'] as $c) {
            $this->assertSame('N/A', $c['status'], "check {$c['key']} should be N/A on blank site");
        }
        $page->assertSee('GEO 语义健康');
        $page->assertSee('空站');
    }

    public function test_seeded_demo_site_renders_five_checks(): void
    {
        $this->useSite($this->default);
        $report = SiteContext::withSite($this->default, fn () => app(GeoHealthService::class)->report());

        $this->assertFalse($report['blank']);
        $this->assertArrayHasKey('missing_og', $report['checks']);
        $this->assertArrayHasKey('public_url', $report['checks']);
        $this->assertArrayHasKey('jsonld', $report['checks']);
        $this->assertArrayHasKey('noindex', $report['checks']);
        $this->assertArrayHasKey('edges', $report['checks']);
        // 整体状态仅四种合法值之一。
        $this->assertContains($report['overall'], ['PASS', 'WARNING', 'FAIL', 'N/A']);

        $this->actingAs($this->super)->get(route('admin.geo.health'))->assertOk()->assertSee('GEO 健康');
    }

    public function test_noindex_leakage_is_flagged_as_warning(): void
    {
        $this->useSite($this->default);

        // 一篇已发布内容 + SeoMeta 显式 noindex → Check D 应报 WARNING 并列出。
        $content = Content::create([
            'site_id' => $this->default->id,
            'type' => 'article',
            'slug' => 'should-not-index',
            'title' => 'Hidden Article',
            'summary' => 'A published article marked noindex',
            'body' => 'body',
            'status' => 'published',
            'locale' => 'zh-CN',
            'translation_group' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        SeoMeta::create([
            'site_id' => $this->default->id, 'locale' => 'zh-CN',
            'content_id' => $content->id, 'noindex' => true,
        ]);

        $report = SiteContext::withSite($this->default, fn () => app(GeoHealthService::class)->report());
        $noindex = $report['checks']['noindex'];
        $this->assertSame('WARNING', $noindex['status']);
        $this->assertGreaterThanOrEqual(1, $noindex['counts']['published_but_noindex']);
        $this->assertContains('Hidden Article', array_column($noindex['affected'], 'label'));
    }

    public function test_edges_count_and_orphan_detection(): void
    {
        $fresh = Site::create([
            'name' => 'Edge', 'slug' => 'edge-site', 'domain' => 'edge.test',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->useSite($fresh);

        $org = $this->makeEntity($fresh, Entity::TYPE_ORGANIZATION, 'acme-corp');
        $p1 = $this->makeEntity($fresh, Entity::TYPE_PRODUCT, 'widget-pro', ['metadata' => ['core' => true]]);
        $orphan = $this->makeEntity($fresh, Entity::TYPE_PRODUCT, 'solitary-item');

        EntityRelation::create([
            'site_id' => $fresh->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $p1->id,
            'relation_type' => EntityRelation::TYPE_PRODUCES,
        ]);

        $report = SiteContext::withSite($fresh, fn () => app(GeoHealthService::class)->report());
        $edges = $report['checks']['edges'];

        $this->assertSame(1, $edges['counts']['entity_to_entity']);
        $this->assertSame(1, $edges['counts']['by_type']['produces'] ?? 0);
        // solitary-item 是公开产品但无任何边 → 孤立实体被检出。
        $this->assertGreaterThanOrEqual(1, $edges['counts']['orphan_entities']);
        $this->assertContains('Solitary-item', array_column($edges['affected'], 'label'));
    }

    public function test_core_product_resolves_public_url_pass(): void
    {
        $fresh = Site::create([
            'name' => 'Pub', 'slug' => 'pub-site', 'domain' => 'pub.test',
            'status' => 'active', 'is_default' => false,
        ]);
        $this->useSite($fresh);

        // 核心产品：PublicUrl::entity() 应解析到详情页 URL → Check B PASS（无 missing）。
        $this->makeEntity($fresh, Entity::TYPE_ORGANIZATION, 'acme');
        $p = $this->makeEntity($fresh, Entity::TYPE_PRODUCT, 'core-widget', ['metadata' => ['core' => true]]);
        EntityRelation::create([
            'site_id' => $fresh->id, 'from_entity_id' => Entity::withoutSiteScope()->where('slug', 'acme')->first()->id,
            'to_entity_id' => $p->id, 'relation_type' => EntityRelation::TYPE_PRODUCES,
        ]);

        [$url, $report] = SiteContext::withSite($fresh, function () use ($p) {
            $url = PublicUrl::entity($p);
            return [$url, app(GeoHealthService::class)->report()];
        });
        $this->assertNotNull($url, 'core product should resolve a public URL');

        $b = $report['checks']['public_url'];
        $this->assertSame(0, $b['counts']['missing_public_url']);
        $this->assertContains($b['status'], ['PASS', 'N/A']);
    }

    public function test_http_jsonld_emitted_on_home_and_product(): void
    {
        $this->useSite($this->default);

        // Home：200 + 含 ld+json + 可解码。
        $home = $this->get('/')->assertOk();
        $home->assertSee('application/ld+json', false);
        $this->assertStringContainsString('application/ld+json', $home->getContent());

        // 找一个公开核心产品真实 URL 做 HTTP 断言。
        $product = Entity::withoutSiteScope()
            ->where('site_id', $this->default->id)
            ->where('type', Entity::TYPE_PRODUCT)
            ->where('status', Entity::STATUS_PUBLISHED)
            ->get()
            ->first(fn (Entity $e) => PublicUrl::entity($e) !== null);

        $this->assertNotNull($product, 'seeded site should have at least one public product');
        $url = SiteContext::withSite($this->default, fn () => PublicUrl::entity($product));
        $resp = $this->get($url)->assertOk();
        $html = $resp->getContent();
        $this->assertStringContainsString('application/ld+json', $html);

        // 抽取并解码 ld+json，确认真实 schema payload 存在（非字符串包含方法名冒充）。
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);
        $this->assertNotEmpty($m[1], 'product page should emit at least one ld+json block');
        $decoded = false;
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && ! empty($data['@type'])) {
                $decoded = true;
                break;
            }
        }
        $this->assertTrue($decoded, 'ld+json should decode to a JSON object with @type');
    }

    public function test_site_isolation_other_site_entities_not_counted(): void
    {
        $a = Site::create(['name' => 'A', 'slug' => 'site-a', 'domain' => 'a.test', 'status' => 'active', 'is_default' => false]);
        $b = Site::create(['name' => 'B', 'slug' => 'site-b', 'domain' => 'b.test', 'status' => 'active', 'is_default' => false]);

        // 只在 A 站放一个公开产品。
        $this->useSite($a);
        $this->makeEntity($a, Entity::TYPE_ORGANIZATION, 'org-a');
        $this->makeEntity($a, Entity::TYPE_PRODUCT, 'product-a', ['metadata' => ['core' => true]]);

        // 切到 B 站：B 应为空站（A 的实体不计入）。
        $this->useSite($b);
        $report = SiteContext::withSite($b, fn () => app(GeoHealthService::class)->report());
        $this->assertTrue($report['blank'], 'site B must not see site A entities');
    }

    public function test_rendering_dashboard_is_read_only(): void
    {
        $this->useSite($this->default);
        $beforeEntities = Entity::withoutSiteScope()->count();
        $beforeRelations = EntityRelation::count();
        $beforeSeo = SeoMeta::count();

        $this->actingAs($this->super)->get(route('admin.geo.health'))->assertOk();

        $this->assertSame($beforeEntities, Entity::withoutSiteScope()->count());
        $this->assertSame($beforeRelations, EntityRelation::count());
        $this->assertSame($beforeSeo, SeoMeta::count());
    }
}
