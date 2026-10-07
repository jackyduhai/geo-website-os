<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use App\Support\RequestScopedState;
use App\Support\SiteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * C-18 · Production-Parity Integration Test —— 新建站点（SQLite 文件库 + FK 开启）
 * ------------------------------------------------------------------
 * ## 为什么必须有这个层（RC-5 暴露出的测试体系盲区）
 *
 * 既有 `SiteManagementTest` 用 `RefreshDatabase`：SQLite `:memory:` 库。
 * 该路径下 `Site::create()` 之后紧接着写 `settings`（FK → sites.id）
 * **不会**抛外键错误，所以「后台新建站点」长期显示为绿色。
 *
 * 但生产配置是 **SQLite 文件库**（`database/database.sqlite`），
 * `config/database.php:39` 的 `foreign_key_constraints` 默认 **true**，
 * 此时显式事务内的新行对 FK 校验不可见 → 必然
 * `FOREIGN KEY constraint failed` → **后台新建站点不可用**。
 *
 * `:memory:` 并不等价于生产。本类用**文件库 + 真实 migrate + FK 开启**
 * 复现生产条件，把该盲区固化成长期契约。
 *
 * ## 覆盖（SITE-REG-001 ~ 008 + 路径等价性）
 *   001 创建 Site 成功
 *   002 Site + Settings 事务成功
 *   003 SiteStructureSeeder 成功（栏目/分组/导航/语言）
 *   004 Categories / Groups / Menu 初始化完整
 *   005 zh-CN / en 配置正确
 *   006 重复 Bootstrap 不覆盖运营手工修改
 *   007 初始化失败 → 事务全回滚（不留半成品站点）
 *   008 建成后站点立即可运营（能建内容、能过 GEO 门禁）
 *   两条建站路径（CLI geo:install vs 后台 POST /admin/sites）结构等价
 */
class SiteCreationProductionParityTest extends TestCase
{
    private const DB_PATH = __DIR__ . '/../../build/tmp/site-creation-parity.sqlite';

    private ?string $originalDbPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 不用 RefreshDatabase：它按 `:memory:` 建表，与本类要验证的
        // 「文件库 + FK 开启」条件相悖。这里自己把连接切到文件库并**真实迁移**。
        $this->originalDbPath = (string) config('database.connections.sqlite.database');

