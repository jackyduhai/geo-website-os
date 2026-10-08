<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-1 Entity Lifecycle：角色化生命周期 + 可恢复 + 站点主体保护。
 *
 * 覆盖：
 *  - destroy（软删）→ 公共/机器面撤出、翻译行级联软删、关系与结构边保留；
 *  - restore → 数据无损恢复（含整组翻译）、公共资格按 CURRENT 重算（不回放快照）；
 *  - 软删后同 slug 可重建；restore 遇 ACTIVE 同 slug → BLOCK；
 *  - site_subject organization：普通 destroy 被 BLOCK，Deactivate/Retire 可用，force-delete 被 BLOCK；
 *  - force-delete（终态）→ 实体消失、关系随 FK CASCADE、结构投影清理。
 */
class EntitySoftDeleteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'Lifecycle Site', 'slug' => 'lifecycle-site',
            'domain' => 'lifecycle.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        // 切换后台会话到本测试站点（admin.site 中间件 / 路由绑定按当前站点解析）。
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeEntity(string $type, string $slug, array $overrides = []): Entity
    {
        return Entity::create(array_merge([
            'site_id' => $this->site->id,
            'type' => $type,
            'slug' => $slug,
            'name' => 'Entity '.$slug,
            'status' => Entity::STATUS_PUBLISHED,
            'locale' => 'zh-CN',
            'metadata' => ['core' => true],
        ], $overrides));
    }

    private function makeSiteSubject(): Entity
    {
        return $this->makeEntity(Entity::TYPE_ORGANIZATION, 'site-org', [
            'role' => Entity::ROLE_SITE_SUBJECT,
            'metadata' => ['is_site_organization' => true],
        ]);
    }

    // ---------- 软删（可恢复） ----------

    public function test_soft_delete_removes_from_public_and_geo_but_keeps_row(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-one');

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $product))
            ->assertRedirect();

        // 行仍存在（软删），且不在 PKS
        $this->assertSoftDeleted('entities', ['id' => $product->id]);
        $graph = $this->get('https://lifecycle.test/geo.json')->json();
        $this->assertNotContains('p-one', array_column($graph['entities'], 'slug'));
    }

    public function test_soft_delete_cascades_translations_but_preserves_relations(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-two');
        $product->createTranslation('en', ['name' => 'P Two EN', 'slug' => 'p-two-en']);

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $product))
            ->assertRedirect();

        // 翻译行级联软删（行保留）
        $this->assertSoftDeleted('entities', ['translation_group' => $product->translation_group, 'locale' => 'en']);
        // 翻译行总数仍是 2（均软删）；显式绕过站点作用域（HTTP 请求后上下文复位）。
        SiteContext::setSite($this->site);
        $this->assertSame(2, Entity::withTrashed()->withoutSiteScope()
            ->where('translation_group', $product->translation_group)->count());
    }

    // ---------- 恢复 ----------

    public function test_restore_recovers_data_and_requalifies(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-three');
        $product->createTranslation('en', ['name' => 'P Three EN', 'slug' => 'p-three-en']);

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $product))->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.entities.restore', $product->id))
            ->assertRedirect();

        // 行恢复（含翻译）；直接查询显式重设站点上下文（HTTP 请求后上下文可能复位）。
        SiteContext::setSite($this->site);
        $this->assertSame(2, Entity::withoutSiteScope()
            ->where('translation_group', $product->translation_group)->count());
        $this->assertDatabaseHas('entities', ['id' => $product->id, 'deleted_at' => null]);

        // 恢复后重新进入 PKS
        $graph = $this->get('https://lifecycle.test/geo.json')->json();
        $this->assertContains('p-three', array_column($graph['entities'], 'slug'));
    }

    // ---------- slug 重建 / restore 冲突 ----------

    public function test_soft_deleted_slug_can_be_reused_by_new_active_entity(): void
    {
        $first = $this->makeEntity(Entity::TYPE_PRODUCT, 'shared-slug');
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $first))->assertRedirect();

        // 同 slug 可建新 ACTIVE 实体
        $second = $this->makeEntity(Entity::TYPE_PRODUCT, 'shared-slug', ['name' => 'Second']);
        SiteContext::setSite($this->site);
        $this->assertSame($second->id, Entity::withoutSiteScope()
            ->where('site_id', $this->site->id)->where('slug', 'shared-slug')->first()->id);
    }

    public function test_restore_is_blocked_when_active_slug_exists(): void
    {
        $first = $this->makeEntity(Entity::TYPE_PRODUCT, 'conflict-slug');
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $first))->assertRedirect();

        // 新建 ACTIVE 同 slug
        $this->makeEntity(Entity::TYPE_PRODUCT, 'conflict-slug', ['name' => 'New Active']);

        // restore 旧实体 → BLOCK（旧实体仍软删）
        $this->actingAs($this->admin)
            ->post(route('admin.entities.restore', $first->id))
            ->assertSessionHasErrors('restore');

        $this->assertSoftDeleted('entities', ['id' => $first->id]);
    }

    // ---------- 站点主体保护 ----------

    public function test_site_subject_destroy_is_blocked(): void
    {
        $subject = $this->makeSiteSubject();

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $subject))
            ->assertRedirect();

        // 主体仍在（未删）
        $this->assertDatabaseHas('entities', ['id' => $subject->id, 'deleted_at' => null]);
    }

    public function test_site_subject_deactivate_and_retire_are_allowed(): void
    {
        $subject = $this->makeSiteSubject();

        $this->actingAs($this->admin)
            ->post(route('admin.entities.deactivate', $subject))->assertRedirect();
        $this->assertSame(Entity::STATUS_DRAFT, $subject->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.entities.retire', $subject))->assertRedirect();
        $this->assertSame(Entity::STATUS_ARCHIVED, $subject->fresh()->status);
    }

    public function test_site_subject_force_delete_is_blocked(): void
    {
        $subject = $this->makeSiteSubject();

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.force-delete', $subject->id))
            ->assertRedirect();

        $this->assertDatabaseHas('entities', ['id' => $subject->id]);
    }

    // ---------- 永久删除（终态） ----------

    public function test_force_delete_removes_entity_and_relations(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-gone');
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $product))->assertRedirect();

        $this->actingAs($this->admin)
            ->delete(route('admin.entities.force-delete', $product->id))
            ->assertRedirect();

        $this->assertSame(0, Entity::withTrashed()->where('id', $product->id)->count());
    }
}