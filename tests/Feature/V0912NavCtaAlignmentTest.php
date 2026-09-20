<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.9.12 前后台能力对齐回归：
 *  - 「结构 → 导航菜单」不再是死面板：主导航/移动端追加项与页脚“快捷入口”真正渲染到前台
 *  - 外链自动 target=_blank rel=noopener；停用项不渲染；保存即清导航缓存
 *  - 顶部/全站主 CTA 文案由「基础信息 → 主 CTA 按钮文案」驱动，留空回退默认
 */
class V0912NavCtaAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_nav_cta_text_setting_is_seeded(): void
    {
        $this->assertSame('获取产品方案', Setting::allCached()['nav_cta_text'] ?? null);
    }

    public function test_header_and_hero_cta_label_follows_setting_with_fallback(): void
    {
        Setting::set('nav_cta_text', '一键获取方案');
        Setting::flush();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('一键获取方案', $html);
        $this->assertStringNotContainsString('获取产品方案', $html);

        // 留空回退 config 默认口径
        Setting::set('nav_cta_text', '');
        Setting::flush();
        $fallback = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(config('copy.nav.cta'), $fallback);
    }

    public function test_general_settings_endpoint_persists_nav_cta_text(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/general', [
            'site_name'      => '示例制造',
            'nav_cta_text'   => '立即获取方案',
            'site_slogan'    => '',
            'site_description' => '',
            'site_short_name' => '',
            'icp_number'     => '',
            'police_number'  => '',
        ])->assertRedirect();

        $this->assertSame('立即获取方案', Setting::allCached()['nav_cta_text'] ?? null);
    }

    public function test_active_custom_main_menu_renders_external_and_inactive_hidden(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position'  => 'main',
            'label'     => '招商合作',
            'url'       => 'https://example.com/join',
            'target'    => 1,
            'sort'      => 90,
            'is_active' => 1,
        ])->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('招商合作', $html);
        $this->assertStringContainsString('https://example.com/join', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);

        // 停用即从前台消失
        $menu = Menu::where('label', '招商合作')->firstOrFail();
        $menu->update(['is_active' => false]);
        AppServiceProvider::forgetNavCache();

        $hidden = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('招商合作', $hidden);
    }

    public function test_footer_custom_menu_renders_as_quick_links(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position'  => 'footer',
            'label'     => '工厂实拍',
            'url'       => '/factory/',
            'target'    => 0,
            'sort'      => 10,
            'is_active' => 1,
        ])->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('快捷入口', $html);
        $this->assertStringContainsString('工厂实拍', $html);
        // 内部路径走 url() 且不开新窗
        $this->assertMatchesRegularExpression(
            '~工厂实拍~', $html
        );
    }

    public function test_menu_store_requires_label(): void
    {
        $this->actingAs($this->admin)->post('/admin/menus', [
            'position' => 'main',
            'label'    => '',
        ])->assertSessionHasErrors('label');
    }
}
