<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 17B：实体（Entity）后台管理 + 产品双轨收敛回归。
 *
 * 覆盖：
 * - 实体管理访客跳登录；普通管理员（非超管）可管实体（与站点管理的超管专属相区别）；
 * - 六类型 Tab / 非法类型拒绝；name / slug 校验；slug 站内按类型唯一（异类型、异站可同名）；
 * - 后台经「实体与图谱」生产 Organization / Product / Service 后，Catalog 即时投影：
 *   空站 /products/ 平铺 200、核心产品详情 200、非核心产品详情 404、服务 /solutions/ 200；
 * - sitemap / llms / geo 只收本站可渲染资源，且严格多站隔离；
 * - 草稿 → 发布 → 下架驱动前台 404 / 200；跨站编辑实体 404；
 * - Content 侧「产品」类型入口已关闭（路由 404、校验拒绝、历史数据迁移归并为 article）；
 * - 「载入示例实体」幂等播种；删除实体级联清除关系。
 */
class AdminEntityCrudTest extends TestCase
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
            'email' => 'plain@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    private function makeSiteB(): Site
    {
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    private function switchTo(User $user, Site $site): void
    {
        $this->actingAs($user)->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function entityPayload(string $type, string $slug, string $name, array $overrides = []): array
    {
        return array_merge([
            'type' => $type,
            'name' => $name,
            'slug' => $slug,
            'status' => 'published',
            'summary' => null,
            'description' => null,
            'sort_order' => 0,
            'meta_core' => '1',
        ], $overrides);
    }

    private function entityOnSite(Site $site, string $type, string $slug): Entity
    {
        return Entity::withoutSiteScope()
            ->where('site_id', $site->id)->where('type', $type)->where('slug', $slug)
            ->firstOrFail();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.entities.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.entities.create', 'product'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.entities.store'), [])->assertRedirect(route('admin.login'));
    }

    public function test_plain_admin_can_manage_entities_but_not_sites(): void
    {
        // 实体管理对普通站点管理员开放（主组，仅需 admin.auth + admin.site）
        $this->actingAs($this->plain)->get(route('admin.entities.index'))->assertOk();
        $this->actingAs($this->plain)->get(route('admin.entities.create', 'service'))->assertOk();

        $this->actingAs($this->plain)->post(route('admin.entities.store'),
            $this->entityPayload('service', 'plain-service', 'Plain Service')
        )->assertRedirect();
        $this->assertDatabaseHas('entities', [
            'site_id' => $this->default->id, 'type' => 'service', 'slug' => 'plain-service',
        ]);

        // 站点管理仍是超管专属
        $this->actingAs($this->plain)->get(route('admin.sites.index'))->assertForbidden();
    }

    public function test_type_tabs_render_and_unknown_type_is_rejected(): void
    {
        foreach (['all', 'organization', 'product', 'service', 'person', 'location', 'topic'] as $tab) {
            $this->actingAs($this->super)->get(route('admin.entities.index', $tab))->assertOk();
        }
        // 非法实体类型禁止创建
        $this->actingAs($this->super)->get(route('admin.entities.create', 'brand'))->assertNotFound();
        // 非法 tab 回退为 all，不报错
        $this->actingAs($this->super)->get(route('admin.entities.index', 'not-a-type'))->assertOk();
    }

    public function test_store_validates_name_and_slug(): void
    {
        $base = $this->entityPayload('product', '', '');
        $this->actingAs($this->super)->post(route('admin.entities.store'), $base)
            ->assertSessionHasErrors(['name', 'slug']);

        // slug 仅允许小写字母 / 数字 / 连字符
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'Bad Slug', 'Bad')
        )->assertSessionHasErrors('slug');
        $this->assertDatabaseMissing('entities', ['slug' => 'Bad Slug']);
    }

    public function test_slug_unique_per_site_and_type_but_reusable_elsewhere(): void
    {
        // 同站同类型 slug 唯一
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'dup-slug', 'First')
        )->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'dup-slug', 'Second')
        )->assertSessionHasErrors('slug');

        // 同站不同类型可同名 slug
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('service', 'dup-slug', 'Service Same Slug')
        )->assertRedirect();

        // 不同站点可同名 slug
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'dup-slug', 'Other Site Product')
        )->assertRedirect();
        $this->assertDatabaseHas('entities', [
            'site_id' => $b->id, 'type' => 'product', 'slug' => 'dup-slug',
        ]);
    }

    public function test_admin_created_org_products_and_service_render_on_frontend_flat(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);

        // 组织（承载公司信息，是目录页可渲染的前提）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('organization', 'example-mfg', 'Example Manufacturing', [
                'org_brand' => 'ExampleBrand',
                'org_industry' => 'Industrial materials',
            ])
        )->assertRedirect();

        // 核心产品（有独立详情页）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'industrial-coating', 'Industrial Coating', [
                'summary' => 'High performance industrial coating.',
                'meta_core' => '1',
            ])
        )->assertRedirect();

        // 非核心产品（仅列表锚点，无独立详情页）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'ancillary-material', 'Ancillary Material', [
                'meta_core' => '0',
            ])
        )->assertRedirect();

        // 第三款核心产品（凑足 Organization + 3 Product）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'functional-additive', 'Functional Additive', [
                'meta_core' => '1',
            ])
        )->assertRedirect();

        // 服务（场景）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('service', 'oem-service', 'OEM Manufacturing')
        )->assertRedirect();

        // 第二个服务（技术支持）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('service', 'technical-support', 'Technical Support')
        )->assertRedirect();

        // 地点（Location 实体，带经纬度）
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('location', 'singapore-plant', 'Singapore Plant', [
                'loc_address'   => '1 Example Road',
                'loc_latitude'  => '1.3521',
                'loc_longitude' => '103.8198',
            ])
        )->assertRedirect();

        // 无线条时产品总览平铺、中性标题，三款产品都在列表
        $listing = $this->get('https://b.test/products/')->assertOk();
        $listing->assertSee('<h1 class="ph-h">产品中心</h1>', false);
        $listing->assertSee('Industrial Coating')->assertSee('Ancillary Material')
            ->assertSee('Functional Additive');

        // 核心产品详情 200，导语回退到摘要
        $detail = $this->get('https://b.test/products/industrial-coating')->assertOk();
        $detail->assertSee('Industrial Coating')->assertSee('High performance industrial coating.');
        $this->get('https://b.test/products/functional-additive')->assertOk()
            ->assertSee('Functional Additive');

        // 非核心产品无独立详情
        $this->get('https://b.test/products/ancillary-material')->assertNotFound();

        // 两个服务详情均 200
        $this->get('https://b.test/solutions/oem-service/')->assertOk()
            ->assertSee('OEM Manufacturing');
        $this->get('https://b.test/solutions/technical-support/')->assertOk()
            ->assertSee('Technical Support');

        // 地点正确落库（本站），并进入 GEO 图谱输出
        $location = $this->entityOnSite($b, 'location', 'singapore-plant');
        $this->assertSame('Singapore Plant', $location->name);
        $geo = $this->get('https://b.test/geo.json')->assertOk();
        $geo->assertSee('Singapore Plant');
        $graph = json_decode($geo->content(), true);
        $this->assertContains('entity/location/singapore-plant', array_column($graph['entities'] ?? [], 'id'));
    }

    public function test_feeds_only_contain_renderable_core_resources_and_are_site_isolated(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('organization', 'example-mfg', 'Example Manufacturing'));
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'industrial-coating', 'Industrial Coating'));
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'ancillary-material', 'Ancillary Material', ['meta_core' => '0']));

        // B 站 feed 收核心产品，不收非核心产品
        $this->get('https://b.test/sitemap.xml')->assertOk()
            ->assertSee('/products/industrial-coating')
            ->assertDontSee('ancillary-material');
        $this->get('https://b.test/llms.txt')->assertOk()
            ->assertSee('industrial-coating')
            ->assertDontSee('ancillary-material');
        $this->get('https://b.test/geo.json')->assertOk()->assertSee('industrial-coating');

        // 默认站（演示数据）不得看到 B 站产品
        $this->get('http://localhost/products/')->assertOk()
            ->assertDontSee('industrial-coating');
    }

    public function test_draft_publish_unpublish_drives_frontend_availability(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('organization', 'example-mfg', 'Example Manufacturing'));
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'draft-good', 'Draft Good', ['status' => 'draft'])
        )->assertRedirect();

        // 草稿不可见
        $this->get('https://b.test/products/draft-good')->assertNotFound();

        $product = $this->entityOnSite($b, 'product', 'draft-good');
        $this->actingAs($this->super)->post(route('admin.entities.publish', $product))->assertRedirect();
        $this->get('https://b.test/products/draft-good')->assertOk();

        $this->actingAs($this->super)->post(route('admin.entities.unpublish', $product))->assertRedirect();
        $this->get('https://b.test/products/draft-good')->assertNotFound();
    }

    public function test_cross_site_entity_edit_is_forbidden(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.entities.store'),
            $this->entityPayload('product', 'b-only', 'B Only'));
        $bEntity = $this->entityOnSite($b, 'product', 'b-only');

        // 切回默认站后，路由模型绑定经 SiteScope 找不到 B 站实体 → 404
        $this->switchTo($this->super, $this->default);
        $this->actingAs($this->super)->get(route('admin.entities.edit', $bEntity))->assertNotFound();
        $this->actingAs($this->super)->put(route('admin.entities.update', $bEntity),
            $this->entityPayload('product', 'b-only', 'Hijacked')
        )->assertNotFound();
    }

    public function test_content_product_entry_is_closed(): void
    {
        // 内容侧不再提供「产品」类型创建入口
        $this->actingAs($this->super)->get(route('admin.contents.create', 'product'))->assertNotFound();

        $this->actingAs($this->super)->post(route('admin.contents.store'), [
            'title' => 'Illegal Product Content',
            'slug' => 'illegal-product-content',
            'type' => 'product',
            'status' => 'draft',
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('contents', ['type' => 'product']);
    }

    public function test_retire_migration_normalizes_legacy_product_content_to_article(): void
    {
        SiteContext::withSite($this->default, function () {
            \DB::table('contents')->insert([
                'site_id' => $this->default->id,
                'title' => 'Legacy',
                'slug' => 'legacy-product-content',
                'type' => 'product',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $migration = require database_path('migrations/2026_09_21_000010_retire_content_product_type.php');
        $migration->up();

        $this->assertSame('article', \DB::table('contents')
            ->where('slug', 'legacy-product-content')->value('type'));
    }

    public function test_seed_examples_populates_empty_site_and_is_idempotent(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);

        $this->actingAs($this->super)->post(route('admin.entities.seed'))->assertRedirect();

        $this->assertDatabaseHas('entities', [
            'site_id' => $b->id, 'type' => 'organization', 'slug' => 'example-organization',
        ]);
        $this->get('https://b.test/products/')->assertOk();

        $countAfterFirst = Entity::withoutSiteScope()->where('site_id', $b->id)->count();
        // 再次执行幂等，不重复创建
        $this->actingAs($this->super)->post(route('admin.entities.seed'))->assertRedirect();
        $this->assertSame($countAfterFirst, Entity::withoutSiteScope()->where('site_id', $b->id)->count());
    }

    public function test_delete_entity_cascades_its_relations(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.entities.seed'))->assertRedirect();

        // 示例种子中核心产品同时是 produces / uses / related_to 的端点。
        // 注意：不得在 HTTP 请求外直接调用 Catalog（会在 default 上下文构建静态 memo），
        // 这里用不走 SiteContext 的查询取 B 站核心产品。
        $core = Entity::withoutSiteScope()
            ->where('site_id', $b->id)->where('type', 'product')->get()
            ->first(fn ($e) => ($e->metadata['core'] ?? false) === true);
        $this->assertNotNull($core);
        $product = $this->entityOnSite($b, 'product', $core->slug);

        $this->actingAs($this->super)->get(route('admin.entities.edit', $product))->assertOk();
        $this->actingAs($this->super)->delete(route('admin.entities.destroy', $product))->assertRedirect();

        $this->assertDatabaseMissing('entities', ['id' => $product->id]);
        $this->assertSame(0, \DB::table('entity_relations')
            ->where(fn ($q) => $q->where('from_entity_id', $product->id)->orWhere('to_entity_id', $product->id))
            ->count());
    }
}
