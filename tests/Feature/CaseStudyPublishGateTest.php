<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2b Gate：禁止孤儿案例（服务端发布门禁）。
 * case_study 发布必须同时具备 ≥1 related_to Product/Service + ≥1 related_to Organization(role=customer)，
 * 否则 publish 被 ValidationException 阻断。
 */
class CaseStudyPublishGateTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->site);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_publish_blocked_without_relations(): void
    {
        $case = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'orphan-case', 'name' => 'Orphan', 'status' => Entity::STATUS_DRAFT,
            'locale' => 'zh-CN', 'metadata' => ['industry' => 'x'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post(route('admin.entities.publish', $case));
        $res->assertSessionHasErrors('status');
        // 仍为 draft，未发布
        $this->assertSame(Entity::STATUS_DRAFT, $case->fresh()->status);
        $this->get('/cases/orphan-case')->assertNotFound();
    }

    public function test_publish_succeeds_with_product_and_customer(): void
    {
        $case = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'complete-case', 'name' => 'Complete', 'status' => Entity::STATUS_DRAFT,
            'locale' => 'zh-CN', 'metadata' => ['industry' => 'x', 'result' => 'good'],
        ]);
        $product = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'gate-prod', 'name' => 'Gate Prod', 'status' => 'published', 'locale' => 'zh-CN',
        ]);
        $cust = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'gate-cust', 'name' => 'Gate Cust', 'status' => 'published', 'locale' => 'zh-CN',
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $product->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
        ]);
        EntityRelation::create([
            'site_id' => $this->site->id, 'from_entity_id' => $case->id,
            'to_entity_id' => $cust->id, 'relation_type' => EntityRelation::TYPE_RELATED_TO,
            'metadata' => ['role' => 'customer'],
        ]);

        $this->actingAs($this->admin)->post(route('admin.entities.publish', $case))->assertRedirect();
        $this->assertSame(Entity::STATUS_PUBLISHED, $case->fresh()->status);
        $this->get('/cases/complete-case')->assertOk();
    }
}
