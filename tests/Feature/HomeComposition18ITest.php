<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P-STEP 18I / TD-70：首页 Page Composition 产品化验收。
 * --------------------------------------------------
 * 验证「陌生品牌零代码建站」的首页契约：
 *   - 出厂 blank 首页只渲染行业中立欢迎屏（is_home Page、无区块、无 demo 内容）；
 *   - 管理员仅经 Admin（Page Composition Manager）即可添加 / 编辑 / 排序 / 显隐 /
 *     复制 / 删除首页区块，前台真实跟随，无需改 PHP / Blade / JS / CSS；
 *   - 区块改动精确失效页面缓存；
 *   - demo 首页为完整 Composition；中文 / 英文首页相互独立。
 */
class HomeComposition18ITest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();
    }

    // ---------------------------------------------------------------
    // 出厂 blank 首页
    // ---------------------------------------------------------------

    public function test_blank_home_renders_neutral_welcome_without_demo_content(): void
    {
        $site = $this->seedBlank();

        $res = $this->get('/')->assertOk();

        // 中性欢迎屏以站点名为主标题。
        $res->assertSee($site->name);
        // 不含任何 demo 行业 / 产品 / 车间内容。
        $res->assertDontSee('环氧富锌底漆');
        $res->assertDontSee('原料处理车间');
        $res->assertDontSee('代工');
        $this->assertStringContainsString('lang="zh-CN"', $res->content());

        // 存在已发布的 is_home Page，且出厂无任何挂载区块（回落欢迎屏）。
        $home = $this->homePage();
        $this->assertSame(Page::STATUS_PUBLISHED, $home->status);
        $this->assertSame(0, PageBlock::withoutSiteScope()->where('page_id', $home->id)->count());
    }

    // ---------------------------------------------------------------
    // Admin 零代码添加 / 编辑区块
    // ---------------------------------------------------------------

    public function test_admin_add_and_edit_hero_renders_on_frontend(): void
    {
        $this->seedBlank();
        $admin = $this->makeAdmin();
        $home = $this->homePage();

        // 添加 Hero 到 main 槽 → 重定向到该区块编辑页。
        $this->actingAs($admin)
            ->post(route('admin.pages.storeBlock', $home), ['type' => 'hero', 'slot' => 'main'])
            ->assertRedirect();

        $hero = PageBlock::withoutSiteScope()
            ->where('page_id', $home->id)->where('type', 'hero')->firstOrFail();

        // 结构化编辑 Hero（不存任意 HTML）。
        $this->actingAs($admin)
            ->put(route('admin.pages.updateBlock', [$home, $hero]), [
                'field' => [
                    'eyebrow'  => 'AURORA · SMART LIVING',
                    'title'    => 'Aurora 让家更懂你',
                    'subtitle' => '全屋智能，从一盏灯开始',
                    'buttons'  => [['label' => '查看产品', 'url' => '/products/', 'style' => 'primary']],
                    'image_id' => null,
                ],
            ])
            ->assertRedirect(route('admin.pages.composer', $home));

        $this->get('/')->assertSee('Aurora 让家更懂你');
    }

    // ---------------------------------------------------------------
    // Admin 显隐 / 排序 / 复制 / 删除
    // ---------------------------------------------------------------

    public function test_admin_toggle_move_duplicate_delete_blocks(): void
    {
        $this->seedBlank();
        $admin = $this->makeAdmin();
        $home = $this->homePage();

        // Hero（sort=1）
        $this->actingAs($admin)
            ->post(route('admin.pages.storeBlock', $home), ['type' => 'hero', 'slot' => 'main']);
        $hero = PageBlock::withoutSiteScope()->where('page_id', $home->id)->where('type', 'hero')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.pages.updateBlock', [$home, $hero]), [
            'field' => ['eyebrow' => '', 'title' => '首页主标题', 'subtitle' => '',
                'buttons' => [], 'image_id' => null],
        ]);

        // FeatureGrid（sort=2）
        $this->actingAs($admin)
            ->post(route('admin.pages.storeBlock', $home), ['type' => 'feature_grid', 'slot' => 'main']);
        $feat = PageBlock::withoutSiteScope()->where('page_id', $home->id)->where('type', 'feature_grid')->firstOrFail();
        $this->actingAs($admin)->put(route('admin.pages.updateBlock', [$home, $feat]), [
            'field' => ['title' => '特色区块', 'subtitle' => '', 'columns' => 3,
                'items' => [
                    ['icon' => 'shield', 'title' => '特色一', 'text' => '说明文字'],
                ]],
        ]);

        $this->assertSame(1, $hero->sort);
        $this->assertSame(2, $feat->sort);

        // 隐藏 Feature → 前台不见；恢复显示 → 出现。
        $this->actingAs($admin)->post(route('admin.pages.toggleBlock', [$home, $feat]))->assertRedirect();
        $this->get('/')->assertDontSee('特色区块');
        $this->actingAs($admin)->post(route('admin.pages.toggleBlock', [$home, $feat]));
        $this->get('/')->assertSee('特色区块');

        // Hero 下移：与 Feature 交换 sort。
        $this->actingAs($admin)->post(route('admin.pages.moveBlock', [$home, $hero, 'down']))->assertRedirect();
        $this->assertSame(2, (int) $hero->fresh()->sort);
        $this->assertSame(1, (int) $feat->fresh()->sort);

        // 复制 Hero：main 槽新增一份。
        $before = PageBlock::withoutSiteScope()->where('page_id', $home->id)->where('type', 'hero')->count();
        $this->actingAs($admin)->post(route('admin.pages.duplicateBlock', [$home, $hero]))->assertRedirect();
        $this->assertSame($before + 1,
            PageBlock::withoutSiteScope()->where('page_id', $home->id)->where('type', 'hero')->count());

        // 删除 Feature：前台不再出现。
        $this->actingAs($admin)->delete(route('admin.pages.destroyBlock', [$home, $feat]))->assertRedirect();
        $this->get('/')->assertDontSee('特色区块');
    }

    // ---------------------------------------------------------------
    // 缓存精确失效
    // ---------------------------------------------------------------

    public function test_home_cache_invalidates_after_block_update(): void
    {
        $this->seedBlank();
        $admin = $this->makeAdmin();
        $home = $this->homePage();

        $this->actingAs($admin)
            ->post(route('admin.pages.storeBlock', $home), ['type' => 'hero', 'slot' => 'main']);
        $hero = PageBlock::withoutSiteScope()->where('page_id', $home->id)->where('type', 'hero')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.pages.updateBlock', [$home, $hero]), [
            'field' => ['eyebrow' => '', 'title' => '标题版本一', 'subtitle' => '',
                'buttons' => [], 'image_id' => null],
        ]);

        // 首次渲染入缓存，第二次命中缓存，均为版本一。
        $this->get('/')->assertSee('标题版本一');
        $this->get('/')->assertSee('标题版本一');

        // 修改区块（页面级失效）→ 前台立即变为版本二，不再返回旧缓存。
        $this->actingAs($admin)->put(route('admin.pages.updateBlock', [$home, $hero]), [
            'field' => ['eyebrow' => '', 'title' => '标题版本二', 'subtitle' => '',
                'buttons' => [], 'image_id' => null],
        ]);

        $this->get('/')->assertSee('标题版本二')->assertDontSee('标题版本一');
    }

    // ---------------------------------------------------------------
    // Demo 完整首页 + 中英独立
    // ---------------------------------------------------------------

    public function test_demo_home_renders_full_composition(): void
    {
        $this->seed();

        $this->get('/')->assertOk()->assertSee('工业涂料');

        $home = $this->homePage();
        $this->assertSame(9, PageBlock::withoutSiteScope()->where('page_id', $home->id)->count());
    }

    public function test_zh_and_en_home_are_independent(): void
    {
        $this->seed();

        $zh = $this->get('/')->assertOk();
        $zh->assertSee('工业涂料');

        $en = $this->get('/en/')->assertOk();
        $en->assertSee('Coatings');
        $en->assertDontSee('工业涂料');
        $this->assertStringContainsString('lang="en"', $en->content());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function seedBlank(): Site
    {
        $site = Site::firstOrCreate(
            ['slug' => Site::DEFAULT_SLUG],
            ['name' => (string) config('app.name'), 'status' => 'active', 'metadata' => json_encode([])]
        );
        $site->name = (string) config('app.name');
        $site->save();

        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        $this->seed(DefaultFormSeeder::class);
        $this->seed(SystemPageSeeder::class);

        PageCache::flush();

        return $site;
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'            => 'Administrator',
            'email'           => 'admin@example.com',
            'password'        => Hash::make('Admin12345!'),
            'is_super_admin'  => true,
        ]);
    }

    private function homePage(string $locale = 'zh-CN'): Page
    {
        return Page::withoutSiteScope()
            ->where('is_home', true)->where('locale', $locale)->firstOrFail();
    }
}
