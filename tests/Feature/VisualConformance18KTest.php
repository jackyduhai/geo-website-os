<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\Theme\ThemePalette;
use App\Support\Theme\ThemePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18K：Theme Visual Conformance（主题视觉一致性）。
 *
 * 18D/18E 已证明 Token 体系与前后台能力闭环；本测试把用户在 18K 的配色裁定落到
 * **渲染层**，防止「代码里有 token、前台却混搭」回潮：
 *   - Brand-led + Action-aligned：浅色与深色 :root 中 --action 默认 === --brand，
 *     主按钮面 .btn/.btn-primary/.hd-cta 背景只引用 var(--cta)，绝不引用 var(--accent)
 *     或固定色；--cta 与 --brand 同色相（换品牌 = 主行动一起换）。
 *   - Accent-controlled：--accent 退为小面积点缀，扫描全部 .btn* 规则，断言没有
 *     以 var(--accent) 为背景的主按钮。
 *   - 语义色稳定：--success 固定积极绿、--error 固定红，不随品牌 / 预设 / accent 改变。
 *   - 8 个行业预设逐一在渲染层对账：--brand/--action/--cta/--accent/--success 与
 *     ThemePalette 派生期望逐字节一致；深色态用 elevated 中性阶且 --action 仍对齐品牌。
 *   - 自定义品牌基色自动派生 action/cta，success 语义独立（品牌是 teal 也不混）。
 */
