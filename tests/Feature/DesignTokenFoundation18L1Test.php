<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PageCache;
use App\Support\Theme\ThemePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-1：Design Token Foundation（Typography / Inverse / Locale）。
 *
 * 把用户在 18L-1 的裁定落到渲染层，防止「字号 / 反白写死」回潮：
 *   - Typography profile（theme_typography：standard/compact/editorial）：字号 scale、
 *     标题 / 正文行高从 ThemePalette 派生，:root 不再硬编码字号；h1–4 / body 消费
 *     --lh-heading/--lh-body、--fw-*、--ls-*。
 *   - Inverse token（TD-114）：反白文字 / 边框 / ghost hover 统一走 --on-inverse /
 *     --inverse-line / --inverse-hover，组件不再直写 #fff / rgba(255,255,255,...)。
 *   - Locale typography（TD-120）：html[lang="en"] 切换拉丁字体（--font-en）并在桌面
 *     精修 h1 字号 / 行高 / 字距，消除长标题孤字。
 */
class DesignTokenFoundation18L1Test extends TestCase
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

    /** 从内联 CSS 中提取「选择器 { 声明 }」块并解析成 [property => value]。 */
    private function parseBlock(string $html, string $selector): array
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{(.*?)\}/s';
        preg_match($pattern, $html, $m);
        $this->assertNotEmpty($m, "未找到 CSS 块：{$selector}");

        $tokens = [];
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

    private function rootTokens(): array
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        return $this->parseBlock($html, ':root');
    }

    public function test_standard_profile_emits_full_typography_tokens(): void
    {
        $root = $this->rootTokens();

        $this->assertSame('2.75rem', $root['--fs-h1']);
        $this->assertSame('1rem', $root['--fs-base']);
        $this->assertSame('1.75', $root['--lh-body']);
        $this->assertSame('1.25', $root['--lh-heading']);
        $this->assertSame('700', $root['--fw-bold']);
        $this->assertSame('500', $root['--fw-medium']);
        $this->assertSame('-.01em', $root['--ls-snug']);
        $this->assertArrayHasKey('--font-en', $root);
        $this->assertArrayHasKey('--fs-display', $root);
    }

    public function test_compact_profile_shrinks_scale_and_line_heights(): void
    {
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), ['theme_typography' => 'compact'])
            ->assertRedirect();
        PageCache::flush();

        $root = $this->rootTokens();
        $this->assertSame('2.25rem', $root['--fs-h1']);
        $this->assertSame('0.9375rem', $root['--fs-base']);
        $this->assertSame('1.55', $root['--lh-body']);
        $this->assertSame('1.2', $root['--lh-heading']);
    }

    public function test_editorial_profile_expands_scale_and_relaxes_body(): void
    {
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), ['theme_typography' => 'editorial'])
            ->assertRedirect();
        PageCache::flush();

        $root = $this->rootTokens();
        $this->assertSame('3rem', $root['--fs-h1']);
        $this->assertSame('1.0625rem', $root['--fs-base']);
        $this->assertSame('1.8', $root['--lh-body']);
        $this->assertSame('1.12', $root['--lh-heading']);
    }

    public function test_typography_scale_is_a_pure_complete_map(): void
    {
        $keys = ['display', 'h1', 'h2', 'h3', 'h4', 'lg', 'base', 'sm', 'xs', '2xs', 'label', 'button'];

        foreach (['standard', 'compact', 'editorial'] as $profile) {
            $scale = ThemePalette::typographyScale($profile);
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $scale, "profile[{$profile}] 缺字号档 {$k}");
            }
        }

        // 非法 profile 回退 standard。
        $this->assertSame(ThemePalette::typographyScale('standard'), ThemePalette::typographyScale('nope'));
    }

    public function test_inverse_tokens_are_emitted(): void
    {
        $root = $this->rootTokens();

        $this->assertSame('#0B1220', $root['--surface-inverse']);
        $this->assertSame('#FFFFFF', $root['--on-inverse']);
        $this->assertSame('rgba(255,255,255,.9)', $root['--on-inverse-soft']);
        $this->assertSame('rgba(255,255,255,.62)', $root['--on-inverse-faint']);
        $this->assertSame('rgba(255,255,255,.15)', $root['--inverse-line']);
        $this->assertSame('rgba(255,255,255,.4)', $root['--inverse-line-strong']);
        $this->assertSame('rgba(255,255,255,.10)', $root['--inverse-hover']);
        $this->assertSame('rgba(255,255,255,.20)', $root['--inverse-hover-strong']);
    }

    public function test_components_reference_inverse_tokens_instead_of_raw_white(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        // 组件直写白色（应全部由 inverse token 承载；token 定义本身不带这些前缀）。
        foreach ([
            'color:#fff', 'color:#ffffff', 'color: #fff',
            'background:rgba(255,255,255',
            'border-color:rgba(255,255,255',
            'solid rgba(255,255,255',
            'solid #fff',
        ] as $raw) {
            $this->assertStringNotContainsString($raw, $html, "组件仍直写白色：{$raw}");
        }

        $this->assertStringContainsString('var(--on-inverse)', $html);
        $this->assertStringContainsString('var(--inverse-line)', $html);
        $this->assertStringContainsString('var(--inverse-hover)', $html);
    }

    public function test_headings_and_body_consume_typography_tokens(): void
    {
        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString(
            'h1,h2,h3,h4{line-height:var(--lh-heading);color:var(--ink);margin:0;'
            . 'font-weight:var(--fw-bold);letter-spacing:var(--ls-snug);}',
            $html
        );
        $this->assertStringContainsString('line-height:var(--lh-body)', $html);
    }

    public function test_english_locale_sets_lang_latin_font_and_desktop_title_refine(): void
    {
        // 启用 en。
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'general'), [
                'site_supported_locales' => ['zh-CN', 'en'],
            ])
            ->assertRedirect();
        PageCache::flush();

        $html = $this->get('http://localhost/en')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en"', $html);
        // 拉丁字体切换。
        $this->assertStringContainsString('html[lang="en"]{--font:var(--font-en);}', $html);
        // 桌面 h1 精修（消除孤字）。
        $this->assertStringContainsString('--fs-h1:2.35rem', $html);
        $this->assertStringContainsString('--lh-heading:1.16', $html);
    }
}
