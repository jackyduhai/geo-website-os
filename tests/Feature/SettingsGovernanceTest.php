<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingController;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 17F：站点设置治理回归。
 *
 * 覆盖：
 *  - 全新生产安装（仅 DefaultSettingSeeder，不装 demo）七组字段完整、产品中性默认、
 *    RETIRE 键不存在、feed 开关默认开启、token 为空；
 *  - 后台七组可渲染、可保存并即时驱动前台；
 *  - RETIRE 键（site_short_name / site_slogan / sync_geoflow_endpoint /
 *    sync_pull_enabled）后台不渲染、伪造提交不可写；
 *  - sitemap / llms / RSS 站点开关关闭返回 404（三者各自独立门禁），robots 不再指向被关闭的 sitemap；
 *  - robots 自定义追加、公安备案空值隐藏 / 有值展示；
 *  - Token 重新生成使用产品中性前缀 gwos_；
 *  - 字段级校验（颜色 / 邮箱 / URL / 数值区间）；
 *  - 设置严格 per-site，A / B 互不串。
 */
class SettingsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected Site $default;

    /** 各组保留键数量（与 DefaultSettingSeeder 一致，合计 72）。 */
    private const GROUP_COUNTS = [
        'general' => 7,
        'theme'   => 16,
        'contact' => 7,
        'seo'     => 6,
        'geo'     => 6,
        'copy'    => 28,
        'sync'    => 3,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        PageCache::flush();
    }

    private function makeSiteB(): Site
    {
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    public function test_default_seeder_alone_populates_seven_neutral_groups(): void
    {
        // 模拟全新生产安装：RefreshDatabase 后仅装产品默认（不装 demo 行业数据）。
        // 先复刻 geo:install 的出厂收敛（TD-12）：默认站名 = 产品名。DefaultSettingSeeder
        // 的 site_name 是 Site.name 的派生镜像，不再独立硬编码 app.name。
        $this->default->name = config('app.name');
        $this->default->save();
        // 复刻 geo:install 顺序：先收敛站名并把当前站点上下文指向收敛后的站点，
        // 再 seed 默认设置，避免 setUp 阶段 seed() 已把旧站名 memo 进 SiteContext。
        SiteContext::setSite($this->default->fresh());
        Setting::withoutSiteScope()->delete();
        Setting::flush();
        $this->seed(DefaultSettingSeeder::class);

        foreach (self::GROUP_COUNTS as $group => $count) {
            $this->assertSame(
                $count,
                Setting::where('group', $group)->count(),
                "分组 [{$group}] 应有 {$count} 个产品默认字段"
            );
        }

        // RETIRE 键绝不被产品默认 Seeder 创建。
        $this->assertSame(0, Setting::withoutSiteScope()
            ->whereIn('key', array_keys(SettingController::RETIRED))->count());

        // 产品中性默认。
        $this->assertSame(config('app.name'), Setting::get('site_name'));
        $this->assertSame('', Setting::get('site_description'));
        $this->assertSame('', Setting::get('copy_bcta_title'));
        $this->assertSame('', Setting::get('sync_geoflow_token'));
        $this->assertSame('1', Setting::get('geo_sitemap_enabled'));
        $this->assertSame('1', Setting::get('geo_llms_enabled'));
        $this->assertSame('1', Setting::get('geo_rss_enabled'));
        $this->assertSame('0', Setting::get('sync_auto_publish'));
        // 补定义的孤儿键现在有正式设置行。
        $this->assertNotNull(Setting::where('key', 'seo_head_code')->first());
        $this->assertNotNull(Setting::where('key', 'sync_auto_publish')->first());
    }

    public function test_admin_renders_each_group_and_persists_to_frontend(): void
    {
        foreach (array_keys(self::GROUP_COUNTS) as $group) {
            $this->actingAs($this->super)->get("/admin/settings/{$group}")
                ->assertOk()->assertSee(SettingController::GROUPS[$group]);
        }

        $this->actingAs($this->super)->put('/admin/settings/general', [
            'site_name' => 'Acme OS',
        ])->assertRedirect();

        Setting::flush();
        PageCache::flush();
        $this->assertSame('Acme OS', Setting::get('site_name'));
        $this->get('/')->assertOk()->assertSee('Acme OS');
    }

    public function test_retired_keys_are_hidden_and_not_writable(): void
    {
        $this->actingAs($this->super)->get('/admin/settings/sync')->assertOk()
            ->assertDontSee('sync_pull_enabled')->assertDontSee('sync_geoflow_endpoint');
        $this->actingAs($this->super)->get('/admin/settings/general')->assertOk()
            ->assertDontSee('site_slogan')->assertDontSee('site_short_name');

        // 伪造提交 RETIRE 键必须被忽略（控制器只遍历未退役的设置行）。
        $this->actingAs($this->super)->put('/admin/settings/sync', [
            'sync_pull_enabled'      => '1',
            'sync_geoflow_endpoint'  => 'https://evil.example/pull',
            'sync_geoflow_enabled'   => '1',
        ])->assertRedirect();

        $this->assertSame(0, Setting::withoutSiteScope()
            ->whereIn('key', ['sync_pull_enabled', 'sync_geoflow_endpoint'])->count());
    }

    public function test_sitemap_llms_rss_toggles_off_return_404_and_robots_drops_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/llms.txt')->assertOk();
        $this->get('/feed.xml')->assertOk();
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:');

        // RSS 独立门禁（TD-20①）：只关 RSS 时 /feed.xml 404，sitemap 不受影响。
        Setting::set('geo_rss_enabled', '0');
        Setting::flush();
        $this->get('/feed.xml')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk();

        Setting::set('geo_sitemap_enabled', '0');
        Setting::set('geo_llms_enabled', '0');
        Setting::flush();

        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/llms.txt')->assertNotFound();
        $robots = $this->get('/robots.txt')->assertOk();
        $robots->assertDontSee('Sitemap:');

        // 恢复后重新可用。
        Setting::set('geo_sitemap_enabled', '1');
        Setting::set('geo_llms_enabled', '1');
        Setting::set('geo_rss_enabled', '1');
        Setting::flush();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/llms.txt')->assertOk();
        $this->get('/feed.xml')->assertOk();
    }

    public function test_robots_extra_setting_is_appended(): void
    {
        $this->get('/robots.txt')->assertOk()->assertDontSee('Disallow: /private/');

        Setting::set('seo_robots_extra', 'Disallow: /private/');
        Setting::flush();

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /private/');
    }

    public function test_police_number_renders_only_when_set(): void
    {
        PageCache::flush();
        $this->get('/')->assertOk()->assertDontSee('beian.mps.gov.cn', false);

        Setting::set('police_number', 'X公网安备 00000000000000 号');
        Setting::flush();
        PageCache::flush();

        $html = $this->get('/')->assertOk();
        $html->assertSee('beian.mps.gov.cn', false)->assertSee('X公网安备 00000000000000 号');
    }

    public function test_regenerated_token_uses_gwos_prefix(): void
    {
        $this->actingAs($this->super)->post(route('admin.settings.token'))->assertRedirect();

        $token = (string) Setting::get('sync_geoflow_token', '');
        $this->assertTrue(str_starts_with($token, 'gwos_'), "token 应以 gwos_ 开头，实际：{$token}");
        $this->assertFalse(str_starts_with($token, 'yhf_'), '不应再使用旧 yhf_ 前缀');
        $this->assertSame(5 + 48, strlen($token));
    }

    public function test_validation_rejects_bad_color_email_url_and_range(): void
    {
        $base = Setting::get('theme_primary');

        $this->actingAs($this->super)->put('/admin/settings/theme', ['theme_primary' => 'zzz'])
            ->assertSessionHasErrors('theme_primary');
        $this->actingAs($this->super)->put('/admin/settings/theme', ['theme_radius' => '999'])
            ->assertSessionHasErrors('theme_radius');
        $this->actingAs($this->super)->put('/admin/settings/contact', ['contact_email' => 'not-an-email'])
            ->assertSessionHasErrors('contact_email');
        $this->actingAs($this->super)->put('/admin/settings/contact', ['contact_map_url' => 'not-a-url'])
            ->assertSessionHasErrors('contact_map_url');

        // 校验失败不写入。
        Setting::flush();
        $this->assertSame($base, Setting::get('theme_primary'));

        // 合法值通过并持久化。
        $this->actingAs($this->super)->put('/admin/settings/theme', ['theme_primary' => '#aabbcc'])
            ->assertSessionHasNoErrors()->assertRedirect();
        Setting::flush();
        $this->assertSame('#aabbcc', Setting::get('theme_primary'));
    }

    public function test_settings_are_isolated_between_sites(): void
    {
        $b = $this->makeSiteB();

        SiteContext::withSite($b, function () {
            Setting::set('site_name', 'B 专属站点');
            Setting::flush();
        });

        SiteContext::withSite($b, function () use ($b) {
            $this->assertSame('B 专属站点', Setting::get('site_name'));
        });

        // A（default）仍是 demo 站点名，看不到 B 的设置。
        $aName = Setting::get('site_name');
        $this->assertNotSame('B 专属站点', $aName);

        // 前台往复 default → B → default（一律显式绝对 Host，避免测试客户端相对 URL
        // 沿用上一次绝对请求的 Host 上下文；真实 HTTP 每请求 Host 明确）。
        PageCache::flush();
        $this->get('http://localhost/')->assertOk()
            ->assertSee('示例制造')->assertDontSee('B 专属站点');
        $this->get('https://b.test/')->assertOk()->assertSee('B 专属站点');
        $this->get('http://localhost/')->assertOk()
            ->assertSee('示例制造')->assertDontSee('B 专属站点');
    }

    public function test_json_locales_persist_as_array_without_double_encoding(): void
    {
        // TD-112：json 设置若 Controller 提前 encode 会双重编码，且 applySetting 强转数组触发 500。
        $this->actingAs($this->super)
            ->put('/admin/settings/general', ['site_supported_locales' => ['zh-CN', 'en']])
            ->assertRedirect();

        Setting::flush();
        $locales = Setting::get('site_supported_locales');
        $this->assertIsArray($locales);
        $this->assertSame(['zh-CN', 'en'], $locales);

        // 默认语言始终保留：伪造提交不含默认语言也会被补回，非法语言码被剔除。
        $this->actingAs($this->super)
            ->put('/admin/settings/general', ['site_supported_locales' => ['en', 'fr-FR']])
            ->assertRedirect();
        Setting::flush();
        $locales2 = Setting::get('site_supported_locales');
        $this->assertContains('zh-CN', $locales2);
        $this->assertNotContains('fr-FR', $locales2);

        // 英文前台真实可访问。
        $this->get('/en')->assertOk();
    }

    public function test_color_placeholder_stays_empty_and_clear_checkbox_falls_back(): void
    {
        // TD-113：type=color 无法清空，占位灰若被保存会写成显式黑色；clear_color 回退默认。
        // 原始为空 + 提交占位灰 → 保持空（不被写成显式色）。
        $this->actingAs($this->super)
            ->put('/admin/settings/theme', ['theme_accent' => '#E5E7EB'])
            ->assertRedirect();
        Setting::flush();
        $this->assertSame('', Setting::get('theme_accent'));

        // 显式自定义色正常保存。
        $this->actingAs($this->super)
            ->put('/admin/settings/theme', ['theme_accent' => '#123456'])
            ->assertRedirect();
        Setting::flush();
        $this->assertSame('#123456', Setting::get('theme_accent'));

        // 勾选回退默认（即使同时提交了旧色）→ 清空。
        $this->actingAs($this->super)
            ->put('/admin/settings/theme', [
                'theme_accent' => '#123456', 'clear_color' => ['theme_accent'],
            ])->assertRedirect();
        Setting::flush();
        $this->assertSame('', Setting::get('theme_accent'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/settings/general')->assertRedirect(route('admin.login'));
    }
}
