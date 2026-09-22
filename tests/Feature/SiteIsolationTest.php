<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Content;
use App\Models\Category;
use App\Models\Group;
use App\Models\Fact;
use App\Models\Setting;
use App\Models\Menu;
use App\Models\Redirect;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.4-G 补证：Site A/B 完整隔离测试
 *
 * 验证多站点运行时隔离：
 * - SELECT 隔离
 * - CREATE 隔离
 * - UPDATE 隔离
 * - DELETE 隔离
 * - Relationship 隔离
 * - 显式 site_id 查询安全边界
 */
class SiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
    }

    protected function tearDown(): void
    {
        SiteContext::clear();
        parent::tearDown();
    }

    /** @test */
    public function site_a_cannot_see_site_b_content(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Content::create([
                'type' => 'article', 'title' => 'A Content', 'slug' => 'a-content',
                'status' => 'published', 'site_id' => $siteA->id,
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Content::create([
                'type' => 'article', 'title' => 'B Content', 'slug' => 'b-content',
                'status' => 'published', 'site_id' => $siteB->id,
            ]);
        });

        // Site A 只能看到 A 的内容
        SiteContext::withSite($siteA, function () {
            $contents = Content::all();
            $this->assertCount(1, $contents);
            $this->assertEquals('A Content', $contents->first()->title);
        });

        // Site B 只能看到 B 的内容
        SiteContext::withSite($siteB, function () {
            $contents = Content::all();
            $this->assertCount(1, $contents);
            $this->assertEquals('B Content', $contents->first()->title);
        });
    }

    /** @test */
    public function create_in_site_a_context_auto_sets_site_id(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);

        SiteContext::withSite($siteA, function () {
            // 不显式设置 site_id
            $content = Content::create([
                'type' => 'article', 'title' => 'Auto', 'slug' => 'auto',
                'status' => 'published',
            ]);

            $this->assertEquals(SiteContext::currentSiteId(), $content->site_id);
        });
    }

    /** @test */
    public function site_a_cannot_update_site_b_content(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        $contentB = SiteContext::withSite($siteB, function () use ($siteB) {
            return Content::create([
                'type' => 'article', 'title' => 'B Original', 'slug' => 'b-original',
                'status' => 'published', 'site_id' => $siteB->id,
            ]);
        });

        // Site A 尝试更新 B 的内容
        SiteContext::withSite($siteA, function () use ($contentB) {
            // Global Scope 会过滤掉 B 的内容
            $found = Content::find($contentB->id);
            $this->assertNull($found);

            // 直接更新也会失败（因为 scope 过滤了）
            $updated = Content::where('id', $contentB->id)->update(['title' => 'Hacked']);
            $this->assertEquals(0, $updated);
        });

        // 验证 B 的内容没有被修改
        $contentB->refresh();
        $this->assertEquals('B Original', $contentB->title);
    }

    /** @test */
    public function site_a_cannot_delete_site_b_content(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        $contentB = SiteContext::withSite($siteB, function () use ($siteB) {
            return Content::create([
                'type' => 'article', 'title' => 'B Delete', 'slug' => 'b-delete',
                'status' => 'published', 'site_id' => $siteB->id,
            ]);
        });

        // Site A 尝试删除 B 的内容
        SiteContext::withSite($siteA, function () use ($contentB) {
            $found = Content::find($contentB->id);
            $this->assertNull($found);

            $deleted = Content::where('id', $contentB->id)->delete();
            $this->assertEquals(0, $deleted);
        });

        // 验证 B 的内容仍然存在
        $this->assertDatabaseHas('contents', [
            'id' => $contentB->id,
            'title' => 'B Delete',
        ]);
    }

    /** @test */
    public function category_contents_are_site_isolated(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        $catA = SiteContext::withSite($siteA, function () use ($siteA) {
            $cat = Category::create([
                'name' => 'Cat A', 'slug' => 'cat-a', 'type' => 'list',
                'site_id' => $siteA->id,
            ]);
            Content::create([
                'type' => 'article', 'title' => 'A in Cat', 'slug' => 'a-in-cat',
                'status' => 'published', 'category_id' => $cat->id, 'site_id' => $siteA->id,
            ]);
            return $cat;
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            $catB = Category::create([
                'name' => 'Cat B', 'slug' => 'cat-b', 'type' => 'list',
                'site_id' => $siteB->id,
            ]);
            Content::create([
                'type' => 'article', 'title' => 'B in Cat', 'slug' => 'b-in-cat',
                'status' => 'published', 'category_id' => $catB->id, 'site_id' => $siteB->id,
            ]);
        });

        // Site A 的 Category 只能看到 A 的内容
        SiteContext::withSite($siteA, function () use ($catA) {
            $contents = $catA->contents;
            $this->assertCount(1, $contents);
            $this->assertEquals('A in Cat', $contents->first()->title);
        });
    }

    /** @test */
    public function explicit_site_id_query_is_filtered_by_global_scope(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::withSite($siteB, function () use ($siteB) {
            Content::create([
                'type' => 'article', 'title' => 'B', 'slug' => 'b',
                'status' => 'published', 'site_id' => $siteB->id,
            ]);
        });

        // Site A 尝试显式查询 B 的数据
        SiteContext::withSite($siteA, function () use ($siteB) {
            // Global Scope 会过滤，即使显式指定了 site_id
            $found = Content::where('site_id', $siteB->id)->get();
            $this->assertCount(0, $found);

            // 必须使用 withoutSiteScope 才能看到跨站数据
            $all = Content::withoutSiteScope()->where('site_id', $siteB->id)->get();
            $this->assertCount(1, $all);
        });
    }

    /** @test */
    public function settings_are_site_isolated(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        // TD-12 后建站即由 Site.name 单向镜像 settings.site_name；这里把各站 site_name
        // 更新为不同值（updateOrCreate 命中镜像行），再验证按站读取互不串扰。
        SiteContext::withSite($siteA, function () use ($siteA) {
            Setting::withoutSiteScope()->updateOrCreate(
                ['site_id' => $siteA->id, 'key' => 'site_name'],
                ['value' => 'Site A', 'group' => 'general', 'type' => 'text']
            );
            Setting::flush();
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Setting::withoutSiteScope()->updateOrCreate(
                ['site_id' => $siteB->id, 'key' => 'site_name'],
                ['value' => 'Site B', 'group' => 'general', 'type' => 'text']
            );
            Setting::flush();
        });

        // Site A 只能看到 A 的设置
        SiteContext::withSite($siteA, function () {
            $setting = Setting::where('key', 'site_name')->first();
            $this->assertEquals('Site A', $setting->value);
        });

        // Site B 只能看到 B 的设置
        SiteContext::withSite($siteB, function () {
            $setting = Setting::where('key', 'site_name')->first();
            $this->assertEquals('Site B', $setting->value);
        });
    }

    /** @test */
    public function menus_are_site_isolated(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Menu::create([
                'key' => 'main', 'position' => 'header', 'label' => 'Menu A',
                'url' => '/', 'sort' => 0, 'is_active' => true, 'site_id' => $siteA->id,
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Menu::create([
                'key' => 'main', 'position' => 'header', 'label' => 'Menu B',
                'url' => '/', 'sort' => 0, 'is_active' => true, 'site_id' => $siteB->id,
            ]);
        });

        // Site A 只能看到 A 的菜单
        SiteContext::withSite($siteA, function () {
            $menus = Menu::all();
            $this->assertCount(1, $menus);
        });

        // Site B 只能看到 B 的菜单
        SiteContext::withSite($siteB, function () {
            $menus = Menu::all();
            $this->assertCount(1, $menus);
        });
    }

    /** @test */
    public function redirects_are_site_isolated(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Redirect::create([
                'from_path' => '/old', 'to_path' => '/new', 'site_id' => $siteA->id,
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Redirect::create([
                'from_path' => '/old', 'to_path' => '/other', 'site_id' => $siteB->id,
            ]);
        });

        // Site A 只能看到 A 的重定向
        SiteContext::withSite($siteA, function () {
            $redirect = Redirect::where('from_path', '/old')->first();
            $this->assertEquals('/new', $redirect->to_path);
        });

        // Site B 只能看到 B 的重定向
        SiteContext::withSite($siteB, function () {
            $redirect = Redirect::where('from_path', '/old')->first();
            $this->assertEquals('/other', $redirect->to_path);
        });
    }

    /** @test */
    public function facts_are_site_isolated(): void
    {
        $siteA = Site::create(['name' => 'A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'B', 'slug' => 'site-b', 'status' => 'active']);

        SiteContext::withSite($siteA, function () use ($siteA) {
            Fact::create([
                'key' => 'founded', 'label' => 'Founded', 'value' => '2020',
                'is_public' => true, 'site_id' => $siteA->id,
            ]);
        });

        SiteContext::withSite($siteB, function () use ($siteB) {
            Fact::create([
                'key' => 'founded', 'label' => 'Founded', 'value' => '2024',
                'is_public' => true, 'site_id' => $siteB->id,
            ]);
        });

        // Site A 只能看到 A 的事实
        SiteContext::withSite($siteA, function () {
            $fact = Fact::where('key', 'founded')->first();
            $this->assertEquals('2020', $fact->value);
        });

        // Site B 只能看到 B 的事实
        SiteContext::withSite($siteB, function () {
            $fact = Fact::where('key', 'founded')->first();
            $this->assertEquals('2024', $fact->value);
        });
    }
}
