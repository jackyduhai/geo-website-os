<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P-STEP 17E：插件管理后台回归。
 *
 * 覆盖：访客跳登录；列出已安装插件、注册路由与本站实时探测（停用 404）；
 * 启用后本站前台路由 200、停用后 404；启用未知插件 404；per-site 严格隔离
 * （B 启用 / A 停用，Host B 200、Host A 404，A→B→A 不串）；admin 登录态不绕过
 * EnsurePluginEnabled；依赖未满足拒绝启用、满足后放行；被依赖插件不可停用；
 * 无效（缺清单）插件目录只读告警且不可启用。
 */
class AdminPluginCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected User $plain;
    protected Site $default;

    /** 临时插件目录，tearDown 必须清理。 */
    private array $tempDirs = ['depchild', 'z-bad'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->plain = User::create([
            'name' => '普通站点管理员',
            'email' => 'plain-plugin@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        // 幂等：清掉上一轮可能因 Windows 文件句柄延迟而残留的临时插件目录。
        foreach ($this->tempDirs as $dir) {
            $this->forceDeleteDir(base_path("plugins/{$dir}"));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->forceDeleteDir(base_path("plugins/{$dir}"));
        }
        parent::tearDown();
    }

    /**
     * Windows 下递归删除偶发因文件句柄 / 索引延迟而静默失败：先多次重试
     * Laravel 删除，再用原生递归（逐文件解除只读 + rmdir）兜底，确保临时插件目录移除。
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

    /** 构造一个依赖 hello 的临时插件（含真实 provider、无前台路由）。 */
    private function makeDependentPlugin(): void
    {
        File::ensureDirectoryExists(base_path('plugins/depchild/providers'));
        File::put(base_path('plugins/depchild/plugin.json'), json_encode([
            'name' => 'Dep Child Plugin',
            'version' => '1.0.0',
            'provider' => 'Plugin\\DepChild\\Providers\\DepChildServiceProvider',
            'dependencies' => ['hello'],
        ], JSON_UNESCAPED_UNICODE));
        File::put(base_path('plugins/depchild/providers/DepChildServiceProvider.php'),
            "<?php\nnamespace Plugin\\DepChild\\Providers;\nuse Illuminate\\Support\\ServiceProvider;\n"
            ."class DepChildServiceProvider extends ServiceProvider\n{\n    public function boot(): void {}\n}\n");
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.plugins.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.plugins.enable', 'hello'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.plugins.disable', 'hello'))->assertRedirect(route('admin.login'));
    }

    public function test_index_lists_hello_its_route_and_probes_404_when_disabled(): void
    {
        $page = $this->actingAs($this->plain)->get(route('admin.plugins.index'))->assertOk();
        $page->assertSee('Hello Example Plugin')
            ->assertSee('plugins/hello/ping')
            ->assertSee('已停用')
            ->assertSee('探测 404', false);
    }

    public function test_enable_makes_route_200_and_disable_returns_404(): void
    {
        // 停用状态：前台 404
        $this->get('http://localhost/plugins/hello/ping')->assertNotFound();

        $this->actingAs($this->plain)
            ->post(route('admin.plugins.enable', 'hello'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('success');

        // 本站前台路由 200 且返回 pong
        $this->get('http://localhost/plugins/hello/ping')->assertOk()->assertSee('pong');

        // 列表探测变为 200
        $this->actingAs($this->plain)->get(route('admin.plugins.index'))->assertOk()
            ->assertSee('探测 200', false);

        // 停用后再次 404
        $this->actingAs($this->plain)->post(route('admin.plugins.disable', 'hello'))->assertRedirect();
        $this->get('http://localhost/plugins/hello/ping')->assertNotFound();
    }

    public function test_enable_unknown_plugin_aborts_404(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.plugins.enable', 'ghost'))
            ->assertNotFound();
    }

    public function test_admin_session_does_not_bypass_plugin_guard(): void
    {
        // 即使以超管登录后台，停用插件的前台路由依旧 404（守卫不认 admin 会话）
        $this->actingAs($this->super)->get('http://localhost/plugins/hello/ping')->assertNotFound();
    }

    public function test_plugin_enable_is_strictly_per_site(): void
    {
        $b = $this->makeSiteB();

        // 仅在 B 站启用
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'hello'))->assertRedirect();

        // A 站仍停用 → 404；B 站启用 → 200；A→B→A 往复不串
        for ($i = 0; $i < 3; $i++) {
            $this->get('https://b.test/plugins/hello/ping')->assertOk()->assertSee('pong');
            $this->get('http://localhost/plugins/hello/ping')->assertNotFound();
        }

        // 设置层隔离：B 含 hello，A 无任何启用记录
        $bRaw = \App\Models\Setting::withoutSiteScope()
            ->where('site_id', $b->id)->where('key', 'plugins_enabled')->first();
        $this->assertNotNull($bRaw);
        $bList = is_string($bRaw->value) ? json_decode($bRaw->value, true) : $bRaw->value;
        $this->assertContains('hello', $bList);
        $this->assertDatabaseMissing('settings', ['key' => 'plugins_enabled', 'site_id' => $this->default->id]);
    }

    public function test_dependency_must_be_enabled_first(): void
    {
        $this->makeDependentPlugin();

        // hello 未启用：启用 depchild 被拒绝，不写启用态
        $this->actingAs($this->super)
            ->post(route('admin.plugins.enable', 'depchild'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('settings', ['key' => 'plugins_enabled', 'site_id' => $this->default->id]);
        $this->assertFalse(PluginManager::isEnabled('depchild'));

        // 先启用依赖 hello，再启用 depchild 成功
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'hello'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'depchild'))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(PluginManager::isEnabled('depchild'));
    }

    public function test_cannot_disable_plugin_that_enabled_dependent_requires(): void
    {
        $this->makeDependentPlugin();
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'hello'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'depchild'))->assertRedirect();

        // depchild 依赖 hello：停用 hello 被拒绝，hello 仍启用（前台仍 200）
        $this->actingAs($this->super)
            ->post(route('admin.plugins.disable', 'hello'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error');
        $this->assertTrue(PluginManager::isEnabled('hello'));
        $this->get('http://localhost/plugins/hello/ping')->assertOk();
    }

    public function test_invalid_plugin_directory_is_warned_and_not_enableable(): void
    {
        File::ensureDirectoryExists(base_path('plugins/z-bad'));

        $this->actingAs($this->super)->get(route('admin.plugins.index'))->assertOk()
            ->assertSee('检测到无效插件目录')->assertSee('缺少 plugin.json 清单文件');

        $this->actingAs($this->super)
            ->post(route('admin.plugins.enable', 'z-bad'))
            ->assertNotFound();
    }

    public function test_super_admin_sees_readonly_cross_site_plugin_matrix(): void
    {
        $b = $this->makeSiteB();
        $this->switchTo($this->super, $b);
        $this->actingAs($this->super)->post(route('admin.plugins.enable', 'hello'))->assertRedirect();

        // 超管可见全部站点 × 插件的启用态只读矩阵
        $page = $this->actingAs($this->super)->get(route('admin.plugins.index'))->assertOk();
        $page->assertSee('各站点插件启用态（只读总览）')
            ->assertSee('Site B')
            ->assertSee('Hello Example Plugin');
    }
}
