<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ThemeController;
use App\Models\Setting;
use App\Support\Theme\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 主题切换必须真正生效（RC-11 H1）。
 *
 * 优先级链（见 docs/product/template-theme-site-settings-architecture.md）：
 *     站点设置 > 激活主题种子 > ThemePalette::DEFAULTS
 *
 * 缺陷：站点设置一旦写了 theme_primary，**任何主题都切不动** ——
 * 前台永远显示那一个颜色，「切换主题」形同虚设。
 * 另一处缺陷：暗色令牌漏传主题种子，永远回落 DEFAULTS（出厂蓝），
 * 于是亮色跟主题变、暗色不变，导航 hover / 选中态颜色对不上。
 */
class ThemeSwitchOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): \App\Models\User
    {
        return \App\Models\User::create([
            'name'           => 'Theme Test Admin',
            'email'          => 'theme-admin@example.test',
            'password'       => bcrypt('Test@123456'),
            'is_super_admin' => true,
        ]);
    }

    public function test_switching_theme_clears_seed_overridable_settings(): void
    {
        // 站点设置里有配色覆盖（模拟「之前调过主题色」）
        Setting::set('theme_primary', '#2563EB');
        Setting::set('theme_accent', '#64748B');
        Setting::flush();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.themes.activate', 'example'))
            ->assertRedirect();

        foreach (ThemeController::SEED_OVERRIDABLE as $key) {
      $this->assertSame(
        '',
 Setting::get($key),
      "切换主题后 {$key} 应被清空 —— 否则它会盖住新主题的种子色，"
            . '表现为「切换主题后前台颜色不变」'
            );
        }

        $this->assertSame('example', ThemeManager::active(), '主题应已切换');
    }

    /**
     * 站点偏好类设置**不能**被清 —— 清了会丢用户选择。
     */
    public function test_switching_theme_preserves_site_preferences(): void
    {
        Setting::set('theme_color_mode', 'dark');
        Setting::set('theme_allow_dark', '1');
        Setting::set('theme_custom_css', '.my-thing{color:red}');
        Setting::flush();

        $this->actingAs($this->superAdmin())
  ->post(route('admin.themes.activate', 'example'))
   ->assertRedirect();

        $this->assertSame('dark', Setting::get('theme_color_mode'), '外观模式是站点偏好，不该被清');
        $this->assertSame('1', (string) Setting::get('theme_allow_dark'), '是否允许深色是站点偏好，不该被清');
        $this->assertSame('.my-thing{color:red}', Setting::get('theme_custom_css'), '运营手写 CSS 不该被清');
    }

    /**
     * 暗色令牌必须与亮色用**同一份**主题种子。
     *
     * 缺陷现场：site.blade.php 里
     *   亮色 resolve($siteSettings, ThemeManager::activeTokens())  ← 传了
     *   暗色 darkOverrides($siteSettings)                ← 漏传
     * 导致暗色 --brand 永远取ThemePalette::DEFAULTS（#2563EB），
     * 切主题后导航 hover / 选中态仍是上一个主题的颜色。
     *
     * 这里做静态契约断言 —— 不依赖浏览器，只保证「没再漏传」。
     */
public function test_dark_tokens_receive_the_same_theme_seed_as_light(): void
    {
        $src = (string) file_get_contents(
          __DIR__ . '/../../resources/views/layouts/site.blade.php'
      );

        // 取出 darkOverrides(...) 这一次调用的实参文本
        $ok = preg_match('/darkOverrides\(([^;]*?)\);/s', $src, $m);
        $this->assertSame(1, $ok, '应能找到 darkOverrides() 调用');
        $args = (string) $m[1];

        $this->assertStringContainsString(
         'ThemeManager::activeTokens()',
     $args,
   'darkOverrides() 未传入 ThemeManager::activeTokens() —— '
      . '暗色令牌会永远回落 ThemePalette::DEFAULTS，'
            . '表现为「切换主题后暗色模式下导航 hover / 选中仍是旧颜色」'
        );

        // 反向对照：亮色那条必须本来就带种子，防止将来只改暗色那条
        $this->assertSame(
            2,
   substr_count($src, 'ThemeManager::activeTokens()'),
            '亮色 resolve() 与暗色 darkOverrides() 应各传一次 activeTokens()'
        );
    }

/**
     * 前提校验：example 主题确实带不同于 default 的种子色，
     * 否则「切主题后颜色不变」可能只是种子本身相同，测试就失去意义。
     *
     * 走真实路径：先 activate 再 activeTokens()（all() 有意不暴露 tokens）。
     */
    public function test_seeded_example_theme_differs_from_default(): void
    {
        $this->assertTrue(ThemeManager::activate('example'), 'example 主题应可激活');

        $tokens = ThemeManager::activeTokens();
        $this->assertNotEmpty($tokens, 'example 主题应带种子 token');
        $this->assertNotSame(
      '',
  (string) ($tokens['theme_primary'] ?? ''),
            'example 应定义 theme_primary 种子'
        );

        // default 主题按设计不带种子（activeTokens 返回 []），
        // 所以「default → example」必然带来颜色变化，测试有意义。
        ThemeManager::activate('default');
     $this->assertSame(
            [],
            ThemeManager::activeTokens(),
  'default 主题不应带种子令牌'
        );
    }
}
