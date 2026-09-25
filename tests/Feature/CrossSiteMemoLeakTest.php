<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP3 状态泄漏专项：同一应用实例内连续跨站 HTTP 请求时，
 * 请求级 static memo（Setting / nav / Fact / Group / Narrative / Copy /
 * Theme / SeoMetaResolver 等）必须随站点切换复位。
 *
 * 覆盖 PHP-FPM 之外的常驻 / 复用进程形态：Octane、同一测试进程内的多次请求、
 * 以及未来常驻 queue worker 跨站 Job。A 站视图数据绝不能泄漏给 B 站。
 */
class CrossSiteMemoLeakTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(string $slug, string $domain, string $name): Site
    {
        return Site::create([
            'name'       => $name,
            'slug'       => $slug,
            'domain'     => $domain,
            'status'     => 'active',
            'is_default' => false,
        ]);
    }

    public function test_consecutive_cross_site_requests_do_not_leak_settings(): void
    {
        $a = $this->makeSite('site-a', 'a-memo.test', 'Site A');
        $b = $this->makeSite('site-b', 'b-memo.test', 'Site B');

        SiteContext::withSite($a, fn () => Setting::set('site_name', 'AAA_SITE_MARKER'));
        SiteContext::withSite($b, fn () => Setting::set('site_name', 'BBB_SITE_MARKER'));

        // 同一应用实例内 A -> B -> A -> B -> A -> B 反复切换，每次都必须只看到本站设置
        $expect = [
            ['http://a-memo.test/', 'AAA_SITE_MARKER', 'BBB_SITE_MARKER'],
            ['http://b-memo.test/', 'BBB_SITE_MARKER', 'AAA_SITE_MARKER'],
        ];
        for ($i = 0; $i < 3; $i++) {
            foreach ($expect as [$url, $see, $dontSee]) {
                $this->get($url)->assertOk()->assertSee($see)->assertDontSee($dontSee);
            }
        }
    }

    public function test_theme_resolves_per_site_across_consecutive_requests(): void
    {
        $this->makeSite('theme-a', 'a-theme.test', 'Theme A');
        $this->makeSite('theme-b', 'b-theme.test', 'Theme B');

        // B 站激活 example 主题，A 站保持 default
        SiteContext::withSite(
            Site::where('domain', 'b-theme.test')->first(),
            fn () => \App\Support\Theme\ThemeManager::activate('example')
        );

        // 连续 A -> B -> A -> B：激活主题（含视觉 token）必须随每次请求的站点解析，
        // 不得把上一请求 / boot 阶段（default）的主题残留给下一站点。
        $this->get('http://a-theme.test/')->assertOk()
            ->assertDontSee('data-theme="example"', false)
            ->assertSee('data-theme="default"', false);
        $this->get('http://b-theme.test/')->assertOk()
            ->assertSee('data-theme="example"', false)
            ->assertSee('--brand: #7C3AED', false);
        $this->get('http://a-theme.test/')->assertOk()
            ->assertDontSee('data-theme="example"', false)
            ->assertSee('data-theme="default"', false);
        $this->get('http://b-theme.test/')->assertOk()
            ->assertSee('data-theme="example"', false)
            ->assertSee('--brand: #7C3AED', false);
    }

    public function test_state_recovers_after_a_404_between_sites(): void
    {
        $a = $this->makeSite('rec-a', 'a-rec.test', 'Rec A');
        $b = $this->makeSite('rec-b', 'b-rec.test', 'Rec B');

        SiteContext::withSite($a, fn () => Setting::set('site_name', 'AAA_REC_MARKER'));
        SiteContext::withSite($b, fn () => Setting::set('site_name', 'BBB_REC_MARKER'));

        // A 正常 -> B 一个不存在路径（404，中间件 finally 仍需清理）-> B 正常首页
        $this->get('http://a-rec.test/')->assertOk()->assertSee('AAA_REC_MARKER');
        $this->get('http://b-rec.test/this-route-does-not-exist')->assertNotFound();
        $this->get('http://b-rec.test/')->assertOk()
            ->assertSee('BBB_REC_MARKER')
            ->assertDontSee('AAA_REC_MARKER');
    }
}
