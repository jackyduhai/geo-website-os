<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Components\ComponentRegistry;
use App\Support\Components\ComponentResolver;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-2a：Component Design System（Registry 契约 + 变体 + 字号消费）。
 *
 * 防止「组件变体回潮为散落类名 / 内联 px 字号」：
 *   - ComponentRegistry 为单一事实源：button / card / hero / section 声明式加载，
 *     变体映射到已注册 CSS 类 + token，并声明语义根标签与 GEO 元数据。
 *   - ComponentResolver 是视图唯一入口：组件 + 变体 + 尺寸 → 类名字符串。
 *   - 视图内联字号一律消费 var(--fs-*)，随 typography profile 变化，不再写 px。
 */
class ComponentDesignSystem18L2aTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        ComponentRegistry::flush();
        PageCache::flush();
    }

    public function test_registry_loads_four_core_components(): void
    {
        foreach (['button', 'card', 'hero', 'section'] as $key) {
            $this->assertTrue(ComponentRegistry::has($key), "缺组件 {$key}");
        }
        $this->assertContains('loading', ComponentRegistry::states());
    }

    public function test_button_has_five_variants_with_registered_classes(): void
    {
        $button = ComponentRegistry::get('button');

        $this->assertSame('primary', $button->defaultVariant);
        $expected = [
            'primary'   => 'btn btn-primary',
            'secondary' => 'btn-o btn-secondary',
            'outline'   => 'btn btn-outline',
            'ghost'     => 'btn btn-ghost',
            'text'      => 'btn-text',
        ];
        foreach ($expected as $variant => $classes) {
            $this->assertSame($classes, $button->variant($variant)->classes, "button[{$variant}]");
        }
    }

    public function test_card_has_five_visual_variants(): void
    {
        $card = ComponentRegistry::get('card');

        foreach (['default', 'border', 'shadow', 'glass', 'minimal'] as $variant) {
            $this->assertTrue($card->hasVariant($variant), "card 缺变体 {$variant}");
        }
        $this->assertSame('card', $card->variant('default')->classes);
        $this->assertStringContainsString('card-glass', $card->variant('glass')->classes);
        $this->assertStringContainsString('card-minimal', $card->variant('minimal')->classes);
    }

    public function test_hero_has_five_variants(): void
    {
        $hero = ComponentRegistry::get('hero');

        $expected = [
            'default' => 'hero',
            'split'   => 'hero-split',
            'center'  => 'hero-center',
            'image'   => 'hb',
            'product' => 'hero-int',
        ];
        foreach ($expected as $variant => $classes) {
            $this->assertSame($classes, $hero->variant($variant)->classes, "hero[{$variant}]");
        }
    }

    public function test_section_has_three_variants(): void
    {
        $section = ComponentRegistry::get('section');

        $this->assertSame('sec', $section->variant('default')->classes);
        $this->assertStringContainsString('sec-wide', $section->variant('wide')->classes);
        $this->assertStringContainsString('sec-compact', $section->variant('compact')->classes);
    }

    public function test_resolver_resolves_classes_size_and_extra(): void
    {
        $this->assertSame('btn btn-primary', ComponentResolver::classes('button'));
        $this->assertSame(
            'btn btn-primary btn-lg',
            ComponentResolver::classes('button', 'primary', 'lg')
        );
        $this->assertSame(
            'btn btn-ghost extra-one extra-two',
            ComponentResolver::classes('button', 'ghost', null, ['extra-one', 'extra-two'])
        );
    }

    public function test_resolver_falls_back_for_unknown_component_or_variant(): void
    {
        // 未知组件：不报错，仅返回 extra。
        $this->assertSame('keep', ComponentResolver::classes('nope', null, null, 'keep'));
        // 未知变体：回退默认变体。
        $this->assertSame('btn btn-primary', ComponentResolver::classes('button', 'does-not-exist'));
    }

    public function test_components_declare_semantic_roots_and_geo_meta(): void
    {
        $button = ComponentRegistry::get('button');
        $this->assertTrue($button->allowsRoot('button'));
        $this->assertTrue($button->allowsRoot('a'));
        $this->assertFalse($button->allowsRoot('div'));
        $this->assertSame('cta', $button->meta['type']);
        $this->assertSame('conversion', $button->meta['purpose']);

        $hero = ComponentRegistry::get('hero');
        $this->assertTrue($hero->allowsRoot('section'));
        $this->assertTrue($hero->meta['entity_support']);
    }

    public function test_search_view_consumes_fs_tokens(): void
    {
        $html = $this->get('http://localhost/search')->assertOk()->getContent();

        $this->assertStringContainsString('font-size:var(--fs-sm)', $html);
        $this->assertStringContainsString('font-size:var(--fs-xs)', $html);
        // search 自身内联 px 字号清零（整页主 CSS 的响应式 px 属有意保留，
        // 已由 test_no_blade_under_site_uses_inline_px_font_size 按源文件覆盖）。
    }

    public function test_no_blade_under_site_uses_inline_px_font_size(): void
    {
        $dir = resource_path('views/site');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($it as $file) {
            if ($file->getExtension() !== 'blade.php' && $file->getFilename() !== 'consent-banner.blade.php') {
                // blade.php 扩展在 getExtension 下返回 'php'，故下面用文件名兜底。
            }
            if (substr($file->getFilename(), -10) === '.blade.php'
                && preg_match('/font-size:\s*[0-9.]+px/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, '仍有 blade 使用内联 px 字号：' . implode('; ', $offenders));
    }

    public function test_search_view_font_tokens_follow_typography_profile(): void
    {
        // compact profile：--fs-sm 缩小；search 输入框消费 var(--fs-sm) 自动跟随。
        $this->actingAs($this->super)
            ->put(route('admin.settings.update', 'theme'), ['theme_typography' => 'compact'])
            ->assertRedirect();
        PageCache::flush();

        $html = $this->get('http://localhost/search')->assertOk()->getContent();

        preg_match('/:root\s*\{(.*?)\}/s', $html, $m);
        $this->assertStringContainsString('--fs-sm: 0.875rem', $m[1]);
        $this->assertStringContainsString('font-size:var(--fs-sm)', $html);
    }
}
