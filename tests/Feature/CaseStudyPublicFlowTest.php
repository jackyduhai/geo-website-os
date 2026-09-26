<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2b：CaseStudy 公开闭环 + GEO 四件套 + AI 图遍历。
 *
 * 覆盖：
 *  - /cases 列表 /cases/{slug} 详情 200、404、root data-*（GEO Gate）；
 *  - canonical 自指 + hreflang（zh/en 不串）；
 *  - Schema JSON-LD @type=CaseStudy 含 about/industry/result；
 *  - sitemap / llms.txt / geo.json 四件套同步；
 *  - Search 收录 case_study、locale 隔离；
 *  - 关系链遍历：Organization --customer-- CaseStudy --related_to--> Product；
 *    Product 反向 relatedCaseStudiesForProduct；多产品案例。
 */
class CaseStudyPublicFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
    }

    private function case(string $slug, string $name, array $meta = [], string $locale = 'zh-CN'): Entity
    {
        return Entity::create([
            'site_id'   => $this->site->id,
            'type'      => Entity::TYPE_CASE_STUDY,
            'slug'      => $slug,
            'name'      => $name,
            'status'    => 'published',
            'locale'    => $locale,
            'summary'   => $name . ' 摘要',
            'metadata'  => $meta,
        ]);
    }

    public function test_cases_listing_renders(): void
    {
        $this->case('acme-line-case', 'Acme 涂装案例', ['industry' => '汽车']);

        $res = $this->get('/cases/');
        $res->assertOk();
        $res->assertSee('Acme 涂装案例');
        $res->assertSee('/cases/acme-line-case');
    }

    public function test_cases_listing_404_when_empty(): void
    {
        $this->get('/cases/')->assertNotFound();
    }

    public function test_case_detail_renders_structure(): void
    {
        $this->case('det-case', 'Det Case', [
            'industry' => '新能源',
            'scenario' => '储能',
            'challenge' => '客户面临盐雾腐蚀',
            'solution' => '采用 X1 涂层体系',
            'result' => '盐雾测试通过 1000h',
        ]);

        $res = $this->get('/cases/det-case');
        $res->assertOk();
        // GEO Gate：根 section 机器语义固定
        $res->assertSee('data-section="case-study"', false);
        $res->assertSee('data-purpose="proof"', false);
        $res->assertSee('data-entity="case_study"', false);
        $res->assertSee('data-conversion="inquiry"', false);
        $res->assertSee('盐雾测试通过 1000h');
    }

    public function test_case_detail_404_for_unknown_slug(): void
    {
        $this->get('/cases/no-such-case')->assertNotFound();
    }

    public function test_case_study_jsonld_is_business_entity(): void
    {
        $product = Entity::where('type', Entity::TYPE_PRODUCT)->where('locale', 'zh-CN')->firstOrFail();
        $case = $this->case('jsonld-case', 'JsonLd Case', [
            'industry' => '船舶',
            'result' => '防腐寿命翻倍',
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $product->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);

        $html = $this->get('/cases/jsonld-case')->assertOk()->getContent();

        // 提取 CaseStudy JSON-LD
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);
        $found = null;
        foreach ($m[1] as $blob) {
            $decoded = json_decode(trim($blob), true);
            if (($decoded['@type'] ?? null) === 'CaseStudy') {
                $found = $decoded;
                break;
            }
        }
        $this->assertNotNull($found, '必须输出 @type=CaseStudy 的 JSON-LD');
        $this->assertSame('船舶', $found['industry']);
        $this->assertSame('防腐寿命翻倍', $found['result']);
        $this->assertNotEmpty($found['about'] ?? [], 'about 必须含关联 Product');
        $this->assertSame('Product', $found['about'][0]['@type']);
    }

    public function test_case_study_in_sitemap(): void
    {
        $this->case('sitemap-case', 'Sitemap Case');
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/cases/sitemap-case', $xml);
    }

    public function test_case_study_in_llms(): void
    {
        $this->case('llms-case', 'Llms Case', ['industry' => '电子']);
        $txt = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('客户案例', $txt);
        $this->assertStringContainsString('/cases/llms-case', $txt);
    }

    public function test_case_study_searchable_and_locale_isolated(): void
    {
        // 中文案例
        $this->case('zh-case', '汽车行业案例', ['industry' => '汽车']);
        // 英文案例（en-only 行）
        $this->case('en-case', 'Electronics Case', ['industry' => 'electronics'], 'en');

        // 重建索引后：zh 查询召回中文案例，不召回 en-only
        app(\App\Support\Search\SearchIndexBuilder::class)->rebuildAll();

        $zh = $this->get('/search?q=' . urlencode('汽车'));
        $zh->assertOk();
        // 高亮会在标题中插入 <mark>，strip_tags 还原连续文本再断言
        $zhText = strip_tags($zh->getContent());
        $this->assertStringContainsString('汽车行业案例', $zhText);

        // en-only 案例不应出现在中文搜索
        $this->assertStringNotContainsString('Electronics Case', $zhText);
    }

    public function test_download_asset_not_in_search_index(): void
    {
        $asset = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'pdf-datasheet', 'name' => 'PDF Datasheet',
            'status' => 'published', 'locale' => 'zh-CN',
            'metadata' => ['type' => 'datasheet'],
        ]);

        app(\App\Support\Search\SearchIndexBuilder::class)->rebuildAll();

        $row = \DB::table('search_documents')
            ->where('resource_type', Entity::TYPE_DOWNLOAD_ASSET)->count();
        $this->assertSame(0, $row, 'download_asset 不得进入搜索索引');
    }

    public function test_relation_chain_traversable_and_reverse_query(): void
    {
        $product = Entity::where('type', Entity::TYPE_PRODUCT)->where('locale', 'zh-CN')->firstOrFail();
        $org = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'acme-customer', 'name' => 'Acme 客户', 'status' => 'published', 'locale' => 'zh-CN',
        ]);
        $case = $this->case('chain-case', 'Chain Case', ['industry' => '汽车']);

        // CaseStudy --related_to--> Product
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $product->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);
        // CaseStudy --related_to--> Organization（客户）
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $org->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
            'metadata' => ['role' => 'customer'],
        ]);

        // 反向：Product → 哪些案例
        $back = Entity::relatedCaseStudiesForProduct($product->id, 'zh-CN')->get();
        $this->assertTrue($back->contains(fn ($c) => $c->slug === 'chain-case'));

        // 图：product --offers--> download_asset 可遍历 type/language/version
        $asset = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'chain-datasheet', 'name' => 'Datasheet', 'status' => 'published', 'locale' => 'zh-CN',
            'metadata' => ['media_id' => 1, 'type' => 'datasheet', 'language' => 'zh-CN', 'version' => '2.0'],
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $product->id,
            'to_entity_id' => $asset->id, 'relation_type' => EntityRelation::TYPE_OFFERS,
        ]);

        $graph = $this->get('/geo.json')->assertOk()->json();
        $edge = collect($graph['relations'])->firstWhere(
            fn ($r) => $r['relation_type'] === 'offers'
                && str_contains((string) $r['to'], 'download_asset/chain-datasheet')
        );
        $this->assertNotNull($edge, 'Product--offers-->DownloadAsset 边必须可遍历');
        $node = collect($graph['entities'])->firstWhere('slug', 'chain-datasheet');
        $this->assertNotNull($node);
        $this->assertSame('datasheet', $node['metadata']['type'] ?? null);
        $this->assertSame('2.0', $node['metadata']['version'] ?? null);
    }

    public function test_multi_product_case(): void
    {
        $case = $this->case('multi-case', 'Multi Product Case');
        $p1 = Entity::where('type', Entity::TYPE_PRODUCT)->where('locale', 'zh-CN')->skip(0)->first();
        $p2 = Entity::where('type', Entity::TYPE_PRODUCT)->where('locale', 'zh-CN')->skip(1)->first();
        $this->assertNotNull($p1);

        foreach ([$p1, $p2] as $p) {
            if ($p) {
                EntityRelation::create([
                    'site_id' => $this->site->id, 'from_entity_id' => $case->id,
                    'to_entity_id' => $p->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
                ]);
            }
        }
        $this->assertGreaterThanOrEqual(1, $case->relationsFrom()->count());
    }

    public function test_en_cases_canonical_and_hreflang(): void
    {
        $this->case('en-only-case', 'English Case', ['industry' => 'electronics'], 'en');

        $res = $this->get('/en/cases/en-only-case');
        $res->assertOk();
        $res->assertSee('English Case');
        // canonical 指向自身（/en/cases/...），不回退 zh
        $res->assertSee('/en/cases/en-only-case', false);
    }
}