class VisualConformance18KTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        PageCache::flush();
    }

    /** 从内联 CSS 中提取「选择器 { 声明 }」块并解析成 [--token => value]。 */
    private function parseBlock(string $html, string $selector): array
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{(.*?)\}/s';
        preg_match($pattern, $html, $m);
        $this->assertNotEmpty($m, "未找到 CSS 块：{$selector}");

        $tokens = [];
        // 先剥离所有 CSS 注释（含跨行），避免注释粘连到紧随声明的变量名。
        $body = preg_replace('/\/\*.*?\*\//s', '', $m[1]);
        foreach (preg_split('/;/', $body) as $decl) {
            if (strpos($decl, ':') !== false) {
                [$k, $v] = explode(':', $decl, 2);
                $k = trim($k);
                if ($k !== '') {
                    $tokens[$k] = trim($v);
                }
            }
        }

        return $tokens;
    }

    /** 计算 hex 的色相（0–360）。 */
    private function hue(string $hex): int
    {
        [$r, $g, $b] = ThemePalette::hexToRgb($hex);
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d == 0) {
            return 0;
        }
        $h = match (true) {
            $max === $r => 60 * fmod((($g - $b) / $d), 6),
            $max === $g => 60 * (($b - $r) / $d + 2),
            default     => 60 * (($r - $g) / $d + 4),
        };

        return (int) round(($h + 360) % 360);
    }

    private function hueDistance(string $a, string $b): int
    {
        $d = abs($this->hue($a) - $this->hue($b));

        return min($d, 360 - $d);
    }

    private function lightRoot(string $host = 'localhost'): array
    {
        $html = $this->get("http://{$host}/")->assertOk()->getContent();

        return $this->parseBlock($html, ':root');
    }

    public function test_light_root_aligns_action_and_cta_to_brand(): void
    {
        $root = $this->lightRoot();

        $this->assertSame($root['--brand'], $root['--action'], 'Action 默认必须等于 Brand');
        $this->assertSame($root['--brand-on'], $root['--action-on']);
        $this->assertSame($root['--brand-dark'], $root['--action-dark']);

        // CTA 与品牌同色相（允许加深带来的极小漂移）。
        $this->assertLessThanOrEqual(
            15,
            $this->hueDistance($root['--cta'], $root['--brand']),
            "CTA {$root['--cta']} 与 Brand {$root['--brand']} 不同色相"
        );
        $this->assertSame('#FFFFFF', $root['--cta-on']);
    }

    public function test_success_and_error_are_fixed_semantic_colors(): void
    {
        $expected = ThemePalette::resolve([]);
        $root = $this->lightRoot();

        // 成功固定积极绿、错误固定红，不随品牌。
        $this->assertSame($expected['--success'], $root['--success']);
        $this->assertSame('#DC2626', $root['--error']);
        $this->assertSame('#E6A23C', $root['--warning']);
    }

    public function test_primary_button_face_uses_cta_token_and_never_accent(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        // 主按钮面唯一引用 var(--cta)。
        $this->assertStringContainsString(
            '.btn,.btn-primary{background:var(--cta);color:var(--cta-on);border-color:var(--cta);}',
            $html
        );

        // 扫描所有选择器含 btn / hd-cta 的规则，不得出现以 var(--accent) 为背景。
        preg_match_all('/([^{}]*\.(?:btn|hd-cta)[^{}]*)\{([^{}]*)\}/', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, '未找到任何按钮规则');
        foreach ($m as $rule) {
            [$selector, $body] = [$rule[1], $rule[2]];
            if (preg_match('/background(?:-color)?\s*:/', $body)) {
                $this->assertStringNotContainsString(
                    'var(--accent)',
                    $body,
                    '按钮规则以 accent 为背景：' . trim($selector)
                );
            }
        }
    }

    public function test_each_industry_preset_keeps_action_and_cta_aligned_to_its_brand(): void
    {
        foreach (ThemePresets::keys() as $slug) {
            $this->actingAs($this->super)
                ->post(route('admin.settings.preset'), ['preset' => $slug])
                ->assertRedirect();
            PageCache::flush();

            $root = $this->lightRoot();
            $expected = ThemePalette::resolve(ThemePresets::tokens($slug));

            $this->assertSame($expected['--brand'], $root['--brand'], "preset[{$slug}] brand 渲染不一致");
            $this->assertSame($expected['--brand'], $root['--action'], "preset[{$slug}] action 未对齐 brand");
            $this->assertSame($expected['--cta'], $root['--cta'], "preset[{$slug}] cta 渲染不一致");
            $this->assertSame($expected['--accent'], $root['--accent'], "preset[{$slug}] accent 渲染不一致");

            // 语义成功绿在任何预设下保持固定，不被预设 / accent 带走。
            $this->assertSame($expected['--success'], $root['--success'], "preset[{$slug}] success 不应改变");

            // 按钮面规则仍是 var(--cta)。
            $html = $this->get('http://localhost/')->getContent();
            $this->assertStringContainsString('.btn,.btn-primary{background:var(--cta);', $html);
        }
    }

    public function test_dark_root_keeps_action_aligned_on_elevated_neutral(): void
    {
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), ['theme_color_mode' => 'dark'])
            ->assertRedirect();
        PageCache::flush();

        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $dark = $this->parseBlock($html, ':root[data-color-scheme="dark"]');

        // 深色 elevated 中性阶。
        $this->assertSame('#0B1220', $dark['--bg']);
        $this->assertSame('#121A2B', $dark['--surface']);
        // Action 在深色下仍对齐品牌（深底提亮版）。
        $this->assertSame($dark['--brand'], $dark['--action']);

        $expectedDark = ThemePalette::darkOverrides([]);
        $this->assertSame($expectedDark['--success'], $dark['--success'], '深色 success 应为固定绿提亮');

        // 实心 CTA 面不被深色覆盖（沿用浅色品牌同色系 + 白字 AA），dark 块不得重定义 --cta 面。
        $this->assertArrayNotHasKey('--cta', $dark);
        $this->assertArrayNotHasKey('--cta-on', $dark);
    }

    public function test_custom_brand_seed_derives_action_cta_while_success_stays_independent(): void
    {
        // 自定义品牌基色 teal；accent 留空（用出厂蓝灰点缀）。
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), [
                'theme_primary' => '#0D9488',
                'theme_accent'  => '',
            ])
            ->assertRedirect();
        PageCache::flush();

        $root = $this->lightRoot();
        $expected = ThemePalette::resolve(['theme_primary' => '#0D9488', 'theme_accent' => '']);

        $this->assertSame($expected['--brand'], $root['--brand']);
        $this->assertSame($expected['--brand'], $root['--action'], '自定义品牌 action 未派生对齐');
        $this->assertSame($expected['--cta'], $root['--cta']);
        $this->assertLessThanOrEqual(15, $this->hueDistance($root['--cta'], $root['--brand']));

        // 即使品牌基色本身是绿色（teal），语义 success 仍是独立固定绿，不与品牌混淆。
        $this->assertSame($expected['--success'], $root['--success']);
        $this->assertSame('#64748B', $root['--accent'], 'accent 留空应回退出厂蓝灰');
    }
}
