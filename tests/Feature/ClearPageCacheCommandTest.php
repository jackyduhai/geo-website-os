<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteCacheKey;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CMD-01 — page-cache:clear 命令防回归。
 * ------------------------------------------------------------------
 * PageCache::flush 本身已由 PageCacheTest 覆盖，但命令入口未被调用。命令的关键
 * 不变量（TD-108）：CLI 无站点上下文，必须逐站切换 SiteContext 后再 flush，
 * 否则非默认站缓存永不失效。本测试验证：默认站与非默认站版本都 +1、退出码 0、
 * 输出站点数量。
 */
class ClearPageCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private Site $default;
    private Site $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->second = Site::create([
            'name' => 'Second Site', 'slug' => 'second-site',
            'domain' => 'second.test', 'status' => 'active', 'is_default' => false,
        ]);

        // PageCache 强制使用 file 存储，版本键跨测试持久；先 forget 使 version() 回到初始 1。
        foreach ([$this->default, $this->second] as $site) {
            SiteContext::withSite($site, static function () {
                Cache::store('file')->forget(SiteCacheKey::pagecacheVersion());
            });
        }
    }

    private function version(Site $site): int
    {
        return SiteContext::withSite($site, static fn () => PageCache::version());
    }

    public function test_command_increments_cache_version_for_every_site(): void
    {
        $this->assertSame(1, $this->version($this->default));
        $this->assertSame(1, $this->version($this->second));

        $this->artisan('page-cache:clear')->assertExitCode(0);

        // 关键不变量：默认站与非默认站版本都 +1（非默认站不能被遗漏）。
        $this->assertSame(2, $this->version($this->default));
        $this->assertSame(2, $this->version($this->second));
    }

    public function test_command_reports_cleared_site_count(): void
    {
        $this->artisan('page-cache:clear')
            ->expectsOutputToContain('已清空全部 2 个站点')
            ->assertExitCode(0);
    }
}
