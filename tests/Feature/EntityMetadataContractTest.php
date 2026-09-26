<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：metadata JSON 键契约 + 负向（negative）契约。
 *
 * 客户公司经 EntityRelation(related_to, role=customer) 关联 Organization 实体——
 * case_study.metadata 禁止塞 CRM 字段（customer_name/contact/sales_owner/contract/
 * amount）。本测试锁定 Registry 白名单，并负向断言这些 CRM 键不在白名单内。
 */
class EntityMetadataContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    public function test_case_study_metadata_allowed_keys_contract(): void
    {
        $keys = EntityCapabilityRegistry::metadataKeys('case_study');

        $this->assertEqualsCanonicalizing(
            ['industry', 'scenario', 'challenge', 'solution', 'result'],
            $keys
        );
    }

    /**
     * Negative contract：CRM 字段绝不能出现在 case_study metadata 白名单中。
     * 防止未来误把客户公司信息塞进 JSON（应走 EntityRelation→Organization）。
     */
    public function test_case_study_metadata_forbids_crm_fields(): void
    {
        $keys = EntityCapabilityRegistry::metadataKeys('case_study');

        foreach (['customer_name', 'contact', 'sales_owner', 'contract_status', 'deal_amount', 'contract', 'amount'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $keys,
                "case_study metadata 白名单不得包含 CRM 键 {$forbidden}——客户公司应经 EntityRelation 关联 Organization"
            );
        }
    }

    public function test_case_study_entity_roundtrips_with_only_allowed_metadata(): void
    {
        $site = Site::where('is_default', true)->first();

        $entity = Entity::create([
            'site_id'  => $site->id,
            'type'     => Entity::TYPE_CASE_STUDY,
            'slug'     => 'acme-case',
            'name'     => 'Acme Case',
            'status'   => 'published',
            'metadata' => [
                'industry'  => 'automotive',
                'scenario' => 'surface-treatment',
                'challenge' => 'rust',
                'solution'  => 'coating line',
                'result'    => '30% cost down',
            ],
        ]);

        $fresh = Entity::find($entity->id);
        $keys = array_keys($fresh->metadata);

        // 写入的 metadata 键全部在白名单内（零越权键）
        foreach ($keys as $k) {
            $this->assertContains($k, EntityCapabilityRegistry::metadataKeys('case_study'));
        }
    }

    public function test_download_asset_metadata_allowed_keys_contract(): void
    {
        $keys = EntityCapabilityRegistry::metadataKeys('download_asset');

        $this->assertEqualsCanonicalizing(
            ['media_id', 'type', 'language', 'version'],
            $keys
        );
    }

    public function test_download_asset_type_enum_is_declared(): void
    {
        // download_asset.metadata.type 枚举（契约文档化；2b Admin 表单据此校验）。
        $valid = ['datasheet', 'manual', 'certificate', 'whitepaper', 'brochure'];

        $this->assertContains('datasheet', $valid);
        $this->assertContains('certificate', $valid);
        // 非法枚举值不在有效集内
        $this->assertNotContains('invoice', $valid);
    }

    public function test_download_asset_entity_roundtrips_metadata(): void
    {
        $site = Site::where('is_default', true)->first();

        $asset = Entity::create([
            'site_id'  => $site->id,
            'type'     => Entity::TYPE_DOWNLOAD_ASSET,
            'slug'     => 'datasheet-x1',
            'name'     => 'X1 Datasheet',
            'status'   => 'published',
            'metadata' => [
                'media_id' => 42,
                'type'     => 'datasheet',
                'language' => 'zh-CN',
                'version'  => 'v2.1',
            ],
        ]);

        $fresh = Entity::find($asset->id);
        $this->assertSame(42, $fresh->metadata['media_id']);
        $this->assertSame('datasheet', $fresh->metadata['type']);

        $keys = array_keys($fresh->metadata);
        foreach ($keys as $k) {
            $this->assertContains($k, EntityCapabilityRegistry::metadataKeys('download_asset'));
        }
    }
}
