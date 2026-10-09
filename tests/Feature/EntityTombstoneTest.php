<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityTombstone;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\SiteContext;
use App\Support\TombstoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-4 Tombstone Registry：physical purge 写入墓碑，区分 KNOWN-DELETED / UNKNOWN-ID。
 *
 * 覆盖：
 *  - force-delete（purge）写入一条墓碑，含原实体身份快照（site/type/slug/name/role/purged_at/purged_by）；
 *  - 本站有墓碑 → KNOWN-DELETED；从未登记 id → UNKNOWN-ID；
 *  - 跨站隔离（墓碑不跨站命中）；
 *  - 幂等（重复 record / 含翻译组 purge 只写一条）；
 *  - 墓碑不复活实体、不进入 PKS / GEO 图谱。
 */
class EntityTombstoneTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;
    protected TombstoneResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'Tombstone Site', 'slug' => 'tombstone-site',
            'domain' => 'tombstone.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
        $this->resolver = new TombstoneResolver();
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

    /** 经后台路由完成 软删 → 永久删除（purge）。 */
    private function purge(Entity $e): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.destroy', $e))->assertRedirect();
        $this->actingAs($this->admin)
            ->delete(route('admin.entities.force-delete', $e->id))->assertRedirect();
    }

    public function test_force_delete_writes_tombstone_with_identity_snapshot(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-purged');

        $this->purge($product);

        $this->assertDatabaseHas('entity_tombstones', [
            'site_id' => $this->site->id,
            'entity_id' => $product->id,
            'type' => Entity::TYPE_PRODUCT,
            'slug' => 'p-purged',
            'name' => 'Entity p-purged',
        ]);

        $tombstone = $this->resolver->find($this->site->id, $product->id);
        $this->assertNotNull($tombstone, 'purge 后 Resolver 必须可实时查到墓碑');
        $this->assertNotNull($tombstone->purged_at);
        $this->assertSame($this->admin->id, $tombstone->purged_by);

        // 当前站点上下文查询同口径
        SiteContext::setSite($this->site);
        $this->assertSame(
            $tombstone->id,
            $this->resolver->findForCurrentSite($product->id)->id
        );
    }

    public function test_known_deleted_vs_unknown_id_distinction(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-known');
        $this->purge($product);

        // 已 purge → 本站有墓碑 → KNOWN-DELETED
        $this->assertSame(
            TombstoneResolver::KNOWN_DELETED,
            $this->resolver->classifyMissing($this->site->id, $product->id)
        );

        // 从未登记的 id → UNKNOWN-ID（失败路径：查无墓碑）
        $this->assertNull($this->resolver->find($this->site->id, 999999));
        $this->assertSame(
            TombstoneResolver::UNKNOWN_ID,
            $this->resolver->classifyMissing($this->site->id, 999999)
        );
    }

    public function test_tombstone_is_site_isolated(): void
    {
        $siteB = Site::create([
            'name' => 'Tombstone B', 'slug' => 'tombstone-b',
            'domain' => 'tombstone-b.test', 'status' => 'active', 'is_default' => false,
        ]);
        $productA = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-a');
        $this->purge($productA);

        // Site B 用同一 entity id 查询 → 无墓碑（绝不跨站命中）
        $this->assertNull($this->resolver->find($siteB->id, $productA->id));
        $this->assertSame(
            TombstoneResolver::UNKNOWN_ID,
            $this->resolver->classifyMissing($siteB->id, $productA->id)
        );
    }

    public function test_tombstone_recording_is_idempotent(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-idem');

        $this->resolver->record($product);
        $this->resolver->record($product);

        $this->assertSame(1, EntityTombstone::withoutSiteScope()
            ->where('site_id', $this->site->id)
            ->where('entity_id', $product->id)->count());
    }

    public function test_purge_with_translations_writes_single_tombstone(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-multi');
        $product->createTranslation('en', ['name' => 'P Multi EN', 'slug' => 'p-multi-en']);

        $this->purge($product);

        // 整组翻译 purge 只写一条墓碑（锚点为默认语言 id）
        $this->assertSame(1, EntityTombstone::withoutSiteScope()
            ->where('site_id', $this->site->id)->count());
        $this->assertNotNull($this->resolver->find($this->site->id, $product->id));
    }

    public function test_tombstone_does_not_revive_entity_or_enter_pks(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'p-ghost');
        $this->purge($product);

        // 实体行（含翻译）彻底不存在，墓碑不复活
        $this->assertSame(0, Entity::withTrashed()->withoutSiteScope()
            ->where('id', $product->id)->count());

        // 不进入 GEO 图谱：无实体节点
        $graph = $this->get('https://tombstone.test/geo.json')->json();
        $this->assertNotContains('p-ghost', array_column($graph['entities'], 'slug'));
        // 无任何边指向该已清除实体
        $this->assertNull(collect($graph['relations'])->first(
            fn ($r) => str_contains((string) $r['to'], 'entity/product/p-ghost')
        ));
    }
}
