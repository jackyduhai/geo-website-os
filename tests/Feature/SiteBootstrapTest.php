<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20G-7.1 · Site Lifecycle 契约（SAL-001~012）
 * ------------------------------------------------------------------
 * 覆盖「新站建好 → 能立刻运营」的完整链路，以及多站隔离：
 *
 *   UX-002Bootstrap：Settings / Categories / Groups / 导航可见性 / 语言开关
 *   UX-001  已由 SiteAdminCrudTest 覆盖，本类只补「与 Bootstrap 的衔接」
 *
 * 与 `docs/audit/20G/e2e/site-lifecycle-e2e.php` 的分工：
 *   本类= **常驻 CI 契约**（快、覆盖语义不变量）
 *   E2E   = **端到端证据**（真实 HTTP、覆盖 Host 解析与 GEO 输出）
 *
 * 断言一律用「真实写入 + 真实读取」，
 * 不用「Seeder 源码里有没有某行」这类恒真判据（20G-3 / 20G-8 教训）。
 */
class SiteBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteA = Site::query()->where('is_default', true)->first();
        $this->siteA->update(['slug' => 'site-a', 'name' => 'A 站', 'domain' => 'a.test']);

        $this->siteB = Site::query()->create([
            'slug' => 'site-b', 'name' => 'B 站', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $this->bootstrap($this->siteA);
        $this->bootstrap($this->siteB);
    }

    /** 复刻 SiteController@store 的 Bootstrap 序列 */
    private function bootstrap(Site $site): void
    {
        SiteContext::setSite($site);
        Setting::flush();
        Catalog::flush();
        RequestScopedState::flushAll();

        try {
            (new \Database\Seeders\DefaultSettingSeeder())->run();
            (new \Database\Seeders\DefaultFormSeeder())->run();
            (new \Database\Seeders\BlankHomepageSeeder($site))->run();
            (new \Database\Seeders\SystemPageSeeder($site))->run();
            (new \Database\Seeders\SiteStructureSeeder())->run();
        } finally {
            // ⚠️ 刻意**不断开DB 连接**：`RefreshDatabase` 靠同一个连接上的事务
            // 保证用例间隔离，disconnect 会让后续查询逃出事务而报错。
            // 站点隔离靠 SiteScope（查询条件）+ SiteContext（连接目标），不靠断连。
            Setting::flush();
            Catalog::flush();
            \App\Support\Localization\LocaleContext::clear();
            SiteContext::clear();
            RequestScopedState::flushAll();
        }
    }

    private function inSite(Site $site, callable $fn)
    {
        SiteContext::setSite($site);
        Setting::flush();
        Catalog::flush();
        RequestScopedState::flushAll();

        try {
            return $fn();
        } finally {
            // ⚠️ 刻意**不断开DB 连接**：`RefreshDatabase` 靠同一个连接上的事务
            // 保证用例间隔离，disconnect 会让后续查询逃出事务而报错。
            // 站点隔离靠 SiteScope（查询条件）+ SiteContext（连接目标），不靠断连。
            Setting::flush();
            Catalog::flush();
            \App\Support\Localization\LocaleContext::clear();
            SiteContext::clear();
            RequestScopedState::flushAll();
        }
    }

    /** SAL-001  Categories：出厂即有行业中立栏目骨架 */
    public function test_sal_001_bootstrap_creates_category_skeleton(): void
    {
        $slugs = $this->inSite($this->siteB, fn () => Category::query()->pluck('slug')->sort()->values()->all());

        foreach (['about', 'contact', 'knowledge', 'news', 'products'] as $expected) {
            $this->assertContains($expected, $slugs,
                "新站出厂应含栏目 {$expected}（否则后台建内容时栏目下拉为空）");
        }
    }

    /** SAL-002  栏目归属正确（写入落到了目标站） */
    public function test_sal_002_categories_belong_to_target_site(): void
    {
        $this->inSite($this->siteB, function (): void {
            $cat = Category::query()->where('slug', 'about')->firstOrFail();
            $this->assertSame($this->siteB->id, (int) $cat->site_id,
                '栏目必须写入请求的目标站（防「以为在管 B、实际写进 A」）');
        });

        $bCount = \Illuminate\Support\Facades\DB::table('categories')
            ->where('site_id', $this->siteB->id)->count();
        $aCount = \Illuminate\Support\Facades\DB::table('categories')
            ->where('site_id', $this->siteA->id)->count();

        $this->assertGreaterThanOrEqual(5, $bCount, 'B 站应有 5 个栏目');
        $this->assertGreaterThanOrEqual(5, $aCount, 'A 站应有 5 个栏目');
    }

    /** SAL-003  Groups：知识中心 / 新闻动态 各带子分组 */
    public function test_sal_003_bootstrap_creates_groups(): void
    {
        $count = $this->inSite($this->siteB, fn () => Group::query()->count());

        $this->assertGreaterThanOrEqual(4, $count, '新站出厂应含至少 4 个内容分组');
    }

    /**
     * SAL-003b  分组名必须符合出厂模板（不只是「有分组」）
     *
     * ⚠️ 只断言「分组数 ≥4」抓不到内容污染（20G-7.1 变异测试实测）：
     * 变异把分组名硬编码成常量后行数不变、key 也不变，只有内容错了。
     */
    public function test_sal_003b_group_names_match_factory_template(): void
    {
        $names = $this->inSite($this->siteB, fn () => Group::query()->orderBy('slug')->pluck('name')->all());
        sort($names);

        $expected = ['常见问题', '使用指南', '企业新闻', '通知公告'];
        sort($expected);

        $this->assertSame($expected, $names,
            '分组名必须符合出厂模板（内容正确，不只是数量对）');
    }

    /** SAL-004  导航可见性：工业制造口径项（工厂与资质）默认关闭 */
    public function test_sal_004_industrial_nav_is_disabled_by_default(): void
    {
        $factory = $this->inSite($this->siteB, fn () => Menu::query()
            ->where('key', 'factory')->where('position', 'main')->first());

        $this->assertNotNull($factory,
            '应存在 factory 的可见性 override（menus 表 key 非空 = 覆盖内置项）');
        $this->assertFalse((bool) $factory->is_active,
            '「工厂与资质」是工业制造口径，对多数站点不适用，出厂应关闭');
    }

    /** SAL-005  语言：出厂即 Multi-language（zh-CN + en） */
    public function test_sal_005_bootstrap_enables_bilingual(): void
    {
        $locales = $this->inSite($this->siteB, fn () => Setting::get('site_supported_locales', []));

        $this->assertIsArray($locales, 'site_supported_locales 应为数组');
        $this->assertContains('zh-CN', $locales, '必须支持 zh-CN');
        $this->assertContains('en', $locales,
            '新站出厂应即支持 en（否则运营会认为英文功能坏了；产品已定 Multi-language）');
    }

    /** SAL-006  幂等：重跑不重复创建 */
    public function test_sal_006_bootstrap_is_idempotent_no_duplicate_rows(): void
    {
        $before = $this->inSite($this->siteB, fn () => [
            'cats'   => Category::query()->count(),
            'groups' => Group::query()->count(),
            'menus'  => Menu::query()->count(),
        ]);

        $this->bootstrap($this->siteB);
        $this->bootstrap($this->siteB);

        $after = $this->inSite($this->siteB, fn () => [
            'cats'   => Category::query()->count(),
            'groups' => Group::query()->count(),
            'menus'  => Menu::query()->count(),
        ]);

        $this->assertSame($before, $after, '重复执行 bootstrap 不得改变行数');
    }

    /**
     * SAL-007  幂等（关键）：重跑**不覆盖人工修改**。
     *
     * ⚠️ 这是 `firstOrCreate` 与 `updateOrCreate` 的分水岭：
     * updateOrCreate 的实现是
     *   `firstOrCreate(...)` 后接 `if (! wasRecentlyCreated) fill($values)->save()`
     * —— 已存在的行会被 values 覆盖，重跑 bootstrap 就把运营改过的
     * 栏目名重置回出厂值。20G-7.1 实测 BS-008 抓到过这个缺陷。
     */
    public function test_sal_007_bootstrap_does_not_overwrite_manual_edits(): void
    {
        $this->inSite($this->siteB, function (): void {
            Category::query()->where('slug', 'about')->update(['name' => '人工改过的名字']);
        });

        $this->bootstrap($this->siteB);

        $name = $this->inSite($this->siteB, fn () => Category::query()
            ->where('slug', 'about')->value('name'));

        $this->assertSame('人工改过的名字', $name,
            '重跑 bootstrap 不得覆盖人工修改（初始化是补齐缺项，不是重置出厂）');
    }

    /** SAL-008  建完站立刻能建内容（零代码建站契约） */
    public function test_sal_008_can_create_content_right_after_bootstrap(): void
    {
        $content = $this->inSite($this->siteB, function () {
            $cat = Category::query()->where('slug', 'news')->firstOrFail();

            return Content::create([
                'type' => 'article', 'category_id' => $cat->id,
                'title' => 'B 站首篇', 'slug' => 'b-first',
                'summary' => '摘要', 'body' => '正文',
                'status' => 'published', 'published_at' => now()->subDay(),
                'locale' => 'zh-CN', 'translation_group' => 'TG-B-1',
            ]);
        });

        $this->assertTrue($content->exists);
        $this->assertSame($this->siteB->id, (int) $content->site_id);
    }

    /**
     * SAL-009  多站隔离：相同 slug 可共存，且互不串。
     */
    public function test_sal_009_same_slug_coexists_across_sites(): void
    {
        $dups = \Illuminate\Support\Facades\DB::table('categories')
            ->select('slug', \Illuminate\Support\Facades\DB::raw('COUNT(*) c'))
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertNotEmpty($dups,
            '两站都bootstrap 了相同 slug，说明 UNIQUE 含 site_id（多站可安全用同一 slug）');
    }

    /** SAL-010  A 站写入不推进 B 站缓存版本 */
    public function test_sal_010_cache_version_is_per_site(): void
    {
        $bBefore = $this->inSite($this->siteB, fn () => PageCache::version());

        $this->inSite($this->siteA, function (): void {
            Setting::set('nav_cta_text', 'A 站专属');
        });

        $bAfter = $this->inSite($this->siteB, fn () => PageCache::version());

        $this->assertSame($bBefore, $bAfter, 'A 站写入不得推进 B 站的页面缓存版本');
    }

    /**
     * SAL-011  停用站点不参与前台解析
     *
     * 判定用「B 站标记是否消失」而非状态码：`ResolveSite` 过滤 status=active
     * 后会**回落默认站**（这是刻意的可用性设计，不是不解析），
     * 所以状态码仍是 200。可区分的信号是「响应内容属于哪个站」。
     */
    public function test_sal_011_inactive_site_resolves_to_default(): void
    {
        $this->inSite($this->siteA, function (): void {
            Setting::set('site_name', 'A-STATION-MARKER');
        });
        $this->inSite($this->siteB, function (): void {
            Setting::set('site_name', 'B-STATION-MARKER');
        });

        $active = $this->get('http://b.test/')->getContent();
        $this->assertStringContainsString('B-STATION-MARKER', (string) $active,
            '启用状态下 b.test 应解析到 B 站');

        $this->siteB->update(['status' => 'inactive']);
        $this->app['cache']->clear();

        $after = $this->get('http://b.test/')->getContent();
        $this->assertStringNotContainsString('B-STATION-MARKER', (string) $after,
            '停用后不得再解析到 B 站（应回落默认站）');
        $this->assertStringContainsString('A-STATION-MARKER', (string) $after,
            '停用后应回落到默认站 A');
    }

    /**
     * SAL-012  两条建站路径等价。
     *
     * `geo:install`（命令行装站）与 `SiteController@store`（后台建站）
     * 必须用同一个 `SiteStructureSeeder`，否则两条路径能力不一致 ——
     * 20G-7.1 实测曾发现 default 站 0 栏目、且 /en 404。
     */
    public function test_sal_012_install_and_admin_paths_share_bootstrap(): void
    {
        foreach (['app/Console/Commands/GeoInstall.php', 'app/Http/Controllers/Admin/SiteController.php'] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertStringContainsString('SiteStructureSeeder', (string) $src,
                "{$file} 必须调用 SiteStructureSeeder —— 两条建站路径要产出等价结构");
        }
    }
}
