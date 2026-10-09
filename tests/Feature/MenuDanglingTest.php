<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WP-6 Menu Dangling Detection：删除前引用保护 + 历史悬空 INVALID + 渲染降级。
 *
 * 覆盖：
 *  - 删除目标仍被 ACTIVE 菜单引用 → BLOCK（Page 硬删 / Content 软删）；
 *  - 未被引用（或仅被停用菜单引用）→ 可正常删除；
 *  - 历史悬空菜单：健康检查标记 INVALID；
 *  - INVALID 项渲染为非可点击占位（不输出伪有效链接）；
 *  - 有效内部 / 外部链接正常解析；
 *  - 全程不自动修改菜单（无副作用）。
 */
class MenuDanglingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'Menu Site', 'slug' => 'menu-site',
            'domain' => 'menu.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
    }

    private function makePage(string $slug): Page
    {
        return Page::create([
            'site_id' => $this->site->id, 'template' => 'landing',
            'slug' => $slug, 'title' => '页面 ' . $slug,
            'status' => Page::STATUS_PUBLISHED, 'locale' => 'zh-CN',
        ]);
    }

    private function makeMenu(string $url, string $label, bool $active = true): Menu
    {
        return Menu::create([
            'site_id' => $this->site->id, 'key' => null,
            'position' => 'main', 'url' => $url, 'label' => $label,
            'is_active' => $active, 'parent_id' => null, 'parent_key' => null,
        ]);
    }

    private function publishContent(string $slug): Content
    {
        $content = Content::create([
            'site_id' => $this->site->id, 'type' => 'article',
            'title' => '文章 ' . $slug, 'slug' => $slug,
            'summary' => '摘要。', 'body' => "## 正文\n\n内容。",
            'geo_conclusion' => '结论。', 'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二', 'source' => '来源二'],
            ],
            'owner' => 'Administrator', 'reviewed_at' => '2026-10-09',
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.contents.publish', $content))->assertRedirect();

        return Content::withoutSiteScope()->where('id', $content->id)->firstOrFail();
    }

    // ---------- 删除前引用保护 ----------

    public function test_page_referenced_by_active_menu_cannot_be_deleted(): void
    {
        $page = $this->makePage('linked-page');
        $this->makeMenu('/linked-page', '页面菜单');

        $this->actingAs($this->admin)
            ->delete(route('admin.pages.destroy', $page))
            ->assertRedirect();

        // 页面仍在；菜单未被改动
        $this->assertDatabaseHas('pages', ['id' => $page->id]);
        $this->assertDatabaseHas('menus', ['url' => '/linked-page', 'is_active' => true]);
    }

    public function test_content_referenced_by_active_menu_cannot_be_deleted(): void
    {
        $content = $this->publishContent('linked-content');
        $this->makeMenu($content->path(), '内容菜单');

        $this->actingAs($this->admin)
            ->delete(route('admin.contents.destroy', $content))
            ->assertRedirect();

        $this->assertDatabaseHas('contents', ['id' => $content->id, 'deleted_at' => null]);
    }

    public function test_unreferenced_page_can_be_deleted(): void
    {
        $page = $this->makePage('free-page');

        $this->actingAs($this->admin)
            ->delete(route('admin.pages.destroy', $page))
            ->assertRedirect(route('admin.pages.index'));

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_page_referenced_only_by_inactive_menu_can_be_deleted(): void
    {
        $page = $this->makePage('inactive-linked');
        $this->makeMenu('/inactive-linked', '停用菜单', false);

        $this->actingAs($this->admin)
            ->delete(route('admin.pages.destroy', $page))
            ->assertRedirect(route('admin.pages.index'));

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    // ---------- 历史悬空 INVALID 识别 ----------

    public function test_historical_dangling_menu_is_marked_invalid_in_health(): void
    {
        $this->makeMenu('/no-such-page', '悬空菜单');

        $resp = $this->actingAs($this->admin)->get(route('admin.geo.health'));
        $resp->assertOk();
        $resp->assertSee('悬空菜单');
        $resp->assertSee('Menu Dangling');
    }

    public function test_health_passes_with_valid_and_external_links(): void
    {
        $page = $this->makePage('healthy-page');
        $this->makeMenu('/healthy-page', '有效菜单');
        $this->makeMenu('https://example.com/ext', '外部菜单');

        // 直接解析服务层判定（外部链接不计悬空）
        $report = app(\App\Services\Geo\GeoHealthService::class)->report();
        $menuCheck = $report['checks']['menu_dangling'];
        $this->assertSame(0, $menuCheck['counts']['invalid']);
        $this->assertSame(1, $menuCheck['counts']['external']);
    }

    // ---------- INVALID 渲染降级 ----------

    public function test_invalid_menu_renders_as_non_clickable_placeholder(): void
    {
        $this->makeMenu('/no-such-page', '悬空菜单');

        $resp = $this->get('https://menu.test/');
        $resp->assertOk();
        // 以非可点击占位呈现
        $resp->assertSee('nav-invalid', false);
        $resp->assertSee('悬空菜单');
        // 不输出指向该路径的可点击链接
        $resp->assertDontSee('href="https://menu.test/no-such-page"', false);
    }

    public function test_valid_menu_renders_as_clickable_link(): void
    {
        $page = $this->makePage('clickable-page');
        $this->makeMenu('/clickable-page', '可点击菜单');

        $resp = $this->get('https://menu.test/');
        $resp->assertOk();
        $resp->assertSee('可点击菜单');
        // 有效菜单渲染为指向该页面的可点击链接
        $resp->assertSee('href="https://menu.test/clickable-page"', false);
    }
}
