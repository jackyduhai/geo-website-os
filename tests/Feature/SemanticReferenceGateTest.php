<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Models\User;
use App\Services\Gate\ContentGate;
use App\Services\Gate\Contracts\SemanticReferenceResolver;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-3 Entity References + ContentGate：G-7 五分支完整集成 + 无副作用断言。
 *
 * 五分支（真实 tombstone 联调，WP-4 已先建成）：
 *   VALID          → PASS
 *   DRAFT          → PASS + WARNING
 *   SOFT_DELETED   → PASS + WARNING
 *   NOINDEX        → PASS + WARNING
 *   KNOWN_DELETED  → PASS + WARNING（purge 后有墓碑）
 *   UNKNOWN_ID     → BLOCK（引用本身不存在）
 *
 * 关键：WARNING / BLOCK 均不得改变 Content 业务状态（不拉 draft / noindex）。
 */
class SemanticReferenceGateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;
    protected ContentGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'SemRef Site', 'slug' => 'semref-site',
            'domain' => 'semref.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
        $this->gate = app(ContentGate::class);
    }

    private function makeEntity(string $slug, string $status = Entity::STATUS_PUBLISHED): Entity
    {
        return Entity::create([
            'site_id' => $this->site->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => $slug,
            'name' => 'Entity ' . $slug,
            'status' => $status,
            'locale' => 'zh-CN',
        ]);
    }

    /** 一份除 entity_refs 外满足全部门禁的内容（默认 draft，门禁只校验字段）。 */
    private function makeContent(array $entityRefs): \App\Models\Content
    {
        return \App\Models\Content::create([
            'site_id' => $this->site->id,
            'type' => 'article',
            'title' => '语义引用门禁测试文章',
            'slug' => 'semref-gate-' . count($entityRefs) . '-' . uniqid(),
            'summary' => '用于验证语义引用五分支。',
            'body' => "## 正文\n\n本内容谈论若干实体。",
            'geo_conclusion' => '本内容通过语义引用指向若干实体，门禁按其生命周期判定。',
            'geo_explanation' => '引用有效则通过；引用指向草稿、删除、noindex 或已知清除实体则告警。',
            'geo_boundary' => '引用从未登记的实体属数据错误，不允许发布。',
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二', 'source' => '来源二'],
            ],
            'owner' => 'Administrator',
            'reviewed_at' => '2026-10-09',
            'entity_refs' => $entityRefs,
        ]);
    }

    /** 软删实体（经后台路由）。 */
    private function softDelete(Entity $e): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $e))->assertRedirect();
    }

    /** 物理删除（purge）：软删 → 强制删除，产生墓碑。 */
    private function purge(Entity $e): void
    {
        $this->softDelete($e);
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.force-delete', $e->id))->assertRedirect();
    }

    public function test_valid_entity_passes_without_warning(): void
    {
        $valid = $this->makeEntity('p-valid');
        $result = $this->gate->check($this->makeContent([$valid->id]));

        $this->assertTrue($result['passed']);
        $this->assertSame([], $this->entityRefMessages($result['warnings']));
    }

    public function test_draft_entity_passes_with_warning(): void
    {
        $draft = $this->makeEntity('p-draft', Entity::STATUS_DRAFT);
        $result = $this->gate->check($this->makeContent([$draft->id]));

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('未公开（草稿）', implode('', $result['warnings']));
    }

    public function test_soft_deleted_entity_passes_with_warning(): void
    {
        $soft = $this->makeEntity('p-soft');
        $this->softDelete($soft);
        $result = $this->gate->check($this->makeContent([$soft->id]));

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('已删除实体', implode('', $result['warnings']));
    }

    public function test_noindex_entity_passes_with_warning(): void
    {
        $noindex = $this->makeEntity('p-noindex');
        SeoMeta::create([
            'site_id' => $this->site->id,
            'entity_id' => $noindex->id,
            'locale' => 'zh-CN',
            'noindex' => true,
        ]);
        $result = $this->gate->check($this->makeContent([$noindex->id]));

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('noindex 实体', implode('', $result['warnings']));
    }

    public function test_known_deleted_entity_passes_with_warning(): void
    {
        $purged = $this->makeEntity('p-purged');
        $purgedId = $purged->id;
        $this->purge($purged);
        $result = $this->gate->check($this->makeContent([$purgedId]));

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('已永久删除实体', implode('', $result['warnings']));
    }

    public function test_unknown_id_blocks_publish(): void
    {
        $result = $this->gate->check($this->makeContent([999999]));

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('从未被系统确认存在', implode('', $result['errors']));
    }

    public function test_warning_does_not_change_public_content_state(): void
    {
        // 已发布的公开内容引用 noindex 实体 → 门禁告警，但内容仍 public，不被改 draft / noindex。
        $noindex = $this->makeEntity('p-noindex-public');
        SeoMeta::create([
            'site_id' => $this->site->id,
            'entity_id' => $noindex->id,
            'locale' => 'zh-CN',
            'noindex' => true,
        ]);
        $content = $this->makeContent([$noindex->id]);
        $content->status = Entity::STATUS_PUBLISHED;
        $content->save();

        $result = $this->gate->check($content);

        $this->assertTrue($result['passed']);
        $this->assertNotEmpty($result['warnings']);
        // 回读 DB：业务状态仍是 published，未被门禁副作用改变。
        $fresh = \App\Models\Content::find($content->id);
        $this->assertSame(Entity::STATUS_PUBLISHED, $fresh->status);
        // 内容自身没有被加 noindex（noindex 只存在于实体的 SeoMeta）。
        $this->assertDatabaseMissing('seo_metas', ['content_id' => $content->id, 'noindex' => true]);
    }

    public function test_block_does_not_change_content_state(): void
    {
        // 已发布内容引用 UNKNOWN-ID → 门禁 BLOCK，但内容仍保持原状态，只是无法通过本次发布。
        $content = $this->makeContent([999999]);
        $content->status = Entity::STATUS_PUBLISHED;
        $content->save();

        $result = $this->gate->check($content);

        $this->assertFalse($result['passed']);
        $fresh = \App\Models\Content::find($content->id);
        $this->assertSame(Entity::STATUS_PUBLISHED, $fresh->status);
    }

    public function test_publish_route_block_keeps_content_draft(): void
    {
        // 端到端：draft 内容带 UNKNOWN-ID 引用，经发布路由被拒，仍保持 draft。
        $content = $this->makeContent([999999]);

        $this->actingAs($this->admin)
            ->post(route('admin.contents.publish', $content))
            ->assertRedirect()
            ->assertSessionHas('gateErrors');

        // HTTP 请求后上下文可能复位，重设后再断言业务状态仍为 draft。
        SiteContext::setSite($this->site);
        $this->assertSame(Entity::STATUS_DRAFT, \App\Models\Content::find($content->id)->status);
    }

    /** 从 warnings 中筛出语义引用相关条目（用于断言 VALID 无引用警告）。 */
    private function entityRefMessages(array $warnings): array
    {
        return array_values(array_filter($warnings, fn ($w) => str_contains((string) $w, '语义引用')));
    }
}
