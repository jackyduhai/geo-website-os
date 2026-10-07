<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplatePreviewSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 模板活站预览（RC-11 G）。
 *
 * 核心不变量：**预览必须隔离，回收必须彻底，真实站点零风险**。
 *
 * 为什么隔离是硬要求：TemplateDefaultsInstaller::bootstrap() 会写
 * Setting / Menu / SeoMeta 且**无自动回滚**。在真实站点上
 * 「激活 → 看 → 回滚」等于把生产数据当实验田。
 */
class TemplateLivePreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 取当前默认站作为「真实站」。
     *
     * RefreshDatabase 的初始迁移已建好 default 站，
     * 不能再建第二个 is_default=1（sites.is_default 有唯一约束）。
     */
    private function realSite(): Site
    {
        $site = Site::withoutGlobalScopes()->where('slug', Site::DEFAULT_SLUG)->first();
        if ($site === null) {
            $this->fail('初始迁移应已创建 default 站点');
        }

        return $site;
    }

    public function test_preview_slug_recognition(): void
    {
        $this->assertTrue(TemplatePreviewSite::isPreviewSlug('tpl-preview-commerce-pro'));
        $this->assertFalse(TemplatePreviewSite::isPreviewSlug('default'));
        $this->assertFalse(TemplatePreviewSite::isPreviewSlug('site-a'));
        // 前缀相同但后面为空 → 仍视为预览站（后续 exists() 会拦住）
        $this->assertTrue(TemplatePreviewSite::isPreviewSlug('tpl-preview-'));
    }

    /**
     * 创建预览站不得影响真实站点，也不得顶替默认站。
     */
    public function test_creating_preview_site_does_not_touch_real_site(): void
    {
        $real = $this->realSite();

        $preview = TemplatePreviewSite::ensure('manufacturing-pro');

        $this->assertNotNull($preview, '应创建出预览站');
        $this->assertNotSame($real->id, $preview->id, '预览站必须是独立 site_id');
        $this->assertTrue(TemplatePreviewSite::isPreviewSlug((string) $preview->slug));
        $this->assertFalse((bool) $preview->is_default, '预览站绝不能成为默认站');

        // 真实站仍存在且仍是默认站
        $real->refresh();
        $this->assertTrue((bool) $real->is_default);
        $this->assertNotSame(
            'tpl-preview-',
            substr((string) $real->slug, 0, 12),
            '真实站 slug 不应被改成预览站前缀'
        );
    }

    /**
     * 幂等：同一 pack 重复调用不得重复建站。
     */
    public function test_ensure_is_idempotent(): void
    {
        $this->realSite();

        $a = TemplatePreviewSite::ensure('saas-pro');
        $b = TemplatePreviewSite::ensure('saas-pro');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->id, $b->id, '同一 pack 应复用同一预览站');
        $this->assertSame(
            1,
            Site::withoutGlobalScopes()->where('slug', TemplatePreviewSite::SLUG_PREFIX . 'saas-pro')->count(),
            '不应产生第二个预览站'
        );
    }

    /**
     * 骨架必须完整 —— 否则预览站前台是空页，preview 就失去意义。
     */
    public function test_preview_site_has_complete_skeleton(): void
    {
        $this->realSite();
        $preview = TemplatePreviewSite::ensure('commerce-pro');
        $this->assertNotNull($preview);

        SiteContext::withSite($preview, function () use ($preview): void {
            $this->assertGreaterThan(0, \App\Models\Setting::query()->count(), '应有站点设置');
            $this->assertGreaterThan(0, \App\Models\Page::query()->count(), '应有页面骨架');
            $this->assertGreaterThan(0, \App\Models\Category::query()->count(), '应有栏目');
            $this->assertGreaterThan(0, \App\Models\Menu::query()->count(), '应有菜单');
        });
    }

    /**
     * 回收必须彻底：该站在所有带 site_id 的表里都不能留数据。
     *
     * 这条比「站点删掉了」更重要 —— 残留的 settings / contents 会变成
     * 无人认领的孤儿数据。
     */
    public function test_discard_removes_all_owned_rows(): void
    {
        $this->realSite();
        $preview = TemplatePreviewSite::ensure('export-pro');
        $this->assertNotNull($preview);
        $pid = $preview->id;

        TemplatePreviewSite::discard($preview);
        PageCache::flush();

        $this->assertNull(
            Site::withoutGlobalScopes()->where('slug', TemplatePreviewSite::SLUG_PREFIX . 'export-pro')->first(),
            '预览站本身应被删除'
        );

        foreach (\Illuminate\Support\Facades\Schema::getTableListing() as $listed) {
            $table = \Illuminate\Support\Str::afterLast($listed, '.');
            if ($table === 'sites' || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'site_id')) {
                continue;
            }
            $left = \Illuminate\Support\Facades\DB::table($table)->where('site_id', $pid)->count();
            $this->assertSame(0, $left, "表 {$table} 仍残留 {$left} 行");
        }
    }

    /**
     * 安全边界：`discard` 只回收带预览前缀的站，
     * 绝不能因为误传而删掉真实站点。
     */
    public function test_discard_never_deletes_a_real_site(): void
    {
        $real = $this->realSite();

        TemplatePreviewSite::discard($real);

        $this->assertNotNull(
            Site::withoutGlobalScopes()->where('id', $real->id)->first(),
            '真实站点绝不能被 discard 删除'
        );
    }

    /**
     * 非法 pack 不建站。
     */
    public function test_unknown_pack_yields_no_site(): void
    {
        $this->realSite();

        $this->assertNull(TemplatePreviewSite::ensure('no-such-pack-xyz'));
        $this->assertSame(1, Site::withoutGlobalScopes()->count(), '不应留下任何站点');
    }

    /**
     * 前台 slug 通道的安全边界：**只认预览站**。
     *
     * 若放开成任意 slug，任何人都能靠 `?site_slug=` 切到别的真实站点，
     * 这正是 SiteResolver 把它限制在 admin 路径的原因。
     */
    public function test_site_resolver_only_accepts_preview_slug(): void
    {
        $this->realSite();
        Site::create([
            'name' => '另一真实站', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => Site::STATUS_ACTIVE, 'is_default' => false,
        ]);

        \App\Support\SiteContext::clear();

        // 非预览 slug → **不得解析到该真实站**。
        // 注意：host=localhost 匹配不到 site-b，会走 fallback 回 default 站
        // （这是既有行为，不是「切站」），所以判据是「拿到的不是 site-b」。
        $req = \Illuminate\Http\Request::create('http://localhost/', 'GET', ['site_slug' => 'site-b']);
        $resolved = \App\Support\SiteResolver::resolveFromRequest($req);
        $this->assertNotSame(
            'site-b',
            (string) ($resolved->slug ?? ''),
            '前台绝不能通过 site_slug 切到另一个真实站点'
        );

        // 预览 slug → 解析成功
        TemplatePreviewSite::ensure('healthcare-pro');
        \App\Support\SiteContext::clear();
        $req2 = \Illuminate\Http\Request::create(
            'http://localhost/',
            'GET',
            ['site_slug' => TemplatePreviewSite::SLUG_PREFIX . 'healthcare-pro']
        );
        $site = \App\Support\SiteResolver::resolveFromRequest($req2);
        $this->assertNotNull($site, '预览站应能经 slug 解析');
        $this->assertTrue(TemplatePreviewSite::isPreviewSlug((string) $site->slug));
    }
}
