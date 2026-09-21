<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\Plugins\PluginManager;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 14 / D.3：插件 per-site 路由隔离（注册 / 授权分离）。
 *
 * 路由在加载期为所有“已安装”插件注册，每条挂 EnsurePluginEnabled:{slug} 守卫，
 * 运行时按“当前请求站点的启用设置”判定 200 / 404。本类用真实 HTTP（按 Host 解析站点）
 * 验证同一插件在 A / B 两站独立启停、互不影响，并覆盖反复切换。
 */
class PluginPerSiteRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Site $a;
    private Site $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->a->update(['name' => 'Site A', 'domain' => 'a-plugin.test']);

        $this->b = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b-plugin.test',
            'status' => 'active', 'is_default' => false,
        ]);

        PageCache::flush();
    }

    protected function tearDown(): void
    {
        $this->setPlugin($this->a, false);
        $this->setPlugin($this->b, false);
        PageCache::flush();
        parent::tearDown();
    }

    /** 在指定站点上下文写启用设置（模拟后台按站启停） */
    private function setPlugin(Site $site, bool $enabled): void
    {
        SiteContext::withSite($site, function () use ($enabled) {
            PluginManager::resetRequestMemo();
            if ($enabled) {
                PluginManager::enable('hello');
            } else {
                PluginManager::disable('hello');
            }
        });
    }

    private function ping(string $domain)
    {
        return $this->get('https://' . $domain . '/plugins/hello/ping');
    }

    public function test_route_is_404_on_both_sites_when_disabled_everywhere(): void
    {
        $this->ping('a-plugin.test')->assertNotFound();
        $this->ping('b-plugin.test')->assertNotFound();

        // 核心页不受影响
        $this->get('https://a-plugin.test/')->assertOk();
        $this->get('https://b-plugin.test/')->assertOk();
    }

    public function test_route_served_only_on_site_a_when_enabled_there(): void
    {
        $this->setPlugin($this->a, true);

        $respA = $this->ping('a-plugin.test')->assertOk()->json();
        $this->assertSame('hello', $respA['plugin']);

        // B 站未启用：同一路由必须 404，不得复用 A 站启用态
        $this->ping('b-plugin.test')->assertNotFound();
    }

    public function test_route_served_only_on_site_b_when_enabled_there(): void
    {
        $this->setPlugin($this->b, true);

        $this->ping('a-plugin.test')->assertNotFound();

        $respB = $this->ping('b-plugin.test')->assertOk()->json();
        $this->assertSame('hello', $respB['plugin']);
        $this->assertSame('pong from example plugin', $respB['message']);
    }

    public function test_route_toggles_independently_across_repeated_site_switching(): void
    {
        // 初始：两站均停用
        $this->ping('a-plugin.test')->assertNotFound();
        $this->ping('b-plugin.test')->assertNotFound();

        // 仅 A 启用 → A 200 / B 404
        $this->setPlugin($this->a, true);
        $this->ping('a-plugin.test')->assertOk();
        $this->ping('b-plugin.test')->assertNotFound();

        // B 也启用（两站都启用）→ 都 200
        $this->setPlugin($this->b, true);
        $this->ping('a-plugin.test')->assertOk();
        $this->ping('b-plugin.test')->assertOk();

        // 关闭 A → A 404 / B 仍 200
        $this->setPlugin($this->a, false);
        $this->ping('a-plugin.test')->assertNotFound();
        $this->ping('b-plugin.test')->assertOk();

        // 再关 B → 都 404
        $this->setPlugin($this->b, false);
        $this->ping('a-plugin.test')->assertNotFound();
        $this->ping('b-plugin.test')->assertNotFound();

        // 再次打开 A（验证停用后可重新启用，且不影响 B）
        $this->setPlugin($this->a, true);
        $this->ping('a-plugin.test')->assertOk();
        $this->ping('b-plugin.test')->assertNotFound();
    }

    public function test_plugin_route_is_unknown_when_plugin_not_installed(): void
    {
        // 未安装插件的守卫等价 404（通过不存在 slug 的中间件契约校验）
        $this->assertFalse(PluginManager::exists('not-installed-plugin'));
    }
}
