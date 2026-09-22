<?php

namespace Tests\Unit;

use App\Support\Theme\ThemePalette as P;
use App\Support\Theme\ThemePresets;
use Tests\TestCase;

/**
 * ThemePalette 设计令牌派生引擎单测（P-STEP 18D）。
 *
 * 重点守护：Brand Seed → 语义色簇的 WCAG AA 对比度（白字 / 白底双向）、
 * 原始明亮种子（bright / soft / hero）与加深实心色的分层、密度 / 阴影质感、
 * 错误红不随品牌变化、非法输入回退。纯函数，无 DB。
 */
class ThemePaletteTest extends TestCase
{
    /** 与白（文字或底色在另一端）的对比度。 */
    private function crWithWhite(string $hex): float
    {
        return 1.05 / (P::luminance($hex) + 0.05);
    }

    /** 前景色 $fg 在底色 $bg 上的对比度。 */
    private function contrast(string $fg, string $bg): float
    {
        $lf = P::luminance($fg);
        $lb = P::luminance($bg);
        $hi = max($lf, $lb);
        $lo = min($lf, $lb);

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_default_resolve_exposes_full_semantic_token_surface(): void
    {
        $t = P::resolve([]);

        $this->assertGreaterThanOrEqual(60, count($t));
        foreach (['--brand', '--brand-dark', '--brand-soft', '--brand-bright',
                      '--accent', '--accent-dark', '--accent-soft', '--accent-bright',
                      '--cta', '--cta-dark', '--cta-on', '--surface', '--ink', '--line',
                      '--hero-gradient', '--scrim', '--radius', '--sec-y',
                      '--shadow-hover', '--error'] as $name) {
            $this->assertArrayHasKey($name, $t, "缺少令牌 {$name}");
        }
        $this->assertStringContainsString('--brand:', P::toCss($t));
    }

    public function test_brand_accent_and_cta_meet_aa_on_white_for_every_preset(): void
    {
        foreach (array_keys((array) config('theme-presets')) as $key) {
            $t = P::resolve(ThemePresets::tokens($key));

            // 这些色既会作实心按钮底（白字），也会作白底上的链接 / 图标 / 文字。
            $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite($t['--brand']), "preset[{$key}] brand 白底对比不足");
            $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite($t['--accent']), "preset[{$key}] accent 白底对比不足");
            $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite($t['--cta']), "preset[{$key}] cta 白底对比不足");

