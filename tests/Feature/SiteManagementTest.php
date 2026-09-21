<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 17A：站点管理（Admin Control Plane）回归。
 *
 * 覆盖：
 * - 站点管理仅超级管理员（is_super_admin）可访问，访客跳登录、普通管理员 403；
 * - 站点 CRUD、域名规范化（去协议 / 端口 / 路径）、slug 与域名唯一、slug 编辑后不可改；
 * - 默认站点唯一（partial unique index）、默认站不可删除、有数据的站点删除保护；
 * - 顶部站点切换器（session admin_site_slug）让后台内容列表严格按当前管理站点隔离。
 */
class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected User $plain;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::firstOrFail();
        $this->assertTrue((bool) $this->super->is_super_admin);

        $this->plain = User::create([
            'name' => '普通站点管理员',
            'email' => 'plain@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);

        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.sites.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.sites.store'), [])->assertRedirect(route('admin.login'));
    }

    public function test_non_super_admin_is_forbidden_everywhere(): void
    {
        $this->actingAs($this->plain)->get(route('admin.sites.index'))->assertForbidden();
        $this->actingAs($this->plain)->get(route('admin.sites.create'))->assertForbidden();
        $this->actingAs($this->plain)->post(route('admin.sites.store'), [
            'name' => 'X', 'slug' => 'x', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($this->plain)
            ->post(route('admin.sites.switch'), ['site_id' => $this->default->id])
            ->assertForbidden();
    }

    public function test_super_admin_can_list_sites_and_open_create(): void
    {
        $this->actingAs($this->super)->get(route('admin.sites.index'))
            ->assertOk()->assertSee($this->default->name);

        $this->actingAs($this->super)->get(route('admin.sites.create'))->assertOk();
    }

    public function test_store_creates_site_and_normalizes_domain(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Site B',
            'slug' => 'site-b',
            'domain' => 'https://www.b.test:8443/some/path?x=1',
            'status' => 'active',
            'description' => 'Second site',
        ])->assertRedirect(route('admin.sites.index'));

        $site = Site::where('slug', 'site-b')->firstOrFail();
        $this->assertSame('www.b.test', $site->domain);
        $this->assertSame('active', $this->default->fresh()->status);
    }

    public function test_store_rejects_invalid_slug(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Bad',
            'slug' => 'Site B',   // 含空格与大写
            'status' => 'active',
        ])->assertSessionHasErrors('slug');

        $this->assertNull(Site::where('slug', 'site b')->first());
    }

    public function test_store_rejects_duplicate_slug_and_duplicate_domain(): void
    {
        Site::create(['name' => 'B', 'slug' => 'site-b', 'domain' => 'b.test', 'status' => 'active']);

        // 重复 slug
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Dup slug', 'slug' => 'site-b', 'status' => 'active',
        ])->assertSessionHasErrors('slug');

        // 重复域名
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Dup domain', 'slug' => 'site-c', 'domain' => 'b.test', 'status' => 'active',
        ])->assertSessionHasErrors('domain');
    }

    public function test_store_can_promote_new_default_keeping_single_default(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'New Default', 'slug' => 'new-default', 'status' => 'active',
            'is_default' => '1',
        ])->assertRedirect();

        $new = Site::where('slug', 'new-default')->firstOrFail();
        $this->assertTrue((bool) $new->is_default);
        $this->assertFalse((bool) $this->default->fresh()->is_default);
        $this->assertSame(1, Site::where('is_default', true)->count());
    }

    public function test_update_changes_fields_but_ignores_submitted_slug(): void
    {
        $site = Site::create(['name' => 'B', 'slug' => 'site-b', 'domain' => 'b.test', 'status' => 'active']);

        $this->actingAs($this->super)->put(route('admin.sites.update', $site), [
            'name' => 'Site B Renamed',
            'slug' => 'hacked-slug',   // 编辑后 slug 必须被忽略
            'domain' => 'https://c.test/',
            'status' => 'maintenance',
        ])->assertRedirect(route('admin.sites.index'));

        $site->refresh();
        $this->assertSame('Site B Renamed', $site->name);
        $this->assertSame('site-b', $site->slug, 'slug must remain immutable after creation');
        $this->assertSame('c.test', $site->domain);
        $this->assertSame('maintenance', $site->status);
    }

    public function test_make_default_promotes_site_and_demotes_previous(): void
    {
        $site = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active', 'is_default' => false]);

        $this->actingAs($this->super)->post(route('admin.sites.default', $site))->assertRedirect();

        $this->assertTrue((bool) $site->fresh()->is_default);
        $this->assertFalse((bool) $this->default->fresh()->is_default);
        $this->assertSame(1, Site::where('is_default', true)->count());
    }

    public function test_default_site_cannot_be_deleted(): void
    {
        $this->actingAs($this->super)->delete(route('admin.sites.destroy', $this->default))
            ->assertRedirect();

        $this->assertDatabaseHas('sites', ['id' => $this->default->id]);
    }

    public function test_site_with_data_is_protected_but_empty_site_can_be_deleted(): void
    {
        $withData = Site::create(['name' => 'With Data', 'slug' => 'with-data', 'status' => 'active']);
        $empty = Site::create(['name' => 'Empty', 'slug' => 'empty', 'status' => 'active']);

        SiteContext::withSite($withData, function () {
            Content::create([
                'title' => 'Belongs to with-data site',
                'slug' => 'with-data-article',
                'type' => 'article',
                'status' => 'published',
            ]);
        });

        // 有内容的站点禁止删除
        $this->actingAs($this->super)->delete(route('admin.sites.destroy', $withData))->assertRedirect();
        $this->assertDatabaseHas('sites', ['id' => $withData->id]);

        // 空站点可删除
        $this->actingAs($this->super)->delete(route('admin.sites.destroy', $empty))->assertRedirect();
        $this->assertDatabaseMissing('sites', ['id' => $empty->id]);
    }

    public function test_switching_admin_site_isolates_content_lists(): void
    {
        $siteB = Site::create(['name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test', 'status' => 'active']);

        SiteContext::withSite($this->default, function () {
            Content::create([
                'title' => 'AAA Default Only Article',
                'slug' => 'aaa-default-only',
                'type' => 'article',
                'status' => 'published',
            ]);
        });
        SiteContext::withSite($siteB, function () {
            Content::create([
                'title' => 'BBB Beta Only Article',
                'slug' => 'bbb-beta-only',
                'type' => 'article',
                'status' => 'published',
            ]);
        });

        // 切到 B：后台内容列表只见 B，不见默认站内容
        $this->actingAs($this->super)->post(route('admin.sites.switch'), ['site_id' => $siteB->id])
            ->assertRedirect();
        $this->actingAs($this->super)->get(route('admin.contents.index', 'article'))
            ->assertOk()
            ->assertSee('BBB Beta Only Article')
            ->assertDontSee('AAA Default Only Article');

        // 切回默认站：只见 A，不见 B
        $this->actingAs($this->super)->post(route('admin.sites.switch'), ['site_id' => $this->default->id])
            ->assertRedirect();
        $this->actingAs($this->super)->get(route('admin.contents.index', 'article'))
            ->assertOk()
            ->assertSee('AAA Default Only Article')
            ->assertDontSee('BBB Beta Only Article');
    }
}