        $dir = dirname(self::DB_PATH);
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir);
        }
        @unlink(self::DB_PATH);
        touch(self::DB_PATH);

        config(['database.connections.sqlite.database' => self::DB_PATH]);
        DB::purge('sqlite');

        // 真实迁移到文件库（生产同款路径）
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // 关键前提：确认 FK 已开启（生产等价，config/database.php:39 默认 true）
        $fk = (int) DB::connection()->getPdo()
            ->query('PRAGMA foreign_keys')->fetchColumn();
        $this->assertSame(1, $fk, '生产等价前提不成立：文件库未开启外键约束');
    }

    protected function tearDown(): void
    {
        RequestScopedState::flushAll();
        SiteContext::clear();

        if ($this->originalDbPath !== null) {
            config(['database.connections.sqlite.database' => $this->originalDbPath]);
            DB::purge('sqlite');
        }
        @unlink(self::DB_PATH);

        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $u = User::first();
        if (! $u) {
            $u = new User();
            $u->name = 'Super';
            $u->email = 'parity@example.com';
            $u->password = bcrypt('parity-pass');
        }
        $u->is_super_admin = 1;
        $u->save();

        return $u;
    }

    /** 走真实的 POST /admin/sites（后台建站路径） */
    private function createSiteViaAdmin(array $overrides = [])
    {
        return $this->actingAs($this->superAdmin())
            ->post(route('admin.sites.store'), array_merge([
                'name' => 'Parity Site',
                'slug' => 'parity-site',
                'domain' => 'parity.test',
                'status' => 'active',
                'description' => 'created by production-parity test',
            ], $overrides));
    }

    // ── SITE-REG-001 / 002 ───────────────────────────────────

    public function test_site_creation_succeeds_under_production_fk(): void
    {
        $response = $this->createSiteViaAdmin();

        $response->assertRedirect(route('admin.sites.index'));
        $response->assertSessionHas('success');

        $site = Site::where('slug', 'parity-site')->first();
        $this->assertNotNull($site, '站点未创建 —— 生产配置（文件库 + FK ON）下 FK 失败会导致此为空');

        $this->assertGreaterThan(
            0,
            DB::table('settings')->where('site_id', $site->id)->count(),
            'settings 未写入该站点 —— 同事务写入失败（C-18 原始症状）'
        );
    }

    // ── SITE-REG-003 / 004 ───────────────────────────────────

    public function test_structure_seeder_initializes_taxonomy_and_nav(): void
    {
        $this->createSiteViaAdmin()->assertRedirect();
        $site = Site::where('slug', 'parity-site')->firstOrFail();

        $this->assertGreaterThan(
            0,
            Category::withoutSiteScope()->where('site_id', $site->id)->count(),
            'Category 未初始化 —— 运营无法给内容归类（零代码建站契约断裂）'
        );
        $this->assertGreaterThan(0, DB::table('groups')->where('site_id', $site->id)->count(), 'Group 未初始化');
        $this->assertGreaterThan(0, DB::table('menus')->where('site_id', $site->id)->count(), 'Menu 未初始化');
    }

    // ── SITE-REG-005 ─────────────────────────────────────────

    public function test_bilingual_locales_are_enabled_for_new_site(): void
    {
        $this->createSiteViaAdmin()->assertRedirect();
        $site = Site::where('slug', 'parity-site')->firstOrFail();

        $raw = (string) DB::table('settings')
            ->where('site_id', $site->id)->where('key', 'site_supported_locales')
            ->value('value');
        $locales = json_decode($raw, true);
        $locales = is_array($locales) ? $locales : [];

        $this->assertContains('zh-CN', $locales, '默认语言 zh-CN 未启用');
        $this->assertContains('en', $locales, '英文未启用 —— /en/* 路由会严格 404');
    }

    // ── SITE-REG-006 · Bootstrap 幂等且不覆盖运营手工修改 ──

    public function test_repeat_bootstrap_preserves_manual_edits(): void
    {
        $this->createSiteViaAdmin()->assertRedirect();
        $site = Site::where('slug', 'parity-site')->firstOrFail();

        $cat = Category::withoutSiteScope()->where('site_id', $site->id)->orderBy('id')->first();
        $this->assertNotNull($cat, '无可观察的 Category');

        $catsBefore = Category::withoutSiteScope()->where('site_id', $site->id)->count();
        $groupsBefore = DB::table('groups')->where('site_id', $site->id)->count();

        // 模拟运营在后台手工改名
        $manual = '运营手工改过的栏目名';
        Category::withoutSiteScope()->where('id', $cat->id)->update(['name' => $manual]);

        // 第二次 bootstrap
        SiteContext::withSite($site, function () use ($site) {
            (new \Database\Seeders\SiteStructureSeeder())->run();
        });

        $nameAfter = (string) Category::withoutSiteScope()->where('id', $cat->id)->value('name');
        $this->assertSame(
            $manual,
            $nameAfter,
            '二次 bootstrap 覆盖了运营手工修改 —— Seeder 用了 updateOrCreate 而非 firstOrCreate'
        );

        $this->assertSame(
            $catsBefore,
            Category::withoutSiteScope()->where('site_id', $site->id)->count(),
            '二次 bootstrap 产生了重复 Category —— 幂等性被破坏'
        );
        $this->assertSame(
            $groupsBefore,
            DB::table('groups')->where('site_id', $site->id)->count(),
            '二次 bootstrap 产生了重复 Group'
        );
    }

    // ── SITE-REG-007 · 原子性 ───────────────────────────────

    public function test_failed_initialization_rolls_back_entire_site(): void
    {
        // 占位行占用 domain
        DB::table('sites')->insert([
            'name' => 'Placeholder', 'slug' => 'placeholder', 'domain' => 'parity.test',
            'status' => 'active', 'is_default' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sitesMid = DB::table('sites')->count();

        // 同一 domain 再次建站 → 应失败
        $this->createSiteViaAdmin(['slug' => 'parity-dup', 'domain' => 'parity.test']);

        $this->assertSame(
            $sitesMid,
            DB::table('sites')->count(),
            '建站失败后留下半成品站点 —— 事务未回滚'
        );
        $this->assertSame(0, DB::table('sites')->where('slug', 'parity-dup')->count(), '失败的新站点仍留在库中');

        $orphan = DB::table('settings')
            ->whereNotIn('site_id', DB::table('sites')->select('id'))
            ->count();
        $this->assertSame(0, $orphan, '出现孤儿 settings（site_id 指向不存在的站点）');
    }

    // ── SITE-REG-008 · 建成后立即可运营 ───────────────────────

    public function test_new_site_is_immediately_operable(): void
    {
        $this->createSiteViaAdmin()->assertRedirect();
        $site = Site::where('slug', 'parity-site')->firstOrFail();

        SiteContext::withSite($site, function () use ($site) {
            $cat = Category::withoutSiteScope()->where('site_id', $site->id)->orderBy('id')->first();
            $this->assertNotNull($cat);

            $gate = app(\App\Services\Gate\ContentGate::class);
            $c = new \App\Models\Content();
            $c->site_id = $site->id;
            $c->type = 'article';
            $c->title = 'Parity article';
            $c->slug = 'parity-article';
            $c->body = 'body';
            $c->status = 'published';
            $c->category_id = $cat->id;
            $c->geo_conclusion = '结论';
            $c->geo_explanation = '解释';
            $c->geo_boundary = '边界';
            // ⚠️ 必须传**数组**：Content 的 `geo_evidence` 是 `'cast' => 'array'`，
            // 但 cast 只在「从 DB 读出」时生效；直接给未保存模型赋 JSON 字符串，
            // `evidenceList()` 会拿到string 并在 array_filter() 处 TypeError。
            $c->geo_evidence = [
                ['label' => 'A', 'value' => '1', 'source' => 'src'],
                ['label' => 'B', 'value' => '2', 'source' => 'src'],
            ];
            $c->owner = 'tester';
            $c->reviewed_at = '2026-10-05';

            $result = $gate->check($c);
            $this->assertTrue(
                (bool) $result['passed'],
                '新站建成后无法通过 GEO 门禁 —— 运营无法发布首条内容: '
                . json_encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE)
            );
        });
    }

    // ── 两条建站路径等价性 ────────────────────────────────────

    public function test_cli_and_admin_paths_produce_equivalent_structure(): void
    {
        // Path B：后台建站（控制器跑完整 5 Seeder 链）
        // 注意：slug 由服务端按站名派生（RC-11），表单传入值被忽略，
        // 因此断言必须按 name 定位，不能依赖传入的 slug。
        $this->createSiteViaAdmin([
            'name' => 'Path Admin', 'slug' => 'path-admin', 'domain' => 'path-admin.test',
        ])->assertRedirect();
        $adminSite = Site::where('name', 'Path Admin')->firstOrFail();
        $adminStruct = $this->structureFingerprint($adminSite->id);

        // Path A：CLI 建站（geo:install 的那套 Seeder，不经控制器事务）
        $cliOnly = Site::create([
            'name' => 'CLI Only', 'slug' => 'path-cli', 'domain' => 'path-cli.test',
            'status' => 'active', 'is_default' => false,
        ]);
        SiteContext::withSite($cliOnly, function () use ($cliOnly) {
            (new \Database\Seeders\DefaultSettingSeeder())->run();
            (new \Database\Seeders\DefaultFormSeeder())->run();
            (new \Database\Seeders\BlankHomepageSeeder($cliOnly))->run();
            (new \Database\Seeders\SystemPageSeeder($cliOnly))->run();
            (new \Database\Seeders\SiteStructureSeeder())->run();
        });
        $cliStruct = $this->structureFingerprint($cliOnly->id);

        foreach (['categories', 'groups', 'menus', 'settings', 'locales'] as $asset) {
            $this->assertArrayHasKey($asset, $cliStruct);
            $this->assertGreaterThan(
                0,
                $cliStruct[$asset],
                "CLI 路径缺资产：$asset （两条建站路径不等价）"
            );
        }

        $this->assertSame(
            $adminStruct['categories'],
            $cliStruct['categories'],
            '两条建站路径的 Category 数量不一致 —— 未来会再次出现「CLI 正常、后台失败」'
        );
    }

    /** 结构指纹：只取「有哪些资产、多少条」，不比具体文案 */
    private function structureFingerprint(int $siteId): array
    {
        $rawLocales = DB::table('settings')
            ->where('site_id', $siteId)->where('key', 'site_supported_locales')
            ->value('value');
        $locales = json_decode((string) $rawLocales, true);

        return [
            'categories' => Category::withoutSiteScope()->where('site_id', $siteId)->count(),
            'groups'     => (int) DB::table('groups')->where('site_id', $siteId)->count(),
            'menus'      => (int) DB::table('menus')->where('site_id', $siteId)->count(),
            'settings'   => (int) DB::table('settings')->where('site_id', $siteId)->count(),
            'locales'    => is_array($locales) ? count($locales) : 0,
        ];
    }
}
