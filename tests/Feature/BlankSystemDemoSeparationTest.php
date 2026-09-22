<?php

namespace Tests\Feature;

use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18B / #115：Blank System ≠ Demo Site。
 *
 * 出厂状态（geo:install）必须是行业中立的空站：历史 data migration 播种的垂直
 * 首页装修由 BlankHomepageSeeder 清空，首页回落到行业中立欢迎屏，空 Catalog 下
 * 产品 / 场景 / 工厂 / 合作 / 关于 / 联系一律 404，Feed 不携带任何行业身份。
 *
 * 工业材料制造的完整演示身份只在显式 db:seed（DemoSeeder）后出现，且属于通用
 * 虚构 Example（示例制造有限公司），绝不携带任何真实客户身份。
 *
 * 同时锁定 TD-12：Site.name 是站点显示名唯一权威，settings.site_name 只是镜像，
 * 模型保存与后台「常规设置」两条写入路径都必须收敛到同一事实源。
 */
class BlankSystemDemoSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Site $default;

    /** 出厂空站任何公开输出都不得出现的行业 / 客户身份词。 */
    private const BLANK_FORBIDDEN = [
        '示例制造', '定制制造', '工业涂料', '工业胶粘剂', '功能助剂',
        '代工', '配方', '年产能', '生产车间', '厂区',
        'Sample Snack', 'Sample Marinade', 'Demo Tenant A', 'Sample City', 'Sample Province', 'Sample Road', 'Sample SaaS',
    ];

    /** Demo 是通用虚构 Example，绝不允许回流真实客户身份。 */
    private const REAL_CUSTOMER = ['Sample Snack', 'Sample Marinade', 'Demo Tenant A', 'Sample City', 'Sample Province', 'Sample Road', '400-001-3770', 'Sample SaaS'];

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
    }

    /** 模拟 geo:install 出厂：产品默认设置 + 清空垂直首页装修（不装 Demo）。 */
    private function seedBlank(): void
    {
        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        Catalog::flush();
        PageCache::flush();
        Setting::flush();
    }

    public function test_blank_home_has_no_homepage_blocks(): void
    {
        $this->seedBlank();

        $this->assertSame(0, PageBlock::where('page', 'home')->count());
    }

    public function test_blank_home_renders_neutral_welcome_without_industry_identity(): void
    {
        $this->seedBlank();

        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee('站点已就绪');
        $home->assertSee(config('app.name'));

        foreach (self::BLANK_FORBIDDEN as $word) {
            $home->assertDontSee($word);
        }
    }

    public function test_blank_site_hides_demo_catalog_pages(): void
    {
        $this->seedBlank();

        foreach (['/products', '/solutions', '/factory', '/cooperation', '/about/profile', '/contact'] as $url) {
            $this->get($url)->assertNotFound();
        }

        // 空 Catalog 下请求一个 Demo 产品 slug 也必须 404，而不是渲染残留。
        $this->get('/products/epoxy-primer-100')->assertNotFound();
    }

    public function test_blank_feeds_are_reachable_but_industry_neutral(): void
    {
        $this->seedBlank();

        // geo.json 是机器可读图（中文经 Unicode 转义）：空站不得包含任何 Example 实体 / 组织。
        $geo = $this->get('/geo.json');
        $geo->assertOk();
        $geo->assertJsonPath('entities', []);
        $geo->assertJsonMissing(['slug' => 'example-organization']);

        foreach (['/sitemap.xml', '/llms.txt', '/feed.xml', '/robots.txt', '/knowledge'] as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $response->assertDontSee('示例制造');
            $response->assertDontSee('Sample Snack');
            $response->assertDontSee('工业涂料');
        }
    }

    public function test_demo_seed_rebuilds_full_manufacturing_example_from_data_layer(): void
    {
        $this->seedBlank();
        $this->seed(DemoSeeder::class);
        Catalog::flush();
        PageCache::flush();
        Setting::flush();

        // Demo StructureSeeder 重建完整 16 个首页装修区块。
        $this->assertSame(16, PageBlock::where('page', 'home')->count());

        $this->get('/')->assertOk()->assertSee('示例制造');
        $this->get('/products')->assertOk();

        // about.profile 的完整制造故事经叙事插槽（contents.slot）装载，而不是出厂 config。
        $profile = $this->get('/about/profile');
        $profile->assertOk();
        $profile->assertSee('工业材料');

        $this->get('/geo.json')->assertOk()
            ->assertJsonPath('site.name', '示例制造有限公司');
        $this->get('/feed.xml')->assertOk()->assertSee('示例制造');
    }

    public function test_demo_example_never_carries_real_customer_identity(): void
    {
        $this->seedBlank();
        $this->seed(DemoSeeder::class);
        Catalog::flush();
        PageCache::flush();

        foreach (['/', '/geo.json', '/about/profile', '/products'] as $url) {
            $response = $this->get($url);
            foreach (self::REAL_CUSTOMER as $word) {
                $response->assertDontSee($word);
            }
        }
    }

    public function test_renaming_site_model_mirrors_single_name_to_settings_and_feeds(): void
    {
        $this->seedBlank();

        $this->default->update(['name' => 'Acme Neutral 站点']);
        Setting::flush();
        PageCache::flush();

        $this->assertSame('Acme Neutral 站点', Setting::get('site_name'));

        $this->get('/feed.xml')->assertOk()->assertSee('Acme Neutral 站点');
        $this->get('/')->assertOk()->assertSee('Acme Neutral 站点');
    }

    public function test_admin_general_settings_rename_flows_back_to_site_authority(): void
    {
        // DatabaseSeeder 提供超级管理员与基线数据。
        $this->seed();
        $super = User::where('email', 'admin@example.com')->firstOrFail();
        Setting::flush();
        PageCache::flush();

        $this->actingAs($super)
            ->put('/admin/settings/general', ['site_name' => '后台改名 Acme'])
            ->assertRedirect();

        Setting::flush();

        $this->assertSame('后台改名 Acme', $this->default->fresh()->name);
        $this->assertSame('后台改名 Acme', Setting::get('site_name'));
    }
}