            // CTA 按钮白字对比。
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($t['--cta-on'], $t['--cta']), "preset[{$key}] CTA 白字对比不足");
            $this->assertSame('#FFFFFF', $t['--cta-on']);
        }
    }

    public function test_bright_custom_seed_is_deepened_to_aa_for_text_and_buttons(): void
    {
        // 亮琥珀是典型「黑白文字都难达标」的中间明度种子，必须被自动加深。
        $t = P::resolve(['theme_primary' => '#F59E0B', 'theme_accent' => '#F59E0B']);

        $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite($t['--brand']), '亮琥珀主色未加深到达标');
        $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite($t['--cta']), '亮琥珀 CTA 未加深到达标');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($t['--cta-on'], $t['--cta']));
        // 原始明亮种子仍保留给深底点缀 / 浅底 / 渐变。
        $this->assertSame('#F59E0B', $t['--brand-bright']);
        $this->assertGreaterThan(P::luminance($t['--brand']), P::luminance($t['--brand-bright']));
    }

    public function test_bright_tokens_are_no_darker_than_solid_tokens(): void
    {
        foreach ([[] , ThemePresets::tokens('lifestyle'), ThemePresets::tokens('education')] as $settings) {
            $t = P::resolve($settings);
            $this->assertGreaterThanOrEqual(P::luminance($t['--brand']), P::luminance($t['--brand-bright']));
            $this->assertGreaterThanOrEqual(P::luminance($t['--accent']), P::luminance($t['--accent-bright']));
        }
    }

    public function test_soft_surfaces_are_light(): void
    {
        $t = P::resolve(ThemePresets::tokens('technology'));
        $this->assertGreaterThan(0.85, P::luminance($t['--brand-soft']));
        $this->assertGreaterThan(0.85, P::luminance($t['--accent-soft']));
        $this->assertGreaterThan(0.85, P::luminance($t['--cta-soft']));
    }

    public function test_deepen_for_contrast_is_idempotent_and_leaves_dark_color(): void
    {
        $dark = P::hexToRgb('#0B3D2E'); // 已足够深
        $this->assertSame($dark, P::deepenForContrast($dark));

        $once = P::deepenForContrast(P::hexToRgb('#0E9F6E'));
        $twice = P::deepenForContrast($once);
        $this->assertSame($once, $twice, '重复加深应幂等');
        $this->assertGreaterThanOrEqual(4.5, $this->crWithWhite(P::hex($once)));
    }

    public function test_density_and_shadow_tokens_respond_to_settings(): void
    {
        $comfortable = P::resolve(['theme_density' => 'comfortable', 'theme_shadow' => 'flat']);
        $this->assertSame('96px', $comfortable['--sec-y']);
        $this->assertSame('none', $comfortable['--shadow']);

        $compact = P::resolve(['theme_density' => 'compact', 'theme_shadow' => 'soft']);
        $this->assertSame('64px', $compact['--sec-y']);
        $this->assertNotSame('none', $compact['--shadow-hover']);
        $this->assertStringContainsString('rgba(', $compact['--shadow-hover']);

        // 非法枚举回退默认。
        $fallback = P::resolve(['theme_density' => 'weird', 'theme_shadow' => 'weird']);
        $this->assertSame('96px', $fallback['--sec-y']);
    }

    public function test_error_red_is_invariant_across_brands(): void
    {
        $a = P::resolve(ThemePresets::tokens('professional'));
        $b = P::resolve(ThemePresets::tokens('commerce'));
        $this->assertSame('#DC2626', $a['--error']);
        $this->assertSame('#DC2626', $b['--error']);
    }

    public function test_invalid_hex_falls_back_to_default_brand(): void
    {
        $t = P::resolve(['theme_primary' => 'not-a-color', 'theme_accent' => '###']);
        $this->assertSame(P::hex(P::deepenForContrast(P::hexToRgb(P::DEFAULTS['brand']))), $t['--brand']);
    }

    public function test_on_color_picks_white_on_dark_and_ink_on_light(): void
    {
        $this->assertSame('#FFFFFF', P::onColor('#0B1220'));
        $this->assertSame('#1A1A1A', P::onColor('#FFFFFF'));
    }

    public function test_mix_endpoints(): void
    {
        $this->assertSame('#000000', P::hex(P::mix('#FFFFFF', '#000000', 1.0)));
        $this->assertSame('#FFFFFF', P::hex(P::mix('#FFFFFF', '#000000', 0.0)));
        $mid = P::mix('#000000', '#FFFFFF', 0.5);
        $this->assertSame(128, $mid[0]);
    }

    public function test_light_resolve_exposes_translucent_header_background(): void
    {
        $t = P::resolve([]);
        $this->assertArrayHasKey('--hd-bg', $t);
        $this->assertStringContainsString('rgba(255,255,255', $t['--hd-bg']);
    }

    public function test_dark_neutral_scale_is_actually_dark(): void
    {
        $d = P::darkOverrides([]);
        $this->assertLessThan(0.05, P::luminance($d['--bg']), '深色底必须足够深');
        $this->assertLessThan(0.10, P::luminance($d['--surface']));
        $this->assertGreaterThan(0.60, P::luminance($d['--ink']), '深色态主文字必须足够亮');
        $this->assertGreaterThan(0.55, P::luminance($d['--ink-2']));
        // 深色与浅色中性阶确实不同。
        $this->assertNotSame(P::resolve([])['--bg'], $d['--bg']);
        $this->assertNotSame(P::resolve([])['--ink'], $d['--ink']);
        $this->assertStringContainsString('rgba(11,18,32', $d['--hd-bg']);
    }

    public function test_dark_brand_accent_and_semantic_colors_meet_aa_on_deep_bg(): void
    {
        $cases = array_merge(
            [[] , ['theme_primary' => '#F59E0B', 'theme_accent' => '#F59E0B']],
            array_values(array_map(fn ($k) => ThemePresets::tokens($k), array_keys((array) config('theme-presets'))))
        );
        foreach ($cases as $i => $settings) {
            $d = P::darkOverrides($settings);
            $bg = $d['--bg'];
            // 深底上的链接 / 文字 / 图标 / 语义色必须可读（AA）。
            foreach (['--brand', '--accent', '--info', '--success', '--error', '--warning'] as $name) {
                $this->assertGreaterThanOrEqual(4.5, $this->contrast($d[$name], $bg), "dark[{$name}] case#{$i} 深底对比不足");
            }
            // 次要文字在卡片底上也须达标。
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($d['--ink-muted'], $d['--surface']), "dark ink-muted case#{$i} 对比不足");
            $this->assertGreaterThanOrEqual(7.0, $this->contrast($d['--ink'], $bg), "dark 主文字 case#{$i} 未达 AAA 级对比");
        }
    }

    public function test_dark_soft_surfaces_are_translucent_and_shadows_deepen(): void
    {
        $d = P::darkOverrides([]);
        foreach (['--brand-soft', '--accent-soft', '--cta-soft', '--brand-ring', '--accent-ring'] as $name) {
            $this->assertStringContainsString('rgba(', $d[$name], "dark[{$name}] 应为半透明");
        }
        $this->assertStringContainsString('rgba(0,0,0', $d['--shadow-hover']);
    }

    public function test_dark_keeps_solid_cta_face_with_white_text(): void
    {
        // 实心主 CTA 沿用浅色派生（与白字 AA），深色态只改其浅底，避免深底上出现刺眼亮绿面。
        $d = P::darkOverrides([]);
        $this->assertArrayNotHasKey('--cta', $d);
        $this->assertArrayNotHasKey('--cta-on', $d);
        $this->assertArrayNotHasKey('--cta-dark', $d);
        $this->assertArrayHasKey('--cta-soft', $d);

        $light = P::resolve([]);
        $this->assertSame('#FFFFFF', $light['--cta-on']);
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($light['--cta-on'], $light['--cta']));
    }

    public function test_lighten_for_contrast_reaches_ratio_and_is_idempotent(): void
    {
        $deep = P::hexToRgb('#0B1220');
        // 已足够亮的颜色保持不变。
        $bright = P::hexToRgb('#9ECBFF');
        $this->assertSame($bright, P::lightenForContrast($bright, $deep));

        // 偏暗的品牌蓝被提亮到深底 AA，且重复调用幂等。
        $once = P::lightenForContrast(P::hexToRgb('#2563EB'), $deep);
        $this->assertGreaterThanOrEqual(4.5, $this->contrast(P::hex($once), '#0B1220'));
        $this->assertSame($once, P::lightenForContrast($once, $deep));
    }
}
