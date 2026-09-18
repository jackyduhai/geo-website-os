<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台「导航菜单」固定栏目覆盖层（改名 / 隐藏 / 排序 / 恢复默认）与自定义追加的测试。
 */
class MenuOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        AppServiceProvider::forgetNavCache();
    }

    protected function menu(): array
    {
        return AppServiceProvider::mainMenu();
    }

    protected function top(string $name): ?array
    {
        return collect($this->menu())->first(fn ($m) => $m['name'] === $name);
    }

    public function test_admin_menus_page_lists_all_fixed_columns(): void
    {
        $this->actingAs($this->admin)->get('/admin/menus')
            ->assertOk()
            ->assertSee('固定主导航栏目')
            ->assertSee('固定页脚栏目')
            ->assertSee('产品中心')
            ->assertSee('Sample Snack创业小店')
            ->assertSee('联系我们');
    }

    public function test_default_main_menu_has_five_tops_and_full_children(): void
    {
        $menu = $this->menu();
        $this->assertSame(['产品中心', '应用场景', '工厂与资质', '知识中心', '关于我们'], array_column($menu, 'name'));

        $products = $this->top('产品中心');
        $childNames = array_column($products['children'], 'name');
        $this->assertContains('Sample Spice', $childNames);
        $this->assertCount(5, $childNames);
    }

    public function test_override_renames_fixed_top(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'products', 'label' => '产品体系', 'is_active' => 1,
        ])->assertRedirect();

        $this->assertNotNull($this->top('产品体系'));
        $this->assertNull($this->top('产品中心'));
    }

    public function test_override_hides_fixed_child(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'products-spices', 'label' => '',
            // 不提交 is_active => 视为隐藏
        ])->assertRedirect();

        $childNames = array_column($this->top('产品中心')['children'], 'name');
        $this->assertNotContains('Sample Spice', $childNames);
        $this->assertCount(4, $childNames);
    }

    public function test_override_reorders_tops(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'about', 'label' => '', 'is_active' => 1, 'sort' => 5,
        ])->assertRedirect();

        $this->assertSame('关于我们', $this->menu()[0]['name']);
    }

    public function test_reset_restores_default(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'products', 'label' => '产品体系', 'is_active' => 1,
        ])->assertRedirect();
        $this->assertNotNull($this->top('产品体系'));

        $this->actingAs($this->admin)->delete('/admin/menus/override/products')->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $this->assertNotNull($this->top('产品中心'));
        $this->assertNull($this->top('产品体系'));
    }

    public function test_override_rejects_unknown_key(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'not-a-real-column', 'is_active' => 1,
        ])->assertNotFound();
    }

    public function test_custom_menu_is_appended_after_fixed_columns(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'label' => '招商合作',
            'url' => '/partner/',
            'sort' => 0,
            'target' => 0,
            'is_active' => 1,
        ])->assertRedirect();

        $names = array_column($this->menu(), 'name');
        $this->assertContains('招商合作', $names);
        // 自定义项始终排在固定栏目之后
        $this->assertGreaterThan(array_search('关于我们', $names, true), array_search('招商合作', $names, true));
    }

    public function test_custom_child_under_custom_root_renders_as_second_level(): void
    {
        // 先建自定义一级（纯父级，无链接）
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'label' => '招商合作', 'url' => '',
            'sort' => 0, 'target' => 0, 'is_active' => 1,
        ])->assertRedirect();
        $root = \App\Models\Menu::whereNull('key')->where('label', '招商合作')->firstOrFail();

        // 再建二级，外链新窗
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'footer', // 提交页脚也应被强制归主导航
            'parent_ref' => 'id:' . $root->id,
            'label' => '加盟政策',
            'url' => 'https://example.com/join',
            'sort' => 0, 'target' => 1, 'is_active' => 1,
        ])->assertRedirect();

        $top = $this->top('招商合作');
        $this->assertNotNull($top);
        $this->assertCount(1, $top['children']);
        $child = $top['children'][0];
        $this->assertSame('加盟政策', $child['name']);
        $this->assertSame('https://example.com/join', $child['url']);
        $this->assertTrue($child['external']);

        $childModel = \App\Models\Menu::where('label', '加盟政策')->firstOrFail();
        $this->assertSame('main', $childModel->position);
        $this->assertSame($root->id, (int) $childModel->parent_id);
    }

    public function test_custom_child_under_fixed_top_appends_after_builtin_children(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'parent_ref' => 'key:products',
            'label' => '产品定制',
            'url' => '/custom-product/',
            'sort' => 0, 'target' => 0, 'is_active' => 1,
        ])->assertRedirect();

        $products = $this->top('产品中心');
        $names = array_column($products['children'], 'name');
        // 固定 5 个子项在前，自定义子项追加在最后
        $this->assertSame('产品定制', end($names));
        $this->assertCount(6, $names);
    }

    public function test_anchored_child_under_products_shows_in_page_subnav(): void
    {
        // 挂接到固定一级「产品中心」的自定义二级，必须同时出现在顶部下拉与产品页内二级 Tab
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'parent_ref' => 'key:products',
            'label' => '产品定制',
            'url' => '/custom-product/',
            'sort' => 0, 'target' => 0, 'is_active' => 1,
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();

        // 产品总览与系列页 Tab 条都应包含自定义项
        $this->get('/products/')->assertOk()->assertSee('产品定制');
        $this->get('/products/seasoning/')->assertOk()->assertSee('产品定制');
    }

    public function test_hidden_fixed_child_disappears_from_subnav_too(): void
    {
        // 导航覆盖层隐藏固定子项后，页面内二级 Tab 必须同步隐藏（同一数据源）
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'products-spices', 'label' => '',
        ])->assertRedirect();
        AppServiceProvider::forgetNavCache();

        $html = $this->get('/products/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<div class="wrap subnav-in".*?<\/div>\s*<\/div>/s',
            $html
        );
        preg_match('/<div class="wrap subnav-in".*?<\/div>\s*<\/div>/s', $html, $m);
        $this->assertStringNotContainsString('Sample Spice', $m[0]);
    }

    public function test_anchored_child_renders_inline_under_fixed_row_on_admin_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'parent_ref' => 'key:products',
            'label' => '产品定制',
            'url' => '/custom-product/',
            'sort' => 0, 'target' => 0, 'is_active' => 1,
        ])->assertRedirect();

        // 固定栏目表内联展示，旧的底部「挂于：」平铺已移除
        $this->actingAs($this->admin)->get('/admin/menus')
            ->assertOk()
            ->assertSee('产品定制')
            ->assertSee('自定义追加')
            ->assertDontSee('挂于：', false);
    }

    public function test_404_quick_entries_follow_main_menu(): void
    {
        // 404 快捷入口与主导航同源：默认展示固定一级栏目与联系我们（只比对入口卡片区，避开页脚）
        $entries = function (string $html): string {
            preg_match('/<div class="kgrid kgrid-3 err-entries".*?<\/div>\s*<\/div>/s', $html, $m);
            return $m[0] ?? '';
        };

        $html = $this->get('/this-page-does-not-exist')->assertNotFound()->getContent();
        $box = $entries($html);
        $this->assertStringContainsString('产品中心', $box);
        $this->assertStringContainsString('联系我们', $box);

        // 后台隐藏一级栏目后，404 入口同步消失（不再写死）
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'key' => 'factory', 'label' => '',
        ])->assertRedirect();
        AppServiceProvider::forgetNavCache();

        $html2 = $this->get('/another-missing-page')->assertNotFound()->getContent();
        $this->assertStringNotContainsString('工厂与资质', $entries($html2));
    }

    public function test_invalid_parent_ref_falls_back_to_root(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'parent_ref' => 'key:not-exist',
            'label' => '独立入口',
            'url' => '/standalone/',
            'is_active' => 1,
        ])->assertRedirect();

        $m = \App\Models\Menu::where('label', '独立入口')->firstOrFail();
        $this->assertNull($m->parent_key);
        $this->assertNull($m->parent_id);
    }

    public function test_root_with_children_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'label' => '招商合作', 'url' => '#', 'is_active' => 1,
        ])->assertRedirect();
        $root = \App\Models\Menu::whereNull('key')->where('label', '招商合作')->firstOrFail();
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'parent_ref' => 'id:' . $root->id,
            'label' => '子项', 'url' => '/sub/', 'is_active' => 1,
        ])->assertRedirect();

        $this->actingAs($this->admin)->delete('/admin/menus/' . $root->id)
            ->assertRedirect();
        $this->assertDatabaseHas('menus', ['id' => $root->id]);
    }

    public function test_inactive_custom_child_is_hidden(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'label' => '招商合作', 'url' => '#', 'is_active' => 1,
        ])->assertRedirect();
        $root = \App\Models\Menu::whereNull('key')->where('label', '招商合作')->firstOrFail();
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'parent_ref' => 'id:' . $root->id,
            'label' => '停用子项', 'url' => '/off/', 'is_active' => 0,
        ])->assertRedirect();

        $this->assertCount(0, $this->top('招商合作')['children']);
    }

    public function test_override_repoints_fixed_top_to_external_link(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'main', 'key' => 'about',
            'label' => '', 'url' => 'https://example.com/group',
            'target' => 1, 'is_active' => 1,
        ])->assertRedirect();

        $about = $this->top('关于我们');
        $this->assertSame('https://example.com/group', $about['url']);
        $this->assertTrue($about['external']);
        $this->assertSame([], $about['patterns']); // 外链不参与当前态匹配
    }

    public function test_override_repoints_fixed_child_to_internal_path(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'main', 'key' => 'products-spices',
            'label' => '', 'url' => '/knowledge/process/',
            'is_active' => 1,
        ])->assertRedirect();

        $spices = collect($this->top('产品中心')['children'])->firstWhere('name', 'Sample Spice');
        $this->assertStringEndsWith('/knowledge/process/', $spices['url']);
        $this->assertFalse($spices['external']);
    }

    public function test_dynamic_knowledge_child_cannot_have_link_overridden(): void
    {
        // 知识中心子项由内容分组驱动，key 取首个启用分组的 href
        $knowledge = $this->top('知识中心');
        $first = $knowledge['children'][0];
        $originalUrl = $first['url'];

        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'main',
            'key' => collect(AppServiceProvider::mainMenuBlueprint())
                ->firstWhere('key', 'knowledge')['children'][0]['key'],
            'label' => '', 'url' => 'https://evil.example.com',
            'target' => 1, 'is_active' => 1,
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $knowledge = $this->top('知识中心');
        $this->assertSame($originalUrl, $knowledge['children'][0]['url']);
        $this->assertFalse($knowledge['children'][0]['external']);
    }

    public function test_explicit_sort_interleaves_custom_and_fixed(): void
    {
        // 自定义一级显式 sort=15，应排在产品中心(10)与应用场景(20)之间
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'label' => '招商合作', 'url' => '/partner/',
            'sort' => 15, 'is_active' => 1,
        ])->assertRedirect();

        $names = array_column($this->menu(), 'name');
        $this->assertSame(['产品中心', '招商合作', '应用场景'], array_slice($names, 0, 3));

        // 固定栏目下的自定义子项显式 sort=15，与固定子项混排
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main', 'parent_ref' => 'key:products',
            'label' => '产品定制', 'url' => '/custom/', 'sort' => 15, 'is_active' => 1,
        ])->assertRedirect();
        $childNames = array_column($this->top('产品中心')['children'], 'name');
        $this->assertSame('产品定制', $childNames[1]); // 固定子项 sort 10,20,...
    }

    public function test_footer_blueprint_overrides_column_title_and_link(): void
    {
        // 改页脚列标题
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-col-about',
            'label' => '了解Example', 'is_active' => 1,
        ])->assertRedirect();
        // 改页脚链接
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-cooperation',
            'label' => '', 'url' => 'https://example.com/coop',
            'target' => 1, 'is_active' => 1,
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $about = collect(AppServiceProvider::footerMenu())->firstWhere('key', 'ft-col-about');
        $this->assertSame('了解Example', $about['title']);
        $coop = collect($about['items'])->firstWhere('name', '合作方式');
        $this->assertSame('https://example.com/coop', $coop['url']);
        $this->assertTrue($coop['external']);
    }

    public function test_footer_hide_item_and_hide_column(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-factory',
            'label' => '', // 不勾 is_active = 隐藏
        ])->assertRedirect();
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-col-solutions',
            'label' => '', // 不勾 is_active = 隐藏整列
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $columns = AppServiceProvider::footerMenu();
        $this->assertNull(collect($columns)->firstWhere('key', 'ft-col-solutions'));
        $about = collect($columns)->firstWhere('key', 'ft-col-about');
        $this->assertNull(collect($about['items'])->firstWhere('name', '工厂与资质'));
    }

    public function test_footer_custom_link_attaches_to_column_and_roots_go_extra(): void
    {
        // 挂到固定页脚列
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'footer', 'parent_ref' => 'key:ft-col-about',
            'label' => '招贤纳士', 'url' => '/jobs/', 'is_active' => 1,
        ])->assertRedirect();
        // 无上级的页脚链接进「快捷入口」
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'footer', 'parent_ref' => '',
            'label' => '友情链接', 'url' => 'https://example.com',
            'target' => 1, 'is_active' => 1,
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $about = collect(AppServiceProvider::footerMenu())->firstWhere('key', 'ft-col-about');
        $this->assertNotNull(collect($about['items'])->firstWhere('name', '招贤纳士'));

        $extra = AppServiceProvider::footerExtra();
        $this->assertSame('友情链接', $extra[0]['name']);
        $this->assertTrue($extra[0]['external']);

        // 挂列项落库为 footer 位置
        $jobs = \App\Models\Menu::where('label', '招贤纳士')->firstOrFail();
        $this->assertSame('footer', $jobs->position);
        $this->assertSame('ft-col-about', $jobs->parent_key);
    }

    public function test_footer_contact_items_are_settings_driven(): void
    {
        \App\Models\Setting::set('contact_phone', '400-000-0000');
        \App\Models\Setting::set('contact_mobile', '13800000000');
        \App\Models\Setting::flush();
        AppServiceProvider::forgetNavCache();

        $contact = collect(AppServiceProvider::footerMenu())->firstWhere('key', 'ft-col-contact');
        $this->assertNotNull($contact);
        $keys = array_column($contact['items'], 'key');
        $this->assertContains('ft-contact-hotline', $keys);
        $this->assertContains('ft-contact-qr', $keys);

        // 锁定项提交 url 覆盖应被忽略
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-contact-hotline',
            'label' => '', 'url' => 'https://evil.example.com', 'is_active' => 1,
        ])->assertRedirect();
        AppServiceProvider::forgetNavCache();
        $hotline = collect(collect(AppServiceProvider::footerMenu())
            ->firstWhere('key', 'ft-col-contact')['items'])->firstWhere('key', 'ft-contact-hotline');
        $this->assertStringNotContainsString('evil.example.com', (string) $hotline['url']);
    }

    public function test_footer_column_override_never_leaks_into_extra_links(): void
    {
        // 改页脚列标题会产生 key=ft-col-* 的覆盖行，它绝不能出现在「快捷入口」
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-col-about',
            'label' => '了解Example', 'is_active' => 1,
        ])->assertRedirect();

        AppServiceProvider::forgetNavCache();
        $extra = AppServiceProvider::footerExtra();
        $this->assertNotContains('了解Example', array_column($extra, 'name'));
    }

    public function test_footer_override_unknown_key_rejected(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus/override', [
            'position' => 'footer', 'key' => 'ft-col-nope', 'is_active' => 1,
        ])->assertNotFound();
    }
}
