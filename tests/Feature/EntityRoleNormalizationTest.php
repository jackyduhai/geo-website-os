<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENT-03 — Entity role 列归一化防回归（WP-1）。
 * ------------------------------------------------------------------
 * role 列是 organization 生命周期策略的唯一权威。对只写 metadata 的路径
 * （覆盖向导 / Seeder / 测试），Entity::saving 会在未显式设置 role 时兜底：
 *   - metadata.is_site_organization → role=site_subject
 *   - metadata.role=customer        → role=customer
 * 现有 EntitySoftDeleteLifecycleTest 总是显式写 role，未覆盖该归一化兜底。
 * 本测试直接验证归一化结果、优先级、显式 role 优先及非 organization 不被归一。
 */
class EntityRoleNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function makeOrganization(array $metadata, ?string $role = null): Entity
    {
        $attrs = [
            'site_id' => $this->site->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'org-'.uniqid(),
            'name' => '某组织',
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'metadata' => $metadata,
        ];
        if ($role !== null) {
            $attrs['role'] = $role;
        }

        return Entity::create($attrs);
    }

    public function test_site_organization_metadata_normalizes_to_site_subject_role(): void
    {
        $e = $this->makeOrganization(['is_site_organization' => true]);

        $this->assertSame(Entity::ROLE_SITE_SUBJECT, $e->role);
        $this->assertTrue($e->isSiteSubject());
        $this->assertFalse($e->isCustomer());
    }

    public function test_customer_metadata_normalizes_to_customer_role(): void
    {
        $e = $this->makeOrganization(['role' => 'customer']);

        $this->assertSame(Entity::ROLE_CUSTOMER, $e->role);
        $this->assertTrue($e->isCustomer());
        $this->assertFalse($e->isSiteSubject());
    }

    public function test_explicit_role_takes_precedence_over_metadata(): void
    {
        // metadata 指向站点主体，但显式 role=customer 应优先。
        $e = $this->makeOrganization(
            ['is_site_organization' => true],
            Entity::ROLE_CUSTOMER,
        );

        $this->assertSame(Entity::ROLE_CUSTOMER, $e->role);
        $this->assertTrue($e->isCustomer());
        $this->assertFalse($e->isSiteSubject());
    }

    public function test_organization_without_role_metadata_keeps_null_role(): void
    {
        $e = $this->makeOrganization(['core' => true]);

        $this->assertNull($e->role);
        $this->assertFalse($e->isSiteSubject());
        $this->assertFalse($e->isCustomer());
    }

    public function test_non_organization_does_not_get_role_from_metadata(): void
    {
        // 即使 product 带了 is_site_organization，也不应被归一为任何 role。
        $p = Entity::create([
            'site_id' => $this->site->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => 'prod-'.uniqid(),
            'name' => '某产品',
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'metadata' => ['is_site_organization' => true],
        ]);

        $this->assertNull($p->role);
    }

    public function test_site_subject_metadata_takes_priority_over_customer_metadata(): void
    {
        // 两个标记同时存在：is_site_organization 分支先判定 → site_subject。
        $e = $this->makeOrganization([
            'is_site_organization' => true,
            'role' => 'customer',
        ]);

        $this->assertSame(Entity::ROLE_SITE_SUBJECT, $e->role);
    }

    public function test_normalized_role_persists_to_database(): void
    {
        $e = $this->makeOrganization(['role' => 'customer']);

        $this->assertDatabaseHas('entities', [
            'id' => $e->id,
            'role' => Entity::ROLE_CUSTOMER,
        ]);
    }
}
