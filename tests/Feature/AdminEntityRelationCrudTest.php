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
 * P-STEP 17C：实体关系（EntityRelation）后台管理 + 关系端到端闭环回归。
 *
 * 契约（与 EntityRelationDeepTest 引擎层测试互补，本类全部走后台 HTTP / 前台 HTTP）：
 * - 关系管理访客跳登录；普通站点管理员可管（与超管专属的站点管理相区别）；
 * - 五型有向边 produces/offers/uses/located_in/related_to；源 / 目标下拉只列本站实体；
 * - 同四元组（site+from+to+type）重复拒绝且为友好表单错误（非数据库异常白屏）；
 * - 反向关系允许、自关系允许；跨站关系在表单校验层即被拦截，模型守卫兜底；
 * - metadata 仅接受合法 JSON 对象；删除实体级联清除其关系；
 * - 关系闭环：两端均发布时该边进入 /geo.json，任一端草稿 / 归档则不输出；
 * - 关系严格多站隔离；关系本身不产生任何 sitemap / llms 公开 URL（无关系伪 URL）。
 */
class AdminEntityRelationCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected User $plain;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->plain = User::create([
            'name' => '普通站点管理员',
            'email' => 'plain-rel@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        // 清空内置 Example 演示实体与其关系，使本类的关系计数 / firstOrFail 从空集开始
        // （admin 用户与 default 站点保留；每个测试经 RefreshDatabase 独立重建）。
        EntityRelation::withoutSiteScope()->delete();
        Entity::withoutSiteScope()->delete();
    }

    private function makeSiteB(): Site
    {
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b-rel', 'domain' => 'brel.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    private function switchTo(User $user, Site $site): void
    {
        $this->actingAs($user)->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    private function makeEntity(Site $site, string $type, string $slug, string $name, string $status = 'published'): Entity
    {
        return SiteContext::withSite($site, function () use ($site, $type, $slug, $name, $status) {
            return Entity::create([
                'site_id' => $site->id,
                'type' => $type,
                'slug' => $slug,
                'name' => $name,
                'summary' => $name,
                'status' => $status,
                'sort_order' => 0,
                'published_at' => $status === Entity::STATUS_PUBLISHED ? now() : null,
                'metadata' => ['core' => true],
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function relationPayload(Entity $from, Entity $to, string $type, array $overrides = []): array
    {
        return array_merge([
            'from_entity_id' => $from->id,
            'to_entity_id' => $to->id,
            'relation_type' => $type,
            'sort_order' => 0,
            'metadata_text' => '',
        ], $overrides);
    }

    private function relationCount(Site $site): int
    {
        return EntityRelation::withoutSiteScope()->where('site_id', $site->id)->count();
    }

    /**
     * @return array<string,mixed>
     */
    private function graph(string $host): array
    {
        $url = $host === 'localhost' ? 'http://localhost/geo.json' : "https://{$host}/geo.json";

        return json_decode($this->get($url)->assertOk()->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function edgeExists(array $graph, string $fromType, string $fromSlug, string $toType, string $toSlug, string $type): bool
    {
        foreach (($graph['relations'] ?? []) as $edge) {
            if (($edge['from'] ?? '') === "entity/{$fromType}/{$fromSlug}"
                && ($edge['to'] ?? '') === "entity/{$toType}/{$toSlug}"
                && ($edge['relation_type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.relations.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.relations.create'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.relations.store'), [])->assertRedirect(route('admin.login'));
    }

    public function test_plain_admin_can_manage_relations_but_not_sites(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'plain-org-rel', 'Plain Org Rel');
        $prod = $this->makeEntity($this->default, 'product', 'plain-prod-rel', 'Plain Prod Rel');

        $this->actingAs($this->plain)->get(route('admin.relations.index'))->assertOk();
        $this->actingAs($this->plain)->get(route('admin.relations.create'))->assertOk();
        $this->actingAs($this->plain)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))
            ->assertRedirect(route('admin.relations.index'));

        $this->assertDatabaseHas('entity_relations', [
            'site_id' => $this->default->id,
            'from_entity_id' => $org->id,
            'to_entity_id' => $prod->id,
            'relation_type' => 'produces',
        ]);

        // 站点管理仍是超管专属
        $this->actingAs($this->plain)->get(route('admin.sites.index'))->assertForbidden();
    }

    public function test_index_lists_relations_with_entity_names_and_type(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'list-org', 'List Org Co');
        $prod = $this->makeEntity($this->default, 'product', 'list-prod', 'List Prod Item');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))
            ->assertRedirect();

        $page = $this->actingAs($this->super)->get(route('admin.relations.index'))->assertOk();
        $page->assertSee('List Org Co')->assertSee('List Prod Item')->assertSee('produces');
    }

    public function test_create_form_options_only_list_current_site_entities(): void
    {
        $orgA = $this->makeEntity($this->default, 'organization', 'org-a-only', 'Org A Only Name');
        $b = $this->makeSiteB();
        $orgB = $this->makeEntity($b, 'organization', 'org-b-only', 'Org B Secret Name');

        // 默认站（A）后台的新建表单：含 A 实体、不含 B 实体（按唯一名称 / slug 断言）
        $form = $this->actingAs($this->super)->get(route('admin.relations.create'))->assertOk();
        $form->assertSee('Org A Only Name')->assertSee('org-a-only')
            ->assertDontSee('Org B Secret Name')->assertDontSee('org-b-only');
    }

    public function test_store_creates_uses_relation_on_current_site(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $service = $this->makeEntity($b, 'service', 'b-coating-service', 'B Coating Service');
        $product = $this->makeEntity($b, 'product', 'b-coated-product', 'B Coated Product');

        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($service, $product, 'uses', ['sort_order' => 2]))
            ->assertRedirect(route('admin.relations.index'));

        $this->assertDatabaseHas('entity_relations', [
            'site_id' => $b->id,
            'from_entity_id' => $service->id,
            'to_entity_id' => $product->id,
            'relation_type' => 'uses',
            'sort_order' => 2,
        ]);
        $this->assertSame(1, $this->relationCount($b));
    }

    public function test_store_validates_missing_fields_and_unknown_type(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'val-org', 'Val Org');
        $prod = $this->makeEntity($this->default, 'product', 'val-prod', 'Val Prod');

        // 全空：三个必填字段都报错
        $this->actingAs($this->super)->post(route('admin.relations.store'), [])
            ->assertSessionHasErrors(['from_entity_id', 'to_entity_id', 'relation_type']);

        // 非法关系类型
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'manufactures'))
            ->assertSessionHasErrors('relation_type');
        $this->assertSame(0, $this->relationCount($this->default));
    }

    public function test_duplicate_relation_rejected_with_friendly_error(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'dup-org', 'Dup Org');
        $prod = $this->makeEntity($this->default, 'product', 'dup-prod', 'Dup Prod');
        $payload = $this->relationPayload($org, $prod, 'produces');

        $this->actingAs($this->super)->post(route('admin.relations.store'), $payload)->assertRedirect();
        // 同四元组再次提交：回到表单且为字段级友好错误，而不是 500
        $this->actingAs($this->super)->post(route('admin.relations.store'), $payload)
            ->assertSessionHasErrors('to_entity_id');

        $this->assertSame(1, $this->relationCount($this->default));
    }

    public function test_reverse_relation_is_allowed(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'rev-org', 'Rev Org');
        $prod = $this->makeEntity($this->default, 'product', 'rev-prod', 'Rev Prod');

        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))
            ->assertRedirect();
        // 反向（product related_to organization）是不同四元组，允许
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($prod, $org, 'related_to'))
            ->assertRedirect();

        $this->assertSame(2, $this->relationCount($this->default));
    }

    public function test_self_relation_is_allowed(): void
    {
        $topic = $this->makeEntity($this->default, 'topic', 'self-topic', 'Self Topic');

        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($topic, $topic, 'related_to'))
            ->assertRedirect();

        $this->assertDatabaseHas('entity_relations', [
            'from_entity_id' => $topic->id,
            'to_entity_id' => $topic->id,
            'relation_type' => 'related_to',
        ]);
    }

    public function test_cross_site_relation_blocked_with_friendly_validation(): void
    {
        $orgA = $this->makeEntity($this->default, 'organization', 'cross-org-a', 'Cross Org A');
        $b = $this->makeSiteB();
        $prodB = $this->makeEntity($b, 'product', 'cross-prod-b', 'Cross Prod B');

        // 当前管理默认站（A），却把目标指向 B 站实体：exists 限定本站 → 字段错误，不落库
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($orgA, $prodB, 'produces'))
            ->assertSessionHasErrors('to_entity_id');

        $this->assertSame(0, EntityRelation::withoutSiteScope()
            ->where('from_entity_id', $orgA->id)->where('to_entity_id', $prodB->id)->count());
    }

    public function test_cross_site_relation_route_binding_returns_404(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $orgB = $this->makeEntity($b, 'organization', 'bind-org-b', 'Bind Org B');
        $prodB = $this->makeEntity($b, 'product', 'bind-prod-b', 'Bind Prod B');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($orgB, $prodB, 'produces'))
            ->assertRedirect();
        $relB = EntityRelation::withoutSiteScope()->where('site_id', $b->id)->firstOrFail();

        // 切回默认站后，按 id 访问 B 站关系应 404（BelongsToSite 全局作用域）
        $this->switchTo($this->super, $this->default);
        $this->actingAs($this->super)->get(route('admin.relations.edit', $relB))->assertNotFound();
    }

    public function test_invalid_metadata_json_rejected(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'meta-bad-org', 'Meta Bad Org');
        $prod = $this->makeEntity($this->default, 'product', 'meta-bad-prod', 'Meta Bad Prod');

        $this->actingAs($this->super)->post(route('admin.relations.store'),
            $this->relationPayload($org, $prod, 'produces', ['metadata_text' => '{not json'])
        )->assertSessionHasErrors('metadata_text');

        $this->assertSame(0, $this->relationCount($this->default));
    }

    public function test_valid_metadata_json_persisted_as_array(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'meta-ok-org', 'Meta Ok Org');
        $prod = $this->makeEntity($this->default, 'product', 'meta-ok-prod', 'Meta Ok Prod');

        $this->actingAs($this->super)->post(route('admin.relations.store'),
            $this->relationPayload($org, $prod, 'produces', ['metadata_text' => '{"strength":"primary"}'])
        )->assertRedirect();

        $rel = EntityRelation::withoutSiteScope()->where('site_id', $this->default->id)->firstOrFail();
        $this->assertSame(['strength' => 'primary'], $rel->metadata);
    }

    public function test_update_changes_type_sort_and_metadata(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'upd-org', 'Upd Org');
        $prod = $this->makeEntity($this->default, 'product', 'upd-prod', 'Upd Prod');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'related_to'))
            ->assertRedirect();
        $rel = EntityRelation::withoutSiteScope()->where('site_id', $this->default->id)->firstOrFail();

        $this->actingAs($this->super)->put(route('admin.relations.update', $rel),
            $this->relationPayload($org, $prod, 'produces', ['sort_order' => 3, 'metadata_text' => '{"k":"v"}'])
        )->assertRedirect(route('admin.relations.index'));

        $fresh = $rel->fresh();
        $this->assertSame('produces', $fresh->relation_type);
        $this->assertSame(3, (int) $fresh->sort_order);
        $this->assertSame(['k' => 'v'], $fresh->metadata);
    }

    public function test_destroy_removes_relation_but_keeps_entities(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'del-org', 'Del Org');
        $prod = $this->makeEntity($this->default, 'product', 'del-prod', 'Del Prod');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))
            ->assertRedirect();
        $rel = EntityRelation::withoutSiteScope()->where('site_id', $this->default->id)->firstOrFail();

        $this->actingAs($this->super)->delete(route('admin.relations.destroy', $rel))
            ->assertRedirect(route('admin.relations.index'));

        $this->assertNull(EntityRelation::find($rel->id));
        $this->assertNotNull(Entity::find($org->id));
        $this->assertNotNull(Entity::find($prod->id));
    }

    public function test_deleting_entity_cascades_its_relations_via_http(): void
    {
        $org = $this->makeEntity($this->default, 'organization', 'cascade-org', 'Cascade Org');
        $p1 = $this->makeEntity($this->default, 'product', 'cascade-p1', 'Cascade P1');
        $p2 = $this->makeEntity($this->default, 'product', 'cascade-p2', 'Cascade P2');
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($org, $p1, 'produces'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($org, $p2, 'produces'))->assertRedirect();
        $this->assertSame(2, $this->relationCount($this->default));

        // 经后台删除组织实体，其作为源端的两条关系由外键级联清除
        $this->actingAs($this->super)->delete(route('admin.entities.destroy', $org))->assertRedirect();
        $this->assertSame(0, EntityRelation::withoutSiteScope()
            ->where(fn ($w) => $w->where('from_entity_id', $org->id)->orWhere('to_entity_id', $org->id))->count());
    }

    public function test_relation_appears_in_geo_when_both_ends_published(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $service = $this->makeEntity($b, 'service', 'geo-svc', 'Geo Service');
        $product = $this->makeEntity($b, 'product', 'geo-prod', 'Geo Product');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($service, $product, 'uses'))
            ->assertRedirect();

        $graph = $this->graph('brel.test');
        $this->assertTrue(
            $this->edgeExists($graph, 'service', 'geo-svc', 'product', 'geo-prod', 'uses'),
            '两端均发布时 uses 关系必须出现在 /geo.json'
        );
    }

    public function test_relation_hidden_from_geo_when_one_end_is_draft(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $service = $this->makeEntity($b, 'service', 'draft-svc', 'Draft Service');
        $product = $this->makeEntity($b, 'product', 'draft-prod', 'Draft Product');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($service, $product, 'uses'))
            ->assertRedirect();

        // 产品下架为草稿：该边不应输出
        $product->update(['status' => Entity::STATUS_DRAFT, 'published_at' => null]);
        $graph = $this->graph('brel.test');
        $this->assertFalse($this->edgeExists($graph, 'service', 'draft-svc', 'product', 'draft-prod', 'uses'));

        // 重新发布后恢复
        $product->update(['status' => Entity::STATUS_PUBLISHED, 'published_at' => now()]);
        $graph2 = $this->graph('brel.test');
        $this->assertTrue($this->edgeExists($graph2, 'service', 'draft-svc', 'product', 'draft-prod', 'uses'));
    }

    public function test_relations_are_strictly_site_isolated_in_geo(): void
    {
        // A（默认站）建 orgA → productA
        $orgA = $this->makeEntity($this->default, 'organization', 'iso-org-a', 'Iso Org A');
        $prodA = $this->makeEntity($this->default, 'product', 'iso-prod-a', 'Iso Prod A');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($orgA, $prodA, 'produces'))
            ->assertRedirect();

        // B 站建 orgB → productB
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $orgB = $this->makeEntity($b, 'organization', 'iso-org-b', 'Iso Org B');
        $prodB = $this->makeEntity($b, 'product', 'iso-prod-b', 'Iso Prod B');
        $this->actingAs($this->super)
            ->post(route('admin.relations.store'), $this->relationPayload($orgB, $prodB, 'produces'))
            ->assertRedirect();

        $graphB = $this->graph('brel.test');
        $this->assertTrue($this->edgeExists($graphB, 'organization', 'iso-org-b', 'product', 'iso-prod-b', 'produces'));
        $this->assertFalse($this->edgeExists($graphB, 'organization', 'iso-org-a', 'product', 'iso-prod-a', 'produces'));

        $graphA = $this->graph('localhost');
        $this->assertTrue($this->edgeExists($graphA, 'organization', 'iso-org-a', 'product', 'iso-prod-a', 'produces'));
        $this->assertFalse($this->edgeExists($graphA, 'organization', 'iso-org-b', 'product', 'iso-prod-b', 'produces'));
    }

    public function test_relations_do_not_emit_pseudo_public_urls(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $org = $this->makeEntity($b, 'organization', 'url-org', 'URL Org');
        $prod = $this->makeEntity($b, 'product', 'url-prod', 'URL Prod');
        $svc = $this->makeEntity($b, 'service', 'url-svc', 'URL Svc');
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($svc, $prod, 'uses'))
            ->assertRedirect();

        // 关系本身不产生任何公开 URL：sitemap / llms 不得出现 entity/ 图谱 id、关系类型路径
        $sitemap = $this->get('https://brel.test/sitemap.xml')->assertOk()->content();
        $llms = $this->get('https://brel.test/llms.txt')->assertOk()->content();
        foreach ([$sitemap, $llms] as $payload) {
            $this->assertStringNotContainsString('entity/product', $payload);
            $this->assertStringNotContainsString('entity/service', $payload);
            $this->assertStringNotContainsString('/uses/', $payload);
            $this->assertStringNotContainsString('relation', $payload);
        }
    }

    public function test_relation_does_not_break_frontend_product_and_schema(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $org = $this->makeEntity($b, 'organization', 'fe-org', 'FE Org');
        $prod = $this->makeEntity($b, 'product', 'fe-prod', 'FE Prod');
        $svc = $this->makeEntity($b, 'service', 'fe-svc', 'FE Svc');
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($org, $prod, 'produces'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.relations.store'), $this->relationPayload($svc, $prod, 'uses'))->assertRedirect();

        // 建关系后核心产品前台详情仍 200 且正常渲染（关系不产生断裂的 Schema / 页面）
        $page = $this->get('https://brel.test/products/fe-prod')->assertOk();
        $page->assertSee('FE Prod');
    }
}
