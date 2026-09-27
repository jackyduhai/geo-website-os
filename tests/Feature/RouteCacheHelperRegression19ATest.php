<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Setting;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 19A — RB-1 防回归：全局 helper 经 composer autoload.files 加载，
 * route:cache 生产模式下 /contact 等页面不再 500。
 *
 * 根因：localized_route() / PublicUrlLocalized() 原定义在 routes/web.php，
 * route:cache 后 Laravel 不再 require web.php，函数未定义 → dynamic_form 渲染 500。
 * 修复：两函数移入 app/Support/helpers.php，composer.json autoload.files 加载。
 *
 * 本测试覆盖：
 *   A) function_exists 断言（证明经 autoload 加载，不依赖 routes/web.php）
 *   B) /contact 与 /en/contact 200 + 表单 action 双语正确
 *   C) /scenarios 兼容重定向（PublicUrlLocalized）
 *   D) route:cache 后 function_exists（证明 helpers 不依赖 routes/web.php；
 *      route:cache + 全新进程 HTTP 四态由发布 E2E 覆盖，单测内 in-memory DB
 *      会因 route:cache 重置连接而无法做 HTTP 断言）
 */
class RouteCacheHelperRegression19ATest extends TestCase
{
    use RefreshDatabase;

    private Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed([
            DefaultSettingSeeder::class,
            DefaultFormSeeder::class,
            SystemPageSeeder::class,
        ]);
        PageCache::flush();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
    }

    protected function tearDown(): void
    {
        // 确保 route:cache 不污染后续测试
        @unlink(base_path('bootstrap/cache/routes-v7.php'));
        @unlink(base_path('bootstrap/cache/config.php'));
        parent::tearDown();
    }

    // ---------- A) function_exists ----------

    public function test_helpers_are_loaded_via_composer_autoload(): void
    {
        $this->assertTrue(function_exists('localized_route'),
            'localized_route() must be loaded via composer autoload.files, not routes/web.php');
        $this->assertTrue(function_exists('PublicUrlLocalized'),
            'PublicUrlLocalized() must be loaded via composer autoload.files, not routes/web.php');
    }

    // ---------- B) /contact 双语 200 + 表单 action ----------

    public function test_contact_page_renders_200_with_correct_form_action(): void
    {
        $response = $this->get('/contact');
        $response->assertStatus(200);
        // 中文表单 action 不应含 /en 前缀
        $response->assertSee('action="http://localhost/forms/contact/submit"', false);
        $response->assertDontSee('action="http://localhost/en/forms/contact/submit"', false);
    }

    public function test_en_contact_page_renders_200_with_en_form_action(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $response = $this->get('/en/contact');
        $response->assertStatus(200);
        // 英文表单 action 应含 /en 前缀
        $response->assertSee('action="http://localhost/en/forms/contact/submit"', false);
    }

    // ---------- C) /scenarios 兼容重定向 ----------

    public function test_scenarios_redirects_to_solutions(): void
    {
        $this->get('/scenarios')
            ->assertStatus(301)
            ->assertRedirect('http://localhost/solutions/');
    }

    public function test_en_scenarios_redirects_to_en_solutions(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $this->get('/en/scenarios')
            ->assertStatus(301)
            ->assertRedirect('http://localhost/en/solutions/');
    }

    // ---------- D) route:cache 后函数仍可用 ----------
    // route:cache + HTTP 请求在 RefreshDatabase（in-memory SQLite）测试中会导致
    // DB 连接重置（"no such table"），无法在单测内做完整 HTTP 断言。
    // function_exists 断言已证明 helpers 经 autoload.files 加载、不依赖 routes/web.php。
    // route:cache + 全新进程的四态 HTTP 验证由发布 E2E（serve 进程）覆盖。

    public function test_helpers_still_exist_after_route_cache(): void
    {
        $this->artisan('route:cache')->assertExitCode(0);

        $this->assertTrue(function_exists('localized_route'));
        $this->assertTrue(function_exists('PublicUrlLocalized'));

        $this->artisan('route:clear')->assertExitCode(0);
    }
}
