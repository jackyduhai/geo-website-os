<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP3 Bug Hunt：多站生产模式（SITE_DEFAULT_FALLBACK=false）下，
 * 未匹配任何 active 站点的 Host（未知域名 / 停用站点域名）必须在 HTTP 层被拒绝（404），
 * 不得因 SiteContext::currentSite() 的单站惰性兜底而把真实 default 站点内容返回给未知 Host。
 *
 * 单站 / 开发模式（fallback=true）保持未知 Host 回退 default site 的兼容行为。
 */
class UnknownHostRejectionTest extends TestCase
{
    use RefreshDatabase;

    private function ensureDefaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => Site::DEFAULT_SLUG],
            ['name' => 'Default Site', 'domain' => 'localhost', 'status' => 'active', 'is_default' => true]
        );
    }

    public function test_multi_site_unknown_host_is_rejected_with_404(): void
    {
        config(['site.default_fallback' => false]);
        $this->ensureDefaultSite();

        $this->get('http://unknown-evil.test/')->assertNotFound();
    }

    public function test_multi_site_inactive_host_is_rejected_with_404(): void
    {
        config(['site.default_fallback' => false]);
        $this->ensureDefaultSite();
        Site::create([
            'name' => 'Inactive', 'slug' => 'inactive', 'domain' => 'inactive.test',
            'status' => 'inactive', 'is_default' => false,
        ]);

        $this->get('http://inactive.test/')->assertNotFound();
    }

    public function test_multi_site_known_active_host_serves_normally(): void
    {
        config(['site.default_fallback' => false]);
        $this->ensureDefaultSite();
        Site::create([
            'name' => 'Known', 'slug' => 'known', 'domain' => 'known.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $this->get('http://known.test/')->assertOk();
    }

    public function test_single_site_unknown_host_falls_back_to_default(): void
    {
        config(['site.default_fallback' => true]);
        $this->ensureDefaultSite();

        $this->get('http://anything.test/')->assertOk();
    }
}
