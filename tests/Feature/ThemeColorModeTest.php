<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18D：前台 Light / Dark / System 外观模式回归。
 *
 * 守护契约：
 *  - 默认允许深色：前台输出 color-scheme meta、首帧防闪脚本（localStorage 键 gwos-color-mode）、
 *    深色覆盖 CSS、右上角三态切换器；默认 light 时 SSR <html data-color-scheme="light">。
 *  - color_mode=dark：SSR 直接输出 data-color-scheme="dark"，无首帧反转。
 *  - color_mode=system：SSR 不写死具体模式，引导脚本默认 def=system 并跟随 prefers-color-scheme。
 *  - allow_dark=0：强制浅色——无切换器、无深色 CSS、无 localStorage 读取，meta 仅 light。
 *  - 非法枚举被后台拒绝；外观设置严格 per-site，B 站深色不污染 A 站。
 */
class ThemeColorModeTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        PageCache::flush();
    }

    private function makeSiteB(): Site
    {
        // 经后台控制器真实建站：store 会为新站播种产品级默认设置（七组完整字段），
        // 否则该站设置页无行可遍历、外观等配置无法保存（P-STEP 18D 修复的真实缺陷）。
        $this->actingAs($this->super)
            ->post(route('admin.sites.store'), [
                'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
                'status' => 'active', 'is_default' => false,
            ])
            ->assertRedirect();

        return Site::where('slug', 'site-b')->firstOrFail();
    }

    private function saveTheme(array $payload): void
    {
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), $payload)
            ->assertRedirect();
        PageCache::flush();
    }

    public function test_guest_cannot_update_appearance(): void
    {
        $this->put(route('admin.settings.update', 'theme'), ['theme_color_mode' => 'dark'])
            ->assertRedirect(route('admin.login'));
    }

    public function test_default_frontend_allows_dark_and_renders_full_asset_surface(): void
    {
        $home = $this->get('http://localhost/')->assertOk();

        // 浏览器与 SSR 均被告知支持浅色 + 深色。
        $home->assertSee('<meta name="color-scheme" content="light dark">', false);
        // 默认 light：首帧 SSR 即浅色，不依赖 JS。
        $home->assertSee('<html lang="zh-CN" data-theme="default" data-color-scheme="light">', false);
        // 首帧防闪脚本（localStorage > 站点默认 > 系统）。
        $home->assertSee('gwos-color-mode', false);
        $home->assertSee("var def = 'light';", false);
        // 深色覆盖样式（显式选择器 + 无 JS 系统偏好兜底）。
        $home->assertSee(':root[data-color-scheme="dark"]{color-scheme:dark;', false);
        $home->assertSee('@media (prefers-color-scheme:dark)', false);
        // 三态切换器与吸顶毛玻璃令牌。
        $home->assertSee('data-theme-toggle', false);
        $home->assertSee('tmt-sun', false)->assertSee('tmt-moon', false)->assertSee('tmt-sys', false);
        $home->assertSee('var(--hd-bg)', false);
    }

    public function test_dark_default_renders_dark_ssr_attribute_without_flash(): void
    {
        $this->saveTheme(['theme_color_mode' => 'dark']);

        $this->assertSame('dark', Setting::get('theme_color_mode'));

        $home = $this->get('http://localhost/')->assertOk();
        $home->assertSee('<html lang="zh-CN" data-theme="default" data-color-scheme="dark">', false);
    }

    public function test_system_default_has_no_hard_ssr_mode_and_script_defaults_system(): void
    {
        $this->saveTheme(['theme_color_mode' => 'system']);

        $home = $this->get('http://localhost/')->assertOk();
        // system 不在服务端写死 light/dark，交由引导脚本按系统偏好解析。
        $home->assertSee('<html lang="zh-CN" data-theme="default">', false);
        $home->assertSee("var def = 'system';", false);
        // 仍然提供深色样式与切换器。
        $home->assertSee(':root[data-color-scheme="dark"]', false);
        $home->assertSee('data-theme-toggle', false);
    }

    public function test_disallow_dark_forces_light_and_removes_every_dark_surface(): void
    {
        $this->saveTheme(['theme_allow_dark' => '0']);

        $this->assertSame('0', Setting::get('theme_allow_dark'));

        $home = $this->get('http://localhost/')->assertOk();
        // SSR 不预置深色（<html> 无 data-color-scheme），引导脚本被短路为 light。
        $home->assertSee('<html lang="zh-CN" data-theme="default">', false);
        $home->assertSee('<meta name="color-scheme" content="light">', false);
        // 关闭深色后 head 只输出强制浅色的极简引导，不含 localStorage / 深色逻辑。
        $home->assertSee("document.documentElement.setAttribute('data-color-scheme','light');document.documentElement.dataset.colorModePref='light'", false);
        // 不输出任何深色能力。
        $home->assertDontSee('data-theme-toggle', false);
        $home->assertDontSee(':root[data-color-scheme="dark"]', false);
        $home->assertDontSee('@media (prefers-color-scheme:dark)', false);
        $home->assertDontSee('gwos-color-mode', false);
    }

    public function test_invalid_color_mode_is_rejected_and_not_persisted(): void
    {
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), ['theme_color_mode' => 'neon'])
            ->assertSessionHasErrors('theme_color_mode');

        $this->assertSame('light', Setting::get('theme_color_mode'));
    }

    public function test_appearance_default_is_strictly_per_site_and_does_not_leak(): void
    {
        $siteB = $this->makeSiteB();

        // 切换到 B 站，把默认外观设为深色。
        $this->actingAs($this->super)
            ->post(route('admin.sites.switch'), ['site_id' => $siteB->id])
            ->assertRedirect();
        $this->saveTheme(['theme_color_mode' => 'dark']);

        // B 站前台 SSR 深色。
        $this->get('http://b.test/')->assertOk()
            ->assertSee('<html lang="zh-CN" data-theme="default" data-color-scheme="dark">', false);

        // A（默认站）仍是 SSR 浅色，未被串站污染。
        $a = $this->get('http://localhost/')->assertOk();
        $a->assertSee('<html lang="zh-CN" data-theme="default" data-color-scheme="light">', false);
        $a->assertDontSee('<html lang="zh-CN" data-theme="default" data-color-scheme="dark">', false);
    }

    public function test_new_site_is_provisioned_with_full_default_settings(): void
    {
        $siteB = $this->makeSiteB();

        // 新站出厂即带七组完整默认设置，外观键存在且为中性默认，后台可直接保存覆盖。
        \App\Support\SiteContext::withSite($siteB, function (): void {
            $this->assertSame(15, Setting::where('group', 'theme')->count());
            $this->assertSame('light', Setting::get('theme_color_mode'));
            $this->assertSame('1', Setting::get('theme_allow_dark'));
        });
    }
}
