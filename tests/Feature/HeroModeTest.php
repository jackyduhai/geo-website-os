<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Media;
use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首屏双模式（方案 1）：
 * A=价值主张+参数卡（默认）；C=左价值主张+右 Banner 图（图文混排）；B=读取首页顶部 Banner 的大图轮播；
 * C/B 无可用 Banner 时回退 A；任意模式整页只有一个 H1；后台“首页装修”可切换并落库。
 */
class HeroModeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    private function heroBlock(): PageBlock
    {
        return PageBlock::where('page', 'home')->where('type', 'hero')->firstOrFail();
    }

    private function makeBanner(array $attrs = []): Banner
    {
        $media = Media::create([
            'disk' => 'public',
            'path' => 'http://example.com/hero-banner.jpg',
            'mime' => 'image/jpeg',
        ]);

        return Banner::create(array_merge([
            'position'  => 'home_top',
            'image_id'  => $media->id,
            'is_active' => true,
            'sort'      => 0,
            'title'     => '',
            'subtitle'  => '',
        ], $attrs));
    }

    public function test_default_home_renders_mode_a_param_card(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<section class="hero-split', $html);
        $this->assertStringNotContainsString('<section class="hb"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_admin_can_switch_hero_mode_and_it_persists(): void
    {
        $blk = $this->heroBlock();

        $this->actingAs($this->admin)
            ->put("/admin/blocks/{$blk->id}", [
                'sort' => $blk->sort, 'is_active' => 1,
                'hero_mode' => 'B', 'hero_autoplay' => 1,
            ])->assertRedirect();

        $cfg = $blk->fresh()->cfg();
        $this->assertSame('B', $cfg['mode']);
        $this->assertTrue($cfg['autoplay']);
    }

    public function test_mode_b_with_banner_renders_image_carousel_with_single_h1(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'B', 'autoplay' => false], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['title' => '首屏主标题', 'subtitle' => '副标题']);

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<section class="hb"', $html);
        $this->assertStringNotContainsString('<section class="hero-split', $html);
        $this->assertStringContainsString('hero-banner.jpg', $html);
        $this->assertStringContainsString('首屏主标题', $html);
        // 整页唯一 H1，且第一张主标题作为 H1
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('~<h1[^>]*>首屏主标题</h1>~u', $html);
    }

    public function test_mode_b_without_any_banner_falls_back_to_mode_a(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'B'], JSON_UNESCAPED_UNICODE);
        $blk->save();
        // 不创建任何 Banner

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<section class="hero-split', $html);
        $this->assertStringNotContainsString('<section class="hb"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_admin_can_switch_to_mode_c_split_and_it_persists(): void
    {
        $blk = $this->heroBlock();

        $this->actingAs($this->admin)
            ->put("/admin/blocks/{$blk->id}", [
                'sort' => $blk->sort, 'is_active' => 1,
                'hero_mode' => 'C',
            ])->assertRedirect();

        $this->assertSame('C', $blk->fresh()->cfg()['mode']);
    }

    public function test_hero_copy_title_kicker_lead_is_editable_and_renders_in_c(): void
    {
        $blk = $this->heroBlock();
        $this->makeBanner(['title' => '', 'subtitle' => '']);

        $this->actingAs($this->admin)
            ->put("/admin/blocks/{$blk->id}", [
                'sort' => $blk->sort, 'is_active' => 1,
                'hero_mode' => 'C',
                'title' => '可编辑主标题XYZ',
                'subtitle' => '可编辑眉题XYZ',
                'hero_lead' => '可编辑说明正文XYZ',
            ])->assertRedirect();

        $fresh = $blk->fresh();
        $this->assertSame('可编辑主标题XYZ', $fresh->title);
        $this->assertSame('可编辑眉题XYZ', $fresh->subtitle);
        $this->assertSame('可编辑说明正文XYZ', $fresh->cfg()['lead']);

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('可编辑主标题XYZ', $html);
        $this->assertStringContainsString('可编辑眉题XYZ', $html);
        $this->assertStringContainsString('可编辑说明正文XYZ', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_blank_hero_lead_falls_back_to_default_copy(): void
    {
        $blk = $this->heroBlock();
        $this->makeBanner(['title' => '', 'subtitle' => '']);
        $blk->content = json_encode(['mode' => 'C', 'lead' => ''], JSON_UNESCAPED_UNICODE);
        $blk->save();

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('年产能约 1,200 吨', $html); // 默认口径兜底
    }

    public function test_mode_c_split_renders_copy_and_image_together_with_single_h1(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'C'], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['title' => '', 'subtitle' => '']);

        $html = $this->get('/')->getContent();

        // 一体化面板：背景图层 + 文案 + CTA 融合在同一块，且不是全幅轮播、不是左右两个盒子
        $this->assertStringContainsString('class="hi-panel"', $html);
        $this->assertStringContainsString('class="hi-bgstack"', $html);
        // 单图：一张背景层（x-picture 内 img.hi-bg.on）、无圆点；精确匹配 img 类，排除 .hi-bgstack 容器
        $bgCount = substr_count($html, 'class="hi-bg on"') + substr_count($html, 'class="hi-bg"');
        $this->assertSame(1, $bgCount);
        $this->assertStringNotContainsString('class="hi-dots"', $html);
        $this->assertStringContainsString('hero-banner.jpg', $html);
        $this->assertStringNotContainsString('<section class="hb"', $html);
        $this->assertStringNotContainsString('hero-split-media', $html);
        // 价值主张文案在初始 HTML（SSR，利于 SEO/GEO），且整页唯一 H1
        // 18B 行业中性化：mode C 默认标题为品牌名模板（不内置「一站式定制制造」行业话术）
        $this->assertStringContainsString('示例制造 官方网站', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_mode_c_with_multiple_banners_renders_crossfade_slideshow_with_single_h1(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'C', 'autoplay' => true], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['title' => '', 'subtitle' => '']);
        // 第二张首屏图
        $media2 = Media::create(['disk' => 'public', 'path' => 'http://example.com/hero-slide2.jpg', 'mime' => 'image/jpeg']);
        Banner::create(['position' => 'home_top', 'image_id' => $media2->id, 'is_active' => true, 'sort' => 1, 'title' => '', 'subtitle' => '']);

        $html = $this->get('/')->getContent();

        // 两张背景图层叠（x-picture 内各一张 img.hi-bg）+ 圆点切换器 + 自动播放标记，文字/CTA 固定（仍只有一个 H1）
        $bgCount = substr_count($html, 'class="hi-bg on"') + substr_count($html, 'class="hi-bg"');
        $this->assertSame(2, $bgCount);
        $this->assertStringContainsString('class="hi-dots"', $html);
        $this->assertStringContainsString('data-autoplay="1"', $html);
        $this->assertStringContainsString('hero-slide2.jpg', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_mode_c_single_banner_has_no_dots_or_script(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'C'], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['title' => '', 'subtitle' => '']);

        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('class="hi-dots"', $html); // 单图不出圆点/轮播脚本
    }

    public function test_mode_c_without_banner_falls_back_to_mode_a(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'C'], JSON_UNESCAPED_UNICODE);
        $blk->save();

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<section class="hero-split', $html);
        $this->assertStringNotContainsString('hero-split-media', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_mode_c_each_slide_carries_its_own_copy_with_single_h1(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'C', 'autoplay' => false], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['sort' => 0, 'title' => '第一张主题AAA', 'subtitle' => '第一张正文AAA', 'link_text' => '看车间']);
        $media2 = Media::create(['disk' => 'public', 'path' => 'http://example.com/hero-slide2.jpg', 'mime' => 'image/jpeg']);
        Banner::create(['position' => 'home_top', 'image_id' => $media2->id, 'is_active' => true, 'sort' => 1,
            'title' => '第二张主题BBB', 'subtitle' => '第二张正文BBB']);

        $html = $this->get('/')->getContent();

        // 图文一起切换：两套文案层都在初始 HTML
        $this->assertStringContainsString('class="hi-copystack"', $html);
        $this->assertStringContainsString('第一张主题AAA', $html);
        $this->assertStringContainsString('第二张主题BBB', $html);
        $this->assertStringContainsString('第一张正文AAA', $html);
        $this->assertStringContainsString('第二张正文BBB', $html);
        $this->assertStringContainsString('看车间', $html);
        // 仅第一张是 H1，第二张用 div.hi-title，整页唯一 H1
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('~<h1[^>]*>第一张主题AAA</h1>~u', $html);
        $this->assertMatchesRegularExpression('~<div class="hi-title">第二张主题BBB</div>~u', $html);
    }

    public function test_admin_inline_slides_can_update_create_and_remove(): void
    {
        $blk = $this->heroBlock();
        $existing = $this->makeBanner(['sort' => 0, 'title' => '旧标题', 'subtitle' => '旧正文']);

        $this->actingAs($this->admin)
            ->put("/admin/blocks/{$blk->id}", [
                'sort' => $blk->sort, 'is_active' => 1, 'hero_mode' => 'C',
                'subtitle' => '全局眉题',
                'slides' => [
                    $existing->id => ['id' => $existing->id, 'title' => '改后标题', 'subtitle' => '改后正文',
                        'link' => '/cooperation/', 'link_text' => '去合作', 'sort' => 0, 'is_active' => 1],
                ],
                'new_slides' => [
                    0 => ['title' => '新增幻灯', 'subtitle' => '新增正文',
                        'image_file' => \Illuminate\Http\UploadedFile::fake()->image('new.jpg', 1200, 500)],
                ],
            ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('改后标题', $existing->title);
        $this->assertSame('改后正文', $existing->subtitle);
        $this->assertSame('/cooperation/', $existing->link);
        $this->assertSame('去合作', $existing->link_text);
        $this->assertSame(1, Banner::where('position', 'home_top')->where('title', '新增幻灯')->count());

        // 删除：勾 remove 后该幻灯被删
        $newId = Banner::where('position', 'home_top')->where('title', '新增幻灯')->firstOrFail()->id;
        $this->actingAs($this->admin)->put("/admin/blocks/{$blk->id}", [
            'sort' => $blk->sort, 'is_active' => 1, 'hero_mode' => 'C',
            'slides' => [
                $existing->id => ['id' => $existing->id, 'title' => '改后标题', 'sort' => 0, 'is_active' => 1],
                $newId => ['id' => $newId, 'remove' => 1],
            ],
        ])->assertRedirect();
        $this->assertNull(Banner::find($newId));
        $this->assertNotNull(Banner::find($existing->id));
    }

    public function test_mode_b_textless_banner_keeps_single_sr_only_h1_and_no_overlay(): void
    {
        $blk = $this->heroBlock();
        $blk->content = json_encode(['mode' => 'B'], JSON_UNESCAPED_UNICODE);
        $blk->save();
        $this->makeBanner(['title' => '', 'subtitle' => '']); // 图片自带文字，主副标题留空

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<section class="hb"', $html);
        $this->assertStringNotContainsString('<div class="hb-overlay"', $html); // 不渲染压字层（CSS 类名仍在样式表中）
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('class="sr-only"', $html);
    }
}
