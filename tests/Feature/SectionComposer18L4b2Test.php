<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-4b-2 — Section Composer Lite（TD-133，吸收 TD-119）防回归。
 *
 * 验证「企业用户零代码、安全地调整页面区块」的八类契约：
 *   1 新增已有 Block；2 删除 Block；3 区块排序；4 Hero variant 切换；
 *   5 非法 Block（system）阻断；6 白名单外 variant（非法语义）阻断；
 *   7 variant 切换不改 canonical（SEO）；8 variant 切换不改 GEO 语义字段。
 *
 * 边界：不做自由拖拽 / 在线 HTML / 自定义 CSS·JS；variant 切换只改布局，
 * 不改 H1 / 结构 / SEO / GEO。
 */
class SectionComposer18L4b2Test extends TestCase
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
        SiteContext::setSite($this->default);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    private function makePage(string $slug, array $attrs = []): Page
    {
        return Page::create(array_merge([
            'site_id'  => $this->default->id,
            'template' => 'landing',
            'slug'     => $slug,
            'title'    => '组合页',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ], $attrs));
    }

    private function makeHero(Page $page, string $variant = 'split', int $sort = 0): PageBlock
    {
        return PageBlock::create([
            'site_id'   => $page->site_id,
            'page'      => 'page',
            'page_id'   => $page->id,
            'slot'      => 'main',
            'type'      => 'hero',
            'sort'      => $sort,
            'is_active' => true,
            'content'   => json_encode([
                'variant' => $variant,
                'title'   => '品牌首屏标题',
                'subtitle' => '品牌副标题',
                'buttons' => [],
                'image_id' => null,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    // 1) 新增已有 Block -------------------------------------------------

    public function test_add_existing_block_to_slot(): void
    {
        $page = $this->makePage('add-block');

        $this->actingAs($this->super)
            ->post(route('admin.pages.storeBlock', $page), [
                'type' => 'stats',
                'slot' => 'main',
            ])->assertRedirect();

        $this->assertDatabaseHas('page_blocks', [
            'page_id' => $page->id,
            'type'    => 'stats',
            'slot'    => 'main',
        ]);
    }

    // 2) 删除 Block -----------------------------------------------------

    public function test_delete_block(): void
    {
        $page = $this->makePage('delete-block');
        $hero = $this->makeHero($page);

        $this->actingAs($this->super)
            ->delete(route('admin.pages.destroyBlock', [$page, $hero]))
            ->assertRedirect();

        $this->assertDatabaseMissing('page_blocks', ['id' => $hero->id]);
    }

    // 3) 区块排序 -------------------------------------------------------

    public function test_reorder_blocks_with_move(): void
    {
        $page = $this->makePage('reorder-block');
        $hero = $this->makeHero($page, 'split', 0);
        $cta = PageBlock::create([
            'site_id' => $page->site_id, 'page' => 'page', 'page_id' => $page->id,
            'slot' => 'main', 'type' => 'cta', 'sort' => 1, 'is_active' => true,
            'content' => json_encode(['title' => 'CTA'], JSON_UNESCAPED_UNICODE),
        ]);

        // CTA 上移：与 hero 交换 sort
        $this->actingAs($this->super)
            ->post(route('admin.pages.moveBlock', [$page, $cta, 'up']))
            ->assertRedirect();

        $this->assertSame(0, (int) PageBlock::find($cta->id)->sort);
        $this->assertSame(1, (int) PageBlock::find($hero->id)->sort);
    }

    // 4) Hero variant 切换 ---------------------------------------------

    public function test_switch_hero_variant_changes_layout_only(): void
    {
        $page = $this->makePage('variant-switch');
        $hero = $this->makeHero($page, 'split');

        $this->actingAs($this->super)
            ->post(route('admin.pages.setVariant', [$page, $hero]), [
                'variant' => 'center',
            ])->assertRedirect();

        $this->assertSame('center', PageBlock::find($hero->id)->cfg()['variant']);

        $html = $this->get('/variant-switch')->getContent();
        $this->assertStringContainsString('class="hero-center', $html);
        $this->assertStringNotContainsString('class="hero-split', $html);
        // H1 与文案不变
        $this->assertStringContainsString('品牌首屏标题', $html);
    }

    // 5) 非法 Block（system）阻断 --------------------------------------

    public function test_system_block_cannot_be_added(): void
    {
        $page = $this->makePage('system-block');

        $this->actingAs($this->super)
            ->post(route('admin.pages.storeBlock', $page), [
                'type' => 'entity_hero',
                'slot' => 'main',
            ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('page_blocks', [
            'page_id' => $page->id,
            'type'    => 'entity_hero',
        ]);
    }

    // 6) 白名单外 variant（非法语义）阻断 ------------------------------

    public function test_variant_outside_whitelist_is_blocked(): void
    {
        $page = $this->makePage('illegal-variant');
        $hero = $this->makeHero($page, 'split');

        // 'product' 虽在组件层注册，但 CompositionPolicy v1.0 仅暴露 split/center
        $this->actingAs($this->super)
            ->post(route('admin.pages.setVariant', [$page, $hero]), [
                'variant' => 'product',
            ])->assertSessionHasErrors('variant');

        // content 未被改动，仍为 split
        $this->assertSame('split', PageBlock::find($hero->id)->cfg()['variant']);
    }

    // 7) variant 切换不改 canonical（SEO） -----------------------------

    public function test_variant_switch_keeps_canonical_seo(): void
    {
        $page = $this->makePage('seo-stable');
        $hero = $this->makeHero($page, 'split');

        $before = $this->get('/seo-stable')->getContent();

        $this->actingAs($this->super)
            ->post(route('admin.pages.setVariant', [$page, $hero]), [
                'variant' => 'center',
            ])->assertRedirect();

        $after = $this->get('/seo-stable')->getContent();

        $canonical = '<link rel="canonical" href="http://localhost/seo-stable"';
        $this->assertStringContainsString($canonical, $before);
        $this->assertStringContainsString($canonical, $after);
    }

    // 8) variant 切换不改 GEO 语义字段 ---------------------------------

    public function test_variant_switch_keeps_geo_semantic_fields(): void
    {
        $page = $this->makePage('geo-stable');
        $hero = $this->makeHero($page, 'split');

        $before = $this->get('/geo-stable')->getContent();

        $this->actingAs($this->super)
            ->post(route('admin.pages.setVariant', [$page, $hero]), [
                'variant' => 'center',
            ])->assertRedirect();

        $after = $this->get('/geo-stable')->getContent();

        // 根 section 的语义四元组不随布局变化（section→purpose→entity 顺序固定）
        $pattern = '/<section class="hero-(split|center)[^>]*data-section="hero"'
            .'[^>]*data-purpose="brand"[^>]*data-entity="Organization"/';

        $this->assertMatchesRegularExpression($pattern, $before);
        $this->assertMatchesRegularExpression($pattern, $after);
    }
}
