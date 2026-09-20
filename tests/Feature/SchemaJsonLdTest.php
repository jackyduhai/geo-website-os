<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 05：统一 Schema.org / JSON-LD 输出层。
 *
 * 数据源边界：SchemaBuilder 只读 Entity / Content / Category / Site /
 * Site.metadata 通用扩展 / Setting / SeoResult——禁止直读业务事实库，
 * Core 层零业务硬编码兜底。
 *
 * 本测试验证：JSON 有效性、必填字段、URL 有效性、@id 唯一性、
 * WebSite → Organization 关联、Entity 六类冻结类型映射。
 */
class SchemaJsonLdTest extends TestCase
{
    use RefreshDatabase;

    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update([
            'name'     => 'Test Site',
            'domain'   => 'example.com',
            'metadata' => [
                'organization' => [
                    'legal_name'    => 'Legal Name Co.',
                    'telephone'     => '400-000-0000',
                    'founding_date' => '2017-03',
                    'address'       => [
                        'street'   => 'No.39 Example Street',
                        'locality' => 'Example City',
                        'region'   => 'Example Region',
                        'country'  => 'CN',
                    ],
                    'area_served'   => ['North', 'East'],
                    'knows_about'   => ['topic-a', 'topic-b'],
                ],
            ],
        ]);
        SiteContext::setSite($site);

        $this->schema = app(SchemaBuilder::class);
    }

    // ---------------------------------------------------------------
    // Organization / WebSite
    // ---------------------------------------------------------------

    public function test_organization_reads_site_extension_not_business_hardcode(): void
    {
        $org = $this->schema->organization();

        $this->assertSame('Test Site', $org['name'], '无 geo_org_name 设置时回退 Site.name，不得硬编码业务名');
        $this->assertSame('Legal Name Co.', $org['legalName']);
        $this->assertSame('400-000-0000', $org['telephone']);
        $this->assertSame('2017-03', $org['foundingDate']);
        $this->assertSame('No.39 Example Street', $org['address']['streetAddress']);
        $this->assertSame('Example City', $org['address']['addressLocality']);
        $this->assertSame(['topic-a', 'topic-b'], $org['knowsAbout']);
        $this->assertSame('Place', $org['areaServed'][0]['@type']);
    }

    public function test_base_url_uses_site_domain_https(): void
    {
        $org = $this->schema->organization();

        $this->assertStringStartsWith('https://example.com/', $org['@id']);
        $this->assertSame('https://example.com/', $org['url']);
    }

    public function test_website_links_publisher_to_organization(): void
    {
        $site = $this->schema->website();
        $org = $this->schema->organization();

        $this->assertSame('WebSite', $site['@type']);
        $this->assertSame($org['@id'], $site['publisher']['@id']);
        $this->assertSame('zh-CN', $site['inLanguage']);
    }

    public function test_organization_has_no_business_hardcode_without_seeder_data(): void
    {
        // 无 metadata 扩展、无设置时：schema 仍成立，且不含业务专属文案
        Site::where('slug', Site::DEFAULT_SLUG)->update(['metadata' => json_encode([])]);
        SiteContext::currentSite()?->refresh();

        $org = $this->schema->organization();

        $this->assertSame('Test Site', $org['name']);
        $this->assertArrayNotHasKey('legalName', $org);
        $this->assertArrayNotHasKey('areaServed', $org);
    }

    // ---------------------------------------------------------------
    // Entity 通用 Schema
    // ---------------------------------------------------------------

    public static function entityTypeMapping(): array
    {
        return [
            'organization' => ['organization', 'Organization'],
            'person'       => ['person', 'Person'],
            'product'      => ['product', 'Product'],
            'service'      => ['service', 'Service'],
            'location'     => ['location', 'Place'],
            'topic'        => ['topic', 'WebPage'],
        ];
    }

    /**
     * @dataProvider entityTypeMapping
     */
    public function test_entity_type_mapping_is_generic(string $type, string $expectedSchemaType): void
    {
        $entity = Entity::create([
            'site_id' => SiteContext::currentSite()->id,
            'type'    => $type,
            'slug'    => "entity-{$type}",
            'name'    => 'Entity ' . ucfirst($type),
            'summary' => 'Entity summary text',
            'status'  => 'published',
        ]);

        $schema = $this->schema->entity($entity);

        $this->assertSame($expectedSchemaType, $schema['@type']);
        $this->assertSame('Entity ' . ucfirst($type), $schema['name']);
        $this->assertSame('Entity summary text', $schema['description']);
        $this->assertSame('https://example.com/' . $type . '/entity-' . $type, $schema['url']);
        $this->assertSame('zh-CN', $schema['inLanguage']);
        $this->assertStringStartsWith('https://', $schema['@id']);
    }

    public function test_entity_schema_consumes_seo_result_overrides(): void
    {
        $entity = Entity::create([
            'site_id' => SiteContext::currentSite()->id,
            'type'    => 'product',
            'slug'    => 'override-product',
            'name'    => 'Plain Name',
            'status'  => 'published',
        ]);

        \App\Models\SeoMeta::create([
            'site_id'       => SiteContext::currentSite()->id,
            'entity_id'     => $entity->id,
            'title'         => 'SeoMeta Entity Title',
            'description'   => 'SeoMeta Entity Description',
            'og_image_path' => '/uploads/entity-og.jpg',
        ]);

        $schema = $this->schema->entity($entity);

        $this->assertSame('SeoMeta Entity Title', $schema['name']);
        $this->assertSame('SeoMeta Entity Description', $schema['description']);
        $this->assertStringContainsString('/uploads/entity-og.jpg', $schema['image']);
    }

    public function test_entity_metadata_extensions_flow_into_schema(): void
    {
        $entity = Entity::create([
            'site_id'  => SiteContext::currentSite()->id,
            'type'     => 'location',
            'slug'     => 'flagship',
            'name'     => 'Flagship Place',
            'status'   => 'published',
            'metadata' => [
                'same_as' => ['https://example.org/flagship'],
                'address' => ['street' => '1 Main St', 'locality' => 'City', 'country' => 'CN'],
                'geo'     => ['lat' => 41.1, 'lng' => 123.4],
            ],
        ]);

        $schema = $this->schema->entity($entity);

        $this->assertSame(['https://example.org/flagship'], $schema['sameAs']);
        $this->assertSame('1 Main St', $schema['address']['streetAddress']);
        $this->assertSame(41.1, $schema['geo']['latitude']);
    }

    // ---------------------------------------------------------------
    // 输出有效性
    // ---------------------------------------------------------------

    public function test_render_produces_valid_json_ld_fragments(): void
    {
        $html = $this->schema->render([
            $this->schema->organization(),
            $this->schema->website(),
            $this->schema->breadcrumb([
                ['name' => 'Home', 'url' => 'https://example.com/'],
                ['name' => 'News', 'url' => 'https://example.com/news/'],
            ]),
        ]);

        $this->assertNotEmpty($html);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertCount(3, $m[1]);

        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, '每个 ld+json 片段必须是有效 JSON');
            $this->assertSame('https://schema.org', $decoded['@context']);
        }
    }

    public function test_global_schemas_have_unique_ids(): void
    {
        $org = $this->schema->organization();
        $site = $this->schema->website();

        $this->assertNotSame($org['@id'], $site['@id']);
        $this->assertSame(1, preg_match('/^https:\/\/example\.com\/#/', $org['@id']));
        $this->assertSame(1, preg_match('/^https:\/\/example\.com\/#/', $site['@id']));
    }
}
