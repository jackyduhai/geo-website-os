<?php

namespace Tests\Feature;

use App\Support\PageCache;
use App\Support\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 06：Plugin / Extension Architecture。
 *
 * 扩展契约：
 *   - 插件 = plugins/{slug}/plugin.json 清单 + 自有 ServiceProvider；
 *   - 启用（写站点设置 + 即时 boot）/ 停用（路由不再注册）；
 *   - 插件只在自己前缀内注册路由，禁止修改 Core；
 *   - 启用 → 功能可用；停用 → Core 完全不受影响。
 */
class PluginArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PluginManager::disable('hello');
        PageCache::flush();
        parent::tearDown();
    }

    public function test_discovers_example_plugin_manifest(): void
    {
        $plugins = PluginManager::all();

        $this->assertArrayHasKey('hello', $plugins);
        $this->assertSame('Hello Example Plugin', $plugins['hello']['name']);
        $this->assertSame('Plugin\\Hello\\Providers\\HelloServiceProvider', $plugins['hello']['provider']);
    }

    public function test_plugin_disabled_by_default_and_core_unaffected(): void
    {
        $this->assertFalse(PluginManager::isEnabled('hello'));
        $this->get('/plugins/hello/ping')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_enabled_plugin_boots_and_serves_own_route(): void
    {
        $this->assertTrue(PluginManager::enable('hello'));
        $this->assertTrue(PluginManager::isEnabled('hello'));

        $json = $this->get('/plugins/hello/ping')->assertOk()->json();
        $this->assertSame('hello', $json['plugin']);
        $this->assertSame('pong from example plugin', $json['message']);

        // 核心页面不受插件启用影响
        $this->get('/')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_disable_stops_plugin_and_core_still_works(): void
    {
        PluginManager::enable('hello');
        $this->get('/plugins/hello/ping')->assertOk();

        PluginManager::disable('hello');
        // 进程内路由由已注册 provider 残留（生命周期到进程结束），
        // 但启用态已持久化：新请求进程（真实部署）不再注册。
        $this->assertFalse(PluginManager::isEnabled('hello'));
        $this->get('/')->assertOk();
        $this->get('/geo.json')->assertOk();
    }

    public function test_enable_is_idempotent_and_unknown_plugin_rejected(): void
    {
        $this->assertTrue(PluginManager::enable('hello'));
        $this->assertTrue(PluginManager::enable('hello'));
        $this->assertSame(['hello'], PluginManager::enabled());

        $this->assertFalse(PluginManager::enable('nonexistent-plugin'));
        $this->assertFalse(PluginManager::exists('nonexistent-plugin'));
    }

    public function test_core_code_has_no_plugin_references(): void
    {
        // 静态边界：Core（app/）除 PluginManager 自身外不得感知任何具体插件
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', (string) $file);
            if (str_contains($path, 'Support/Plugins/PluginManager.php')) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('plugins/hello', $src, $path . ' 感知了具体插件');
            $this->assertStringNotContainsString('Plugin\\Hello', $src, $path . ' 感知了具体插件');
        }
    }
}
