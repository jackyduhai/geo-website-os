<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 14 / D.4：withSite() 进程内状态隔离。
 *
 * withSite 切站不仅替换 currentSite，还必须复位全部“按当前站点解析、以 static 记忆”
 * 的请求级状态（Setting / Catalog / SeoMeta / Theme / Plugin / SiteScope / SiteCacheKey）。
 * 本类在同一 PHP 进程内连续 / 嵌套切站，验证无 memo 串站（CLI / Admin / Job 内嵌场景）。
 */
class SiteContextStateIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Site $a;
    private Site $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->a->update(['name' => 'Site A', 'domain' => 'a-state.test']);

        $this->b = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b-state.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    public function test_settings_do_not_leak_across_repeated_with_site_switching(): void
    {
        SiteContext::withSite($this->a, fn () => Setting::set('probe_key', 'VALUE_A'));
        SiteContext::withSite($this->b, fn () => Setting::set('probe_key', 'VALUE_B'));

        // 旧缺陷：withSite 不复位 static memo，第二次切回 A 会短路读到 B 的值
        SiteContext::withSite($this->a, function () {
            $this->assertSame($this->a->id, SiteContext::currentSiteId());
            $this->assertSame('VALUE_A', Setting::get('probe_key'));
        });
        SiteContext::withSite($this->b, function () {
            $this->assertSame($this->b->id, SiteContext::currentSiteId());
            $this->assertSame('VALUE_B', Setting::get('probe_key'));
        });
        // 再做一轮 A→B→A→B，确认反复切换稳定
        SiteContext::withSite($this->a, fn () => $this->assertSame('VALUE_A', Setting::get('probe_key')));
        SiteContext::withSite($this->b, fn () => $this->assertSame('VALUE_B', Setting::get('probe_key')));
    }

    public function test_with_site_restores_outer_context_and_state_on_exit(): void
    {
        SiteContext::setSite($this->a);
        RequestScopedState::reapply();
        SiteContext::withSite($this->a, fn () => Setting::set('probe_key', 'VALUE_A'));
        SiteContext::withSite($this->b, fn () => Setting::set('probe_key', 'VALUE_B'));

        // 当前外层是 A：进入 B 再退出后，currentSite 与数据都必须恢复为 A
        SiteContext::withSite($this->b, fn () => $this->assertSame('VALUE_B', Setting::get('probe_key')));

        $this->assertSame($this->a->id, SiteContext::currentSiteId());
        $this->assertSame('VALUE_A', Setting::get('probe_key'));
    }

    public function test_nested_with_site_contexts_are_correctly_restored(): void
    {
        SiteContext::withSite($this->a, function () {
            Setting::set('probe_key', 'VALUE_A');
            $this->assertSame($this->a->id, SiteContext::currentSiteId());

            SiteContext::withSite($this->b, function () {
                Setting::set('probe_key', 'VALUE_B');
                $this->assertSame($this->b->id, SiteContext::currentSiteId());
                $this->assertSame('VALUE_B', Setting::get('probe_key'));

                // 内层再切回 A
                SiteContext::withSite($this->a, function () {
                    $this->assertSame($this->a->id, SiteContext::currentSiteId());
                    $this->assertSame('VALUE_A', Setting::get('probe_key'));
                });

                // 内层 A 退出后恢复为 B
                $this->assertSame($this->b->id, SiteContext::currentSiteId());
                $this->assertSame('VALUE_B', Setting::get('probe_key'));
            });

            // 外层 B 退出后恢复为 A
            $this->assertSame($this->a->id, SiteContext::currentSiteId());
            $this->assertSame('VALUE_A', Setting::get('probe_key'));
        });
    }

    public function test_catalog_memo_is_site_scoped_across_with_site_switching(): void
    {
        // 仅 A 站播种 Example 目录（Catalog 投影为本站 Entity）
        SiteContext::setSite($this->a);
        RequestScopedState::reapply();
        $this->seed(CatalogSeeder::class);

        SiteContext::withSite($this->a, function () {
            $this->assertNotEmpty(Catalog::company());
        });
        SiteContext::withSite($this->b, function () {
            // B 站为空站：不得读到 A 站 / 全局目录
            $this->assertEmpty(Catalog::company());
            $this->assertSame([], Catalog::products());
        });
        // 切回 A，memo 重建后仍能读到 A 目录
        SiteContext::withSite($this->a, function () {
            $this->assertNotEmpty(Catalog::company());
            $this->assertCount(8, Catalog::products());
        });
    }
}
