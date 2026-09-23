<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18D：行业视觉预设后台回归。
 *
 * 守护：访客被拦截；超级管理员一键应用预设后 theme_* 视觉令牌落库、主色（深）置空
 * 走自动派生、前台 :root 立即换色；非法预设 404；预设严格 per-site——在 B 站应用
 * 不会污染 A 站。预设只改外观，断言其不会写入任何 IA / 内容字段。
 */
class ThemePresetAdminTest extends TestCase
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
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    public function test_guest_cannot_apply_preset(): void
    {
        $this->post(route('admin.settings.preset'), ['preset' => 'technology'])
            ->assertRedirect(route('admin.login'));
    }

    public function test_super_admin_applies_preset_and_frontend_uses_derived_tokens(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.settings.preset'), ['preset' => 'technology'])
            ->assertRedirect(route('admin.settings.index', ['group' => 'theme']))
            ->assertSessionHas('success');

        // 预设视觉令牌落库；主色（深）置空，交由 ThemePalette 从新主色自动派生。
        $this->assertSame('#4F46E5', \App\Models\Setting::get('theme_primary'));
        $this->assertSame('#0E7490', \App\Models\Setting::get('theme_accent'));
        $this->assertSame('', \App\Models\Setting::get('theme_primary_dark'));
        $this->assertSame('10', \App\Models\Setting::get('theme_radius'));

        // 主题设置页可见预设面板。
        $this->actingAs($this->super)->get('/admin/settings/theme')
            ->assertOk()->assertSee('行业视觉预设')->assertSee('科技软件');

        // 前台 :root 立即消费新品牌色（缓存已在应用预设时清空）。
        $home = $this->get('http://localhost/')->assertOk();
        $home->assertSee('#4F46E5');
        $home->assertDontSee('#2563EB');
    }

    public function test_unknown_preset_is_404(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.settings.preset'), ['preset' => 'does-not-exist'])
            ->assertNotFound();
    }

    public function test_preset_is_strictly_per_site_and_does_not_leak(): void
    {
        $siteB = $this->makeSiteB();

        // 切换到 B 站后应用 commerce（玫红）。
        $this->actingAs($this->super)
            ->post(route('admin.sites.switch'), ['site_id' => $siteB->id])->assertRedirect();
        $this->actingAs($this->super)
            ->post(route('admin.settings.preset'), ['preset' => 'commerce'])
            ->assertRedirect();

        PageCache::flush();

        // B 站前台呈现 commerce 主色。
        $this->get('http://b.test/')->assertOk()->assertSee('#E11D48');

        // A（默认站）仍是 professional 蓝，未被串站污染。
        $a = $this->get('http://localhost/')->assertOk();
        $a->assertSee('#2563EB');
        $a->assertDontSee('#E11D48');
    }

    public function test_preset_only_changes_visual_keys_not_content_or_ia(): void
    {
        $beforeName = $this->default->name;

        $this->actingAs($this->super)
            ->post(route('admin.settings.preset'), ['preset' => 'industrial'])
            ->assertRedirect();

        // 站点名 / 导航等非视觉事实不变。
        $this->assertSame($beforeName, $this->default->fresh()->name);
        // 工业预设的视觉特征落库：紧凑密度、小圆角、钢蓝主色。
        $this->assertSame('compact', \App\Models\Setting::get('theme_density'));
        $this->assertSame('flat', \App\Models\Setting::get('theme_shadow'));
        $this->assertSame('#1D6FA5', \App\Models\Setting::get('theme_primary'));
    }

    public function test_all_blueprint_industry_presets_exist_with_valid_visual_tokens(): void
    {
        // 蓝图 §9 八类行业视觉预设（Consumer 由 commerce 零售电商承载）。
        $required = ['technology', 'professional', 'industrial', 'finance',
            'education', 'healthcare', 'commerce', 'lifestyle'];

        $keys = \App\Support\Theme\ThemePresets::keys();
        foreach ($required as $key) {
            $this->assertContains($key, $keys, "缺少行业预设：{$key}");
            $tokens = \App\Support\Theme\ThemePresets::tokens($key);
            // 只允许写入视觉白名单键，不得夹带 IA / 内容字段。
            $this->assertSame([], array_values(array_diff(array_keys($tokens), \App\Support\Theme\ThemePresets::allowedKeys())),
                "预设 {$key} 含非视觉键");
            // 主色（深）一律置空，交由 ThemePalette 从新主色自动派生悬停 / 浅底。
            $this->assertSame('', $tokens['theme_primary_dark']);
            $this->assertNotEmpty($tokens['theme_primary']);
        }
    }
}
