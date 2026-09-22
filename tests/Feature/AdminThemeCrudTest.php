<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P-STEP 17E：主题管理后台回归。
 *
 * 覆盖：访客跳登录；普通站点管理员可列出 / 激活本站主题；激活即时驱动前台换肤、
 * 切回 default 正确回退；激活不存在主题 404 且不留半激活；预览只在请求内换肤、
 * 不改激活态；无效（缺清单）目录只读告警且不可激活；重名主题告警；激活严格 per-site
 * （B 站 example / A 站 default，A→B→A 不串）。
 */
class AdminThemeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected User $plain;
    protected Site $default;

    /** 本测试临时创建、tearDown 必须清理的主题目录。 */
    private array $tempDirs = ['z-broken', 'z-dup'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->plain = User::create([
            'name' => '普通站点管理员',
            'email' => 'plain-theme@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        // 幂等：清掉上一轮可能因 Windows 文件句柄延迟而残留的临时主题目录。
        foreach ($this->tempDirs as $dir) {
            $this->forceDeleteDir(resource_path("themes/{$dir}"));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->forceDeleteDir(resource_path("themes/{$dir}"));
        }
        parent::tearDown();
    }

    /**
     * Windows 下递归删除偶发因文件句柄 / 索引延迟而静默失败：先多次重试
     * Laravel 删除，再用原生递归（逐文件解除只读 + rmdir）兜底，确保临时主题目录移除。
     * Linux CI 上首次删除即成功。setUp 也调用本方法，保证上一轮残留时本轮仍幂等。
     */
    private function forceDeleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        for ($i = 0; $i < 6; $i++) {
            File::deleteDirectory($path, true);
            if (! is_dir($path)) {
                return;
            }
            usleep(250000);
        }
        $this->rrmdir($path);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->rrmdir($child);
            } else {
                @chmod($child, 0777);
                @unlink($child);
            }
        }
        @chmod($dir, 0777);
        @rmdir($dir);
    }

    private function makeSiteB(): Site
    {
        return Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    private function switchTo(User $user, Site $site): void
    {
        $this->actingAs($user)->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.themes.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.themes.activate', 'example'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.themes.preview', 'example'))->assertRedirect(route('admin.login'));
    }

    public function test_plain_admin_can_list_and_activate_theme_for_this_site(): void
    {
        // 列出 default / example，并标记 default 为当前
        $list = $this->actingAs($this->plain)->get(route('admin.themes.index'))->assertOk();
        $list->assertSee('Default Theme')->assertSee('Example Theme')->assertSee('当前主题');

        // 激活 example（普通站点管理员即可，属本站运营操作）
        $this->actingAs($this->plain)
            ->post(route('admin.themes.activate', 'example'))
            ->assertRedirect(route('admin.themes.index'))
            ->assertSessionHas('success');

        // 前台首页立即由 example 主题覆盖视图渲染（标记来自 example/site/home.blade.php）
        $this->get('http://localhost/')->assertOk()->assertSee('data-theme="example-home"', false);
    }

    public function test_switch_back_to_default_restores_base_frontend(): void
    {
        $this->actingAs($this->super)->post(route('admin.themes.activate', 'example'))->assertRedirect();
        $this->get('http://localhost/')->assertOk()->assertSee('data-theme="example-home"', false);

        $this->actingAs($this->super)->post(route('admin.themes.activate', 'default'))->assertRedirect();
        $this->get('http://localhost/')->assertOk()->assertDontSee('data-theme="example-home"', false);
    }

    public function test_activate_unknown_theme_aborts_404_without_half_state(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.themes.activate', 'nope'))
            ->assertNotFound();

        // 激活态保持 default：前台不出现 example 标记，设置中无 theme_active=example
        $this->get('http://localhost/')->assertOk()->assertDontSee('data-theme="example-home"', false);
        $this->assertDatabaseMissing('settings', ['key' => 'theme_active', 'site_id' => $this->default->id]);
    }

    public function test_preview_renders_theme_without_changing_active_theme(): void
    {
        // 当前激活为 default
        $this->get('http://localhost/')->assertOk()->assertDontSee('data-theme="example-home"', false);

        // 预览 example：返回 example 渲染的首页，并带 noindex
        $preview = $this->actingAs($this->super)
            ->get(route('admin.themes.preview', 'example'))
            ->assertOk()
            ->assertSee('data-theme="example-home"', false);
        $preview->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $preview->assertHeader('X-Theme-Preview', 'example');

        // 激活态未被预览改变：随后前台首页仍是 default
        $this->get('http://localhost/')->assertOk()->assertDontSee('data-theme="example-home"', false);
        $this->assertDatabaseMissing('settings', ['key' => 'theme_active', 'site_id' => $this->default->id]);
    }

    public function test_preview_unknown_theme_aborts_404(): void
    {
        $this->actingAs($this->super)
            ->get(route('admin.themes.preview', 'ghost'))
            ->assertNotFound();
    }

    public function test_invalid_theme_directory_is_warned_and_not_activatable(): void
    {
        File::ensureDirectoryExists(resource_path('themes/z-broken'));

        $this->actingAs($this->super)->get(route('admin.themes.index'))->assertOk()
            ->assertSee('检测到无效主题目录')->assertSee('缺少 theme.json 清单文件');

        // 无效主题不可激活
        $this->actingAs($this->super)
            ->post(route('admin.themes.activate', 'z-broken'))
            ->assertNotFound();
    }

    public function test_duplicate_display_name_is_warned(): void
    {
        File::ensureDirectoryExists(resource_path('themes/z-dup'));
        File::put(
            resource_path('themes/z-dup/theme.json'),
            json_encode(['name' => 'Example Theme', 'version' => '9.9.9'], JSON_UNESCAPED_UNICODE)
        );

        $this->actingAs($this->super)->get(route('admin.themes.index'))->assertOk()
            ->assertSee('存在重名主题');
    }

    public function test_theme_activation_is_strictly_per_site(): void
    {
        $b = $this->makeSiteB();

        // B 站激活 example
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.themes.activate', 'example'))->assertRedirect();

        // A 站保持 default（未激活任何主题）
        $this->actingAs($this->super)->get('http://localhost/')->assertOk()
            ->assertDontSee('data-theme="example-home"', false);

        // A → B → A → B 往复，主题严格按 Host 隔离
        for ($i = 0; $i < 3; $i++) {
            $this->get('https://b.test/')->assertOk()->assertSee('data-theme="example-home"', false);
            $this->get('http://localhost/')->assertOk()->assertDontSee('data-theme="example-home"', false);
        }

        // 两站设置互不污染：B=example，A 无 theme_active 记录
        $this->assertDatabaseMissing('settings', ['key' => 'theme_active', 'site_id' => $this->default->id]);
        $bSetting = \App\Models\Setting::withoutSiteScope()
            ->where('site_id', $b->id)->where('key', 'theme_active')->first();
        $this->assertNotNull($bSetting);
        $this->assertSame('example', $bSetting->value);
    }

    public function test_super_admin_sees_readonly_cross_site_theme_matrix(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.themes.activate', 'example'))->assertRedirect();

        // 超管在任意站点上下文都能看到全部站点的激活主题只读总览
        $page = $this->actingAs($this->super)->get(route('admin.themes.index'))->assertOk();
        $page->assertSee('各站点当前激活主题（只读总览）')
            ->assertSee('Site B')
            ->assertSee('example');
    }
}
