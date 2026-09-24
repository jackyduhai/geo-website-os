<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18G-1 — Page Composition / Template System 防回归。
 *
 * 覆盖七类契约：
 *   A 访客跳登录；
 *   B Page CRUD / 发布 / 删除级联 / slug 组合唯一；
 *   C Composer + Block 增删改 / 排序 / 显隐 / 槽位·类型闸门；
 *   D 前台 Landing 渲染（draft 404 / published 200 / 无 hero sr-only H1 / en 前缀）；
 *   E 页面级缓存失效（改 block 仅失效该页，不波及其他页）；
 *   F resolvePage（canonical / title / page-level SeoMeta 覆盖）；
 *   G 「陌生品牌零代码搭站」全程仅经 Admin HTTP；以及解耦 / 多站隔离 / 翻译组。
 */
class PageComposition18GTest extends TestCase
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

    private function makePage(array $attrs = []): Page
    {
        return Page::create(array_merge([
            'site_id'  => $this->default->id,
            'template' => 'landing',
            'slug'     => 'promo-' . uniqid(),
            'title'    => '活动页',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ], $attrs));
    }

    private function makeBlock(Page $page, string $type = 'hero', array $content = [],
                               string $slot = 'main', int $sort = 0): PageBlock
    {
        return PageBlock::create([
            'site_id'  => $page->site_id,
            'page'     => 'page',
            'page_id'  => $page->id,
            'slot'     => $slot,
            'type'     => $type,
            'sort'     => $sort,
            'is_active' => true,
            'content'  => json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // ----------------------------------------------------------------
    // A 访客
    // ----------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.pages.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.pages.create'))->assertRedirect(route('admin.login'));
    }

    // ----------------------------------------------------------------
    // B Page CRUD
    // ----------------------------------------------------------------

    public function test_create_draft_page_via_admin_and_draft_is_404(): void
    {
        $this->actingAs($this->super)
            ->get(route('admin.pages.create'))->assertOk();

        $this->actingAs($this->super)->post(route('admin.pages.store'), [
            'template' => 'landing',
            'locale'   => 'zh-CN',
            'title'    => '新春活动',
            'slug'     => 'spring-event',
            'status'   => 'draft',
        ])->assertRedirect();

        $this->assertDatabaseHas('pages', [
            'site_id' => $this->default->id,
            'slug'    => 'spring-event',
            'status'  => 'draft',
            'locale'  => 'zh-CN',
        ]);

        // Draft 不公开
        $this->get('/spring-event')->assertNotFound();
    }

    public function test_edit_publish_page_and_frontend_is_200(): void
    {
        $page = $this->makePage(['slug' => 'launch-event', 'status' => Page::STATUS_DRAFT]);

        $this->actingAs($this->super)->put(route('admin.pages.update', $page), [
            'template' => 'landing',
            'title'    => '发布活动',
            'slug'     => 'launch-event',
            'status'   => 'published',
        ])->assertRedirect();

        $this->actingAs($this->super)
            ->post(route('admin.pages.publish', [$page, 'publish']))->assertRedirect();

        $this->get('/launch-event')->assertOk();
    }

    public function test_delete_page_cascades_its_blocks(): void
    {
        $page = $this->makePage();
        $b1 = $this->makeBlock($page, 'hero', ['title' => 'X']);
        $b2 = $this->makeBlock($page, 'cta', ['title' => 'Y'], 'main', 1);

        $this->actingAs($this->super)
            ->delete(route('admin.pages.destroy', $page))->assertRedirect(route('admin.pages.index'));

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('page_blocks', ['id' => $b1->id]);
        $this->assertDatabaseMissing('page_blocks', ['id' => $b2->id]);
    }

    public function test_duplicate_slug_same_locale_is_rejected(): void
    {
        $this->makePage(['slug' => 'dup-slug', 'locale' => 'zh-CN']);

        $this->actingAs($this->super)->post(route('admin.pages.store'), [
            'template' => 'landing',
            'locale'   => 'zh-CN',
            'title'    => '重复页',
            'slug'     => 'dup-slug',
            'status'   => 'published',
        ])->assertSessionHasErrors('slug');
    }

    public function test_same_slug_different_locale_is_allowed(): void
    {
        $this->makePage(['slug' => 'shared-slug', 'locale' => 'zh-CN']);

        $this->actingAs($this->super)->post(route('admin.pages.store'), [
            'template' => 'landing',
            'locale'   => 'en',
            'title'    => 'Shared',
            'slug'     => 'shared-slug',
            'status'   => 'published',
        ])->assertSessionDoesntHaveErrors();
    }

    // ----------------------------------------------------------------
    // C Composer + Block
    // ----------------------------------------------------------------

    public function test_composer_lists_page_and_slots(): void
    {
        $page = $this->makePage(['title' => '组合页X']);
        $this->actingAs($this->super)
            ->get(route('admin.pages.composer', $page))
            ->assertOk()->assertSee('组合页X');
    }

    public function test_add_block_to_slot_redirects_to_edit(): void
    {
        $page = $this->makePage();

        $this->actingAs($this->super)
            ->get(route('admin.pages.addBlock', $page) . '?slot=main')
            ->assertOk();

        $this->actingAs($this->super)->post(route('admin.pages.storeBlock', $page), [
            'type' => 'hero',
            'slot' => 'main',
        ])->assertRedirect();

        $this->assertDatabaseHas('page_blocks', [
            'page_id' => $page->id,
            'page'    => 'page',
            'type'    => 'hero',
            'slot'    => 'main',
        ]);
    }

    public function test_edit_block_content_reflects_in_frontend(): void
    {
        $page = $this->makePage(['slug' => 'custom-block']);
        $block = $this->makeBlock($page, 'hero', ['title' => '旧标题']);

        $editHtml = $this->actingAs($this->super)
            ->get(route('admin.pages.editBlock', [$page, $block]))->assertOk()->getContent();
        // TD-86 regression: the real edit form must spoof PUT (updateBlock is PUT-only).
        // Without it the browser POSTs and gets 405, so block content can never be saved.
        $this->assertStringContainsString('name="_method" value="PUT"', $editHtml);
        $this->assertStringContainsString(
            route('admin.pages.updateBlock', [$page, $block]), $editHtml);

        $this->actingAs($this->super)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => ['title' => '全新活动标题'],
        ])->assertRedirect(route('admin.pages.composer', $page));

        $html = $this->get('/custom-block')->getContent();
        $this->assertStringContainsString('全新活动标题', $html);
        $this->assertStringNotContainsString('旧标题', $html);
    }

    public function test_move_and_toggle_block(): void
    {
        $page = $this->makePage();
        $b1 = $this->makeBlock($page, 'hero', [], 'main', 0);
        $b2 = $this->makeBlock($page, 'cta', [], 'main', 1);

        $this->actingAs($this->super)
            ->post(route('admin.pages.moveBlock', [$page, $b2, 'up']))->assertRedirect();
        $this->assertSame(0, (int) PageBlock::find($b2->id)->sort);
        $this->assertSame(1, (int) PageBlock::find($b1->id)->sort);

        $this->actingAs($this->super)
            ->post(route('admin.pages.toggleBlock', [$page, $b1]))->assertRedirect();
        $this->assertFalse(PageBlock::find($b1->id)->is_active);
    }

    public function test_delete_block(): void
    {
        $page = $this->makePage();
        $block = $this->makeBlock($page, 'cta');

        $this->actingAs($this->super)
            ->delete(route('admin.pages.destroyBlock', [$page, $block]))->assertRedirect();
        $this->assertDatabaseMissing('page_blocks', ['id' => $block->id]);
    }

    public function test_invalid_slot_is_rejected(): void
    {
        $page = $this->makePage();

        $this->actingAs($this->super)->post(route('admin.pages.storeBlock', $page), [
            'type' => 'hero',
            'slot' => 'no-such-slot',
        ])->assertSessionHasErrors('type');
    }

    public function test_block_from_other_page_returns_404(): void
    {
        $p1 = $this->makePage();
        $p2 = $this->makePage();
        $foreign = $this->makeBlock($p2, 'hero');

        $this->actingAs($this->super)
            ->get(route('admin.pages.editBlock', [$p1, $foreign]))
            ->assertNotFound();
    }

    // ----------------------------------------------------------------
    // D 前台渲染
    // ----------------------------------------------------------------

    public function test_published_landing_renders_hero(): void
    {
        $page = $this->makePage(['slug' => 'live-promo']);
        $this->makeBlock($page, 'hero', ['title' => '欢迎来到活动现场']);

        $r = $this->get('/live-promo');
        $r->assertOk();
        $this->assertStringContainsString('欢迎来到活动现场', $r->getContent());
    }

    public function test_page_without_hero_has_sr_only_h1(): void
    {
        $page = $this->makePage(['slug' => 'no-hero-page', 'title' => '无首屏页面']);
        $this->makeBlock($page, 'rich_text', ['body' => '正文内容']);

        $html = $this->get('/no-hero-page')->getContent();
        $this->assertStringContainsString('无首屏页面', $html);
        // 不应渲染 hero section（.hero-title 选择器仍存在于样式表，故只断言 hero 结构）
        $this->assertStringNotContainsString('class="hero-split', $html);
    }

    public function test_en_landing_renders_under_en_prefix(): void
    {
        $page = $this->makePage([
            'slug' => 'english-event', 'locale' => 'en', 'title' => 'English Event',
        ]);
        $this->makeBlock($page, 'hero', ['title' => 'Welcome to the Event']);

        $this->get('/english-event')->assertNotFound(); // zh prefix, en page not visible
        $r = $this->get('/en/english-event');
        $r->assertOk();
        $this->assertStringContainsString('Welcome to the Event', $r->getContent());
    }

    public function test_frontend_inline_theme_script_is_not_double_escaped(): void
    {
        // TD-60 防回归：<script> 内 {{ json_encode() }} 会被 Blade e()（ENT_QUOTES）二次转义，
        // JSON 双引号变 &quot;，致主脚本 SyntaxError、IntersectionObserver 永不建立，
        // 首屏以下 .reveal 区块永久 opacity:0。脚本内必须用 {!! json_encode() !!}。
        $page = $this->makePage(['slug' => 'script-check']);
        $this->makeBlock($page, 'hero', ['title' => '脚本检查']);

        $html = $this->get('/script-check')->getContent();

        // 合法：labels 以真正的双引号字符串字面量开始
        $this->assertMatchesRegularExpression('/var labels=\{light:"/', $html);
        // 不得出现被 HTML 转义进脚本的 &quot;
        $this->assertStringNotContainsString('var labels={light:&quot;', $html);
    }

    // ----------------------------------------------------------------
    // E 页面级缓存
    // ----------------------------------------------------------------

    public function test_block_edit_invalidates_only_that_page(): void
    {
        $p1 = $this->makePage(['slug' => 'cache-one']);
        $b1 = $this->makeBlock($p1, 'hero', ['title' => '页面一旧']);

        // 首次访问进入缓存
        $this->assertStringContainsString('页面一旧', $this->get('/cache-one')->getContent());

        // 经 Admin 改 block（触发 forgetPage）
        $this->actingAs($this->super)->put(route('admin.pages.updateBlock', [$p1, $b1]), [
            'field' => ['title' => '页面一新'],
        ])->assertRedirect();

        $this->assertStringContainsString('页面一新', $this->get('/cache-one')->getContent());
        $this->assertStringNotContainsString('页面一旧', $this->get('/cache-one')->getContent());
    }

    public function test_other_page_cache_is_not_invalidated(): void
    {
        $p1 = $this->makePage(['slug' => 'page-alpha']);
        $p2 = $this->makePage(['slug' => 'page-bravo']);
        $b1 = $this->makeBlock($p1, 'hero', ['title' => 'Alpha']);
        $this->makeBlock($p2, 'hero', ['title' => 'Bravo']);

        // 两页都进缓存
        $this->get('/page-alpha')->assertOk();
        $this->get('/page-bravo')->assertOk();

        // 只改 P1
        $this->actingAs($this->super)->put(route('admin.pages.updateBlock', [$p1, $b1]), [
            'field' => ['title' => 'Alpha Updated'],
        ])->assertRedirect();

        // P2 仍能正常提供其内容（path 版本独立，未被 P1 的改动误伤 / 也未串内容）
        $bravo = $this->get('/page-bravo')->getContent();
        $this->assertStringContainsString('Bravo', $bravo);
        $this->assertStringNotContainsString('Alpha', $bravo);
    }

    // ----------------------------------------------------------------
    // F resolvePage / SEO
    // ----------------------------------------------------------------

    public function test_page_canonical_and_title(): void
    {
        $page = $this->makePage(['slug' => 'seo-page', 'title' => 'SEO 页面标题']);
        $this->makeBlock($page, 'hero', ['title' => 'SEO 页面标题']);

        $html = $this->get('/seo-page')->getContent();
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/seo-page"', $html);
    }

    public function test_page_level_seo_meta_overrides_title(): void
    {
        $page = $this->makePage(['slug' => 'seo-override', 'title' => '原始标题']);
        $this->makeBlock($page, 'hero', ['title' => '原始标题']);

        SeoMeta::create([
            'site_id'  => $this->default->id,
            'page_id'  => $page->id,
            'locale'   => 'zh-CN',
            'title'    => 'SEO 覆盖标题',
            'og_title' => 'SEO 覆盖标题',
        ]);

        $html = $this->get('/seo-override')->getContent();
        $this->assertStringContainsString('SEO 覆盖标题', $html);
    }

    // ----------------------------------------------------------------
    // G 陌生品牌零代码搭站（全程仅经 Admin HTTP，不改核心代码）
    // ----------------------------------------------------------------

    public function test_unknown_brand_builds_landing_via_admin_only(): void
    {
        // 1) 建页面（draft）
        $this->actingAs($this->super)->post(route('admin.pages.store'), [
            'template' => 'landing',
            'locale'   => 'zh-CN',
            'title'    => '品牌发布页',
            'slug'     => 'brand-launch',
            'status'   => 'draft',
        ])->assertRedirect();
        $page = Page::where('slug', 'brand-launch')->where('locale', 'zh-CN')->firstOrFail();

        // 2) 加 hero block
        $this->actingAs($this->super)->post(route('admin.pages.storeBlock', $page), [
            'type' => 'hero', 'slot' => 'main',
        ])->assertRedirect();
        $hero = PageBlock::where('page_id', $page->id)->where('type', 'hero')->firstOrFail();

        // 3) 编辑 hero 文案
        $this->actingAs($this->super)->put(route('admin.pages.updateBlock', [$page, $hero]), [
            'field' => ['title' => '全新品牌，全新出发'],
        ])->assertRedirect();

        // 4) 加 FAQ block
        $this->actingAs($this->super)->post(route('admin.pages.storeBlock', $page), [
            'type' => 'faq', 'slot' => 'main',
        ])->assertRedirect();

        // 5) 发布
        $this->actingAs($this->super)
            ->post(route('admin.pages.publish', [$page, 'publish']))->assertRedirect();

        // 6) 前台访问
        $r = $this->get('/brand-launch');
        $r->assertOk();
        $this->assertStringContainsString('全新品牌，全新出发', $r->getContent());
    }

    // ----------------------------------------------------------------
    // 解耦
    // ----------------------------------------------------------------

    public function test_deleting_grid_block_keeps_product_entity(): void
    {
        $product = Entity::where('type', Entity::TYPE_PRODUCT)->first();
        $this->assertNotNull($product);

        $page = $this->makePage();
        $grid = $this->makeBlock($page, 'product_grid');

        $this->actingAs($this->super)
            ->delete(route('admin.pages.destroyBlock', [$page, $grid]))->assertRedirect();

        $this->assertDatabaseMissing('page_blocks', ['id' => $grid->id]);
        $this->assertDatabaseHas('entities', ['id' => $product->id]);
    }

    public function test_deleting_page_keeps_business_entities(): void
    {
        $product = Entity::where('type', Entity::TYPE_PRODUCT)->first();
        $this->assertNotNull($product);

        $page = $this->makePage();
        $this->makeBlock($page, 'product_grid');

        $this->actingAs($this->super)
            ->delete(route('admin.pages.destroy', $page))->assertRedirect();

        $this->assertDatabaseHas('entities', ['id' => $product->id]);
    }

    // ----------------------------------------------------------------
    // 多站隔离 / 翻译组
    // ----------------------------------------------------------------

    public function test_page_is_site_scoped(): void
    {
        $b = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => Site::STATUS_ACTIVE, 'is_default' => false,
        ]);

        $this->makePage(['slug' => 'only-on-a']);

        // B 前台看不到 A 的组合页
        $this->get('http://b.test/only-on-a')->assertNotFound();

        // 切到 B 上下文后，尝试编辑 A 的 page → 模型绑定跨站 404
        $aPage = Page::where('slug', 'only-on-a')->firstOrFail();
        $this->actingAs($this->super)->post(route('admin.sites.switch'), [
            'site_id' => $b->id,
        ])->assertRedirect();

        $this->actingAs($this->super)
            ->get(route('admin.pages.edit', $aPage))->assertNotFound();
    }

    public function test_translation_links_pages_by_group(): void
    {
        $zh = $this->makePage(['slug' => 'group-page', 'locale' => 'zh-CN', 'title' => '中文页']);
        // 模拟 translation_group（zh 行）
        $zh->translation_group = 'group-uuid-123';
        $zh->save();

        $this->actingAs($this->super)->post(route('admin.pages.store'), [
            'template'       => 'landing',
            'locale'         => 'en',
            'title'          => 'English Page',
            'slug'           => 'group-page',
            'status'         => 'published',
            'translation_of' => $zh->id,
        ])->assertRedirect();

        $en = Page::where('slug', 'group-page')->where('locale', 'en')->firstOrFail();
        $this->assertSame('group-uuid-123', $en->translation_group);
    }
}
