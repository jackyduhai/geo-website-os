<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2a：跨类型关系契约（复用现有 5 种 relation_type，不新增）。
 *
 * 方向约定（Gate D）：
 *   - CaseStudy --related_to--> Product（一个案例可挂多个产品）
 *   - CaseStudy --related_to--> Organization（客户公司，metadata.role=customer）
 *   - Product --offers--> DownloadAsset（产品提供资料；资料经 metadata.media_id 引用 Media）
 * 不得出现 Media owns Product 这类让 Media 成为业务中心的反向关系。
 */
class EntityRelationCrossTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    private function site(): Site
    {
        return Site::where('is_default', true)->first();
    }

    public function test_case_study_related_to_product_is_queryable(): void
    {
        $site = $this->site();

        $case = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'case-1', 'name' => 'Case 1', 'status' => 'published',
        ]);
        $product = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-a', 'name' => 'Product A', 'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $case->id,
            'to_entity_id' => $product->id,
            'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);

        $this->assertNotNull($relation->id);
        // 方向：case_study → product
        $this->assertSame($case->id, $relation->from_entity_id);
        $this->assertSame($product->id, $relation->to_entity_id);
        $this->assertSame(Entity::TYPE_CASE_STUDY, $relation->fromEntity->type);
        $this->assertSame(Entity::TYPE_PRODUCT, $relation->toEntity->type);
    }

    public function test_case_study_related_to_multiple_products(): void
    {
        $site = $this->site();

        $case = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'case-multi', 'name' => 'Case Multi', 'status' => 'published',
        ]);
        $p1 = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-m1', 'name' => 'Prod M1', 'status' => 'published',
        ]);
        $p2 = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-m2', 'name' => 'Prod M2', 'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $p1->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);
        EntityRelation::create([
            'site_id' => $site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $p2->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);

        $this->assertSame(2, $case->relationsFrom()->count());
    }

    public function test_case_study_related_to_customer_organization_with_role_metadata(): void
    {
        $site = $this->site();

        $case = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'case-cust', 'name' => 'Case Cust', 'status' => 'published',
        ]);
        $customer = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'customer-co', 'name' => 'Customer Co', 'status' => 'published',
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $case->id,
            'to_entity_id' => $customer->id,
            'relation_type' => EntityRelation::TYPE_RELATED_TO,
            'metadata' => ['role' => 'customer'],
        ]);

        $fresh = EntityRelation::find($relation->id);
        $this->assertSame('customer', $fresh->metadata['role']);
        $this->assertSame(Entity::TYPE_ORGANIZATION, $fresh->toEntity->type);
    }

    public function test_product_offers_download_asset_direction(): void
    {
        $site = $this->site();

        $product = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-offer', 'name' => 'Prod Offer', 'status' => 'published',
        ]);
        $asset = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'asset-offer', 'name' => 'Asset Offer', 'status' => 'published',
            'metadata' => ['media_id' => 99, 'type' => 'manual'],
        ]);

        $relation = EntityRelation::create([
            'site_id' => $site->id,
            'from_entity_id' => $product->id,
            'to_entity_id' => $asset->id,
            'relation_type' => EntityRelation::TYPE_OFFERS,
        ]);

        // 方向：product offers asset（不是 asset → product）
        $this->assertSame($product->id, $relation->from_entity_id);
        $this->assertSame($asset->id, $relation->to_entity_id);
        $this->assertSame(Entity::TYPE_PRODUCT, $relation->fromEntity->type);
        $this->assertSame(Entity::TYPE_DOWNLOAD_ASSET, $relation->toEntity->type);
    }

    public function test_cross_site_relation_rejected_for_new_types(): void
    {
        $siteA = $this->site();
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b-x', 'domain' => 'b-x.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $caseA = Entity::create([
            'site_id' => $siteA->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'case-a', 'name' => 'Case A', 'status' => 'published',
        ]);
        $assetB = Entity::create([
            'site_id' => $siteB->id, 'type' => Entity::TYPE_DOWNLOAD_ASSET,
            'slug' => 'asset-b', 'name' => 'Asset B', 'status' => 'published',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-site violation');

        EntityRelation::create([
            'site_id' => $siteA->id,
            'from_entity_id' => $caseA->id,
            'to_entity_id' => $assetB->id,
            'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);
    }

    public function test_duplicate_relation_unique_constraint(): void
    {
        $site = $this->site();

        $case = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'case-dup', 'name' => 'Case Dup', 'status' => 'published',
        ]);
        $product = Entity::create([
            'site_id' => $site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-dup', 'name' => 'Prod Dup', 'status' => 'published',
        ]);

        EntityRelation::create([
            'site_id' => $site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $product->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        EntityRelation::create([
            'site_id' => $site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $product->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);
    }
}
