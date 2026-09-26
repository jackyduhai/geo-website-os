<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：SchemaBuilder 契约——类型→@type 映射全部走 Registry。
 *
 *   - case_study 产出 @type=CaseStudy 的 JSON-LD（含 name/description/inLanguage）；
 *   - download_asset schema=null → entity() 返回 null，不产出 JSON-LD；
 *   - 现有 6 型映射回归不变；
 *   - 硬编码 ENTITY_SCHEMA_TYPES 常量已删除（无残留引用）。
 */
class EntitySchemaContractTest extends TestCase
{
    use RefreshDatabase;

    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update(['name' => 'Test Site', 'domain' => 'example.com']);
        SiteContext::setSite($site);

        $this->schema = app(SchemaBuilder::class);
    }

    public function test_case_study_outputs_case_study_json_ld(): void
    {
        $entity = Entity::create([
            'site_id'  => SiteContext::currentSite()->id,
            'type'     => Entity::TYPE_CASE_STUDY,
            'slug'     => 'acme-case',
            'name'     => 'Acme Automotive Case',
            'summary'  => 'Reduced rust by 60%',
            'status'   => 'published',
            'metadata' => [
                'industry'  => 'automotive',
                'scenario' => 'coating',
                'challenge' => 'corrosion',
                'solution'  => 'e-coating line',
                'result'    => '60% reduction',
            ],
        ]);

        $schema = $this->schema->entity($entity);

        $this->assertNotNull($schema, 'case_study 必须产出 JSON-LD');
        $this->assertSame('CaseStudy', $schema['@type']);
        $this->assertSame('Acme Automotive Case', $schema['name']);
        $this->assertSame('Reduced rust by 60%', $schema['description']);
        $this->assertSame('zh-CN', $schema['inLanguage']);
        // 18R-2b：/cases 路由已建，case_study public=true → Schema 输出指向详情页的 url
        $this->assertArrayHasKey('url', $schema);
        $this->assertStringContainsString('/cases/acme-case', $schema['url']);
    }

    public function test_download_asset_outputs_no_json_ld(): void
    {
        $entity = Entity::create([
            'site_id'  => SiteContext::currentSite()->id,
            'type'     => Entity::TYPE_DOWNLOAD_ASSET,
            'slug'     => 'datasheet-x1',
            'name'     => 'X1 Datasheet',
            'status'   => 'published',
            'metadata' => ['media_id' => 42, 'type' => 'datasheet'],
        ]);

        $this->assertNull(
            $this->schema->entity($entity),
            'download_asset schema=null → 不得产出 JSON-LD'
        );
    }

    /**
     * 现有 6 型映射回归：product→Product、organization→Organization 等不变。
     */
    public function test_existing_six_types_schema_mapping_unchanged(): void
    {
        $matrix = [
            'organization' => 'Organization',
            'person'       => 'Person',
            'product'      => 'Product',
            'service'      => 'Service',
            'location'     => 'Place',
            'topic'        => 'WebPage',
        ];

        foreach ($matrix as $type => $expected) {
            $entity = Entity::create([
                'site_id' => SiteContext::currentSite()->id,
                'type'    => $type,
                'slug'    => "schema-{$type}",
                'name'    => 'Entity ' . $type,
                'status'  => 'published',
            ]);

            $schema = $this->schema->entity($entity);
            $this->assertNotNull($schema, "{$type} 仍应产出 JSON-LD");
            $this->assertSame($expected, $schema['@type'], "{$type} @type 回归");
        }
    }

    public function test_old_hardcoded_schema_types_constant_removed(): void
    {
        // Registry 化后，SchemaBuilder 不得再残留私有映射常量。
        $this->assertFalse(
            (new \ReflectionClass(SchemaBuilder::class))->hasConstant('ENTITY_SCHEMA_TYPES'),
            'ENTITY_SCHEMA_TYPES 硬编码常量已删除，类型映射改由 EntityCapabilityRegistry 提供'
        );
    }
}
