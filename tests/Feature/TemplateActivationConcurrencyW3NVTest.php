<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\PageCacheInvalidation;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRegistry;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Tests\Support\SchemaInspector;
use Tests\TestCase;

/**
 * W3 方案丙：模板激活「串行化一致性」单进程验证（SQLite :memory:）。
 * ------------------------------------------------------------------
 * 证据等级（重要，勿误读）：
 *  - phpunit 使用 sqlite :memory:，单一进程、单一连接，无法真实同时跑两个激活。
 *    本文件**不**声称复现真实多进程并发。
 *  - 真实跨进程并发（站点行锁、REPEATABLE READ、唯一键冲突、缓存恢复）由独立
 *    MySQLNV_C 多进程 harness 验证，权威证据见 f2-evidence/20261022-092314-reverify
 *    （6 worker 全 exit 0、无 1062、终态一致）。
 *
 * 覆盖（单进程可验证的契约）：
 *  - NV-0：page_blocks / pages / settings 唯一约束对比（DB 结构级）。
 *  - NV-1：同包重复激活幂等（受管块不翻倍、无同槽位同 index 重复）。
 *  - NV-2：切换不同包后首页为单一一致状态（接管旧包受管块，不混合）。
 *  - NV-3：失败激活原子回滚无残留（marker/块/注册/审计/意图），重试成功。
 *
 * 本文件只修改测试，不修改生产代码、配置或基线。
 */
class TemplateActivationConcurrencyW3NVTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private const PACK = 'manufacturing-pro';
    private const OTHER = 'saas-pro';

    private Site $site;
    private User $super;

    /** 中途异常注入是否已触发（一次性）。 */
    private bool $fired = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        $this->seed(DefaultFormSeeder::class);
        $this->seed(SystemPageSeeder::class);

        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->super = User::create([
            'name' => '超管',
            'email' => 'admin@example.com',
            'password' => bcrypt('Admin@123456'),
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    // =====================================================================
    // NV-0：数据库唯一约束对比（结构级 / 确定性插入对照）
    // =====================================================================

    public function test_nv0_page_blocks_lack_unique_constraint_unlike_pages_and_settings(): void
    {
        $inspector = new SchemaInspector();

        // --- 结构断言：读取各表真实唯一索引包含的列集合 ---
        $blockUniques = $inspector->uniqueIndexColumnSets('page_blocks');
        $pageUniques = $inspector->uniqueIndexColumnSets('pages');
        $settingUniques = $inspector->uniqueIndexColumnSets('settings');

        // page_blocks：不存在任何包含 slot 或 page_id 的唯一集（_recipe 标记在 content JSON 里，不是列）。
        $this->assertSame([], array_values(array_filter($blockUniques, function (array $cols) {
            return in_array('slot', $cols, true) || in_array('page_id', $cols, true);
        })), 'page_blocks 不应在 slot/page_id 上存在唯一约束，但实际唯一集为：'.json_encode($blockUniques));

        // pages：存在 (site_id, slug, locale) 唯一。
        $this->assertTrue($this->hasColumnSet($pageUniques, ['site_id', 'slug', 'locale']),
            'pages 应存在 (site_id, slug, locale) 唯一约束，实际：'.json_encode($pageUniques));

        // settings：存在 (site_id, key) 唯一。
        $this->assertTrue($this->hasColumnSet($settingUniques, ['site_id', 'key']),
            'settings 应存在 (site_id, key) 唯一约束，实际：'.json_encode($settingUniques));

        // --- 行为对照：page_blocks 同 marker 槽位可直接插两条，DB 不拦截 ---
        $home = $this->page('zh-CN', home: true);
        $base = [
            'site_id' => $this->site->id,
            'page_id' => $home->id,
            'page' => 'home',
            'slot' => 'main',
            'type' => 'hero',
            'is_active' => true,
            'limit' => 0,
            'sort' => 1,
            'content' => json_encode(['_recipe' => 'nv-probe.dup', '_recipe_index' => 0], JSON_UNESCAPED_UNICODE),
        ];
        PageBlock::create($base);
        PageBlock::create($base); // 完全相同的 (page_id, slot, marker, index) —— 无约束时应成功
        $dupCount = $this->markerBlocksOnPage($home, 'nv-probe.dup')->count();
        $this->assertSame(2, $dupCount, 'page_blocks 对相同配方标记槽位不设唯一约束，两条应都落库（重复风险无 DB 兜底）');

        // --- 对照：settings 同 (site_id, key) 第二次插入被 DB 拒绝 ---
        $settingsDup = $this->throwsQueryException(function () {
            DB::table('settings')->insert([
                'site_id' => $this->site->id,
                'key' => 'nv_unique_probe',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('settings')->insert([
                'site_id' => $this->site->id,
                'key' => 'nv_unique_probe',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertTrue($settingsDup, 'settings 重复 (site_id,key) 应触发唯一约束 QueryException');

        // --- 对照：pages 同 (site_id, slug, locale) 第二次插入被 DB 拒绝 ---
        $pagesDup = $this->throwsQueryException(function () {
            $row = [
                'site_id' => $this->site->id,
                'template' => 'landing',
                'slug' => 'nv-dup-probe',
                'locale' => 'zh-CN',
                'is_home' => false,
                'is_system' => false,
                'system_key' => null,
                'status' => Page::STATUS_PUBLISHED,
                'title' => 'probe',
            ];
            Page::create($row);
            Page::create($row);
        });
        $this->assertTrue($pagesDup, 'pages 重复 (site_id,slug,locale) 应触发唯一约束 QueryException');
    }

    // =====================================================================
    // NV-1：同包重复激活幂等（受管块不翻倍）
    // =====================================================================

    public function test_nv1_same_pack_repeated_activation_is_idempotent(): void
    {
        $this->activateViaHttp(self::PACK)->assertRedirect();
        $this->activateViaHttp(self::PACK)->assertRedirect();

        // 激活态一致。
        $this->assertSame(self::PACK, Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));

        // 受管块数量不随重复激活翻倍。
        $this->assertSame(10, $this->markerBlocks('manufacturing-pro.about')->count(),
            'about 受管块应为 5×2=10（不翻倍）');
        $this->assertSame(2, $this->markerBlocks('manufacturing-pro.contact')->count(),
            'contact 受管块应为 1×2=2');
        $this->assertSame(14, $this->markerBlocks('manufacturing-pro.homepage')->count(),
            'homepage 受管块应为 7×2=14');
        $this->assertSame(4, $this->markerBlocks('manufacturing-pro.products')->count(),
            'products 受管块应为 2×2=4');

        // 无同槽位同 index 的重复区块。
        $aboutZh = $this->page('zh-CN', slug: 'about-company');
        $homeZh = $this->page('zh-CN', home: true);
        $this->assertSame([], $this->duplicateKeys($this->markerBlocksOnPage($aboutZh, 'manufacturing-pro.about')),
            'about zh-CN 不应存在同槽位同 index 重复');
        $this->assertSame([], $this->duplicateKeys($this->markerBlocksOnPage($homeZh, 'manufacturing-pro.homepage')),
            'homepage zh-CN 不应存在同槽位同 index 重复');

        // 页面不重复。
        $this->assertSame(1, Page::where('site_id', $this->site->id)
            ->where('slug', 'about-company')->where('locale', 'zh-CN')->count());
        $this->assertSame(1, Page::where('site_id', $this->site->id)
            ->where('slug', 'about-company')->where('locale', 'en')->count());
    }

    // =====================================================================
    // NV-2：切换不同包后首页为单一一致状态（不混合）
    // =====================================================================

    public function test_nv2_switching_packs_results_in_single_consistent_state(): void
    {
        // 一个无 _recipe 标记的人工块（首页 main，sort 99）。
        $homeZh = $this->page('zh-CN', home: true);
        PageBlock::create([
            'site_id' => $this->site->id,
            'page_id' => $homeZh->id,
            'page' => 'home',
            'slot' => 'main',
            'type' => 'rich_text',
            'is_active' => true,
            'limit' => 0,
            'sort' => 99,
            'content' => json_encode(['title' => '人工自定义区块', 'body' => '必须保留'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->activateViaHttp(self::PACK)->assertRedirect();  // manufacturing
        $this->activateViaHttp(self::OTHER)->assertRedirect(); // saas

        // 激活态最终指向 saas-pro。
        $this->assertSame(self::OTHER, Setting::get(TemplatePackageManager::SETTINGS_KEY, ''),
            '切换后激活态应为 saas-pro');

        // 首页被 saas 接管：仅 saas 7×2=14 块，manufacturing 首页块已清除。
        $this->assertSame(14, $this->markerBlocks('saas-pro.homepage')->count(),
            'saas 首页块应为 7×2=14');
        $this->assertSame(0, $this->markerBlocks('manufacturing-pro.homepage')->count(),
            'manufacturing 首页块应被接管清除，不混合');

        // 首页 hero 仅 1 个（两套并存才会出现 2 个）。
        $this->assertSame(1, $homeZh->blocks()->get()->where('slot', 'main')->where('type', 'hero')->count(),
            '首页应只保留一个 hero（不混合）');

        // 人工块保留。
        $this->assertNotNull($this->manualBlock($homeZh), '切换后人工块必须保留');

        // 非目标页保留：saas 仅含 homepage，about 仍是 manufacturing 内容。
        $this->assertSame(10, $this->markerBlocks('manufacturing-pro.about')->count(),
            'saas 未触及的 about 页应保留 manufacturing 内容');
    }

    // =====================================================================
    // NV-3：失败激活原子回滚无残留，重试成功
    // =====================================================================

    public function test_nv3_failed_activation_leaves_no_residue_and_retry_succeeds(): void
    {
        // 人工块（全程必须保留）。
        $homeZh = $this->page('zh-CN', home: true);
        PageBlock::create([
            'site_id' => $this->site->id,
            'page_id' => $homeZh->id,
            'page' => 'home',
            'slot' => 'main',
            'type' => 'rich_text',
            'is_active' => true,
            'limit' => 0,
            'sort' => 99,
            'content' => json_encode(['title' => '人工自定义区块', 'body' => '必须保留'], JSON_UNESCAPED_UNICODE),
        ]);

        // --- 首次激活：contact 首个块确定性抛异常 ---
        $this->armFailure('contact');
        $response = $this->activateViaHttp(self::PACK);
        $response->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($this->fired, '中途失败注入必须被触发');

        // --- 原子回滚：无任何残留（marker / 区块 / 注册 / 意图 / 审计）---
        $this->assertSame('', Setting::get(TemplatePackageManager::SETTINGS_KEY, ''),
            '回滚后激活态不应残留');
        $this->assertSame(0, $this->markerBlocks('manufacturing-pro.about')->count(), 'about 不应残留');
        $this->assertSame(0, $this->markerBlocks('manufacturing-pro.contact')->count(), 'contact 不应残留');
        $this->assertSame(0, $this->markerBlocks('manufacturing-pro.homepage')->count(), 'homepage 不应残留');
        $this->assertSame(0, $this->markerBlocks('manufacturing-pro.products')->count(), 'products 不应残留');
        $this->assertFalse(TemplateRegistry::has('mfg-home'), '回滚后包级模板注册应清空');
        $this->assertNull(Setting::get(PageCacheInvalidation::PENDING_KEY), '待失效意图不应残留');
        $this->assertSame(0, $this->auditCount('template.activate'), '回滚不应留下成功审计');
        $this->assertSame(1, $this->auditCount('template.activate_failed'), '应记录一次失败审计');
        $this->assertNotNull($this->manualBlock($homeZh), '人工块在回滚后必须保留');

        // --- 重试（一次性注入已失效）：成功，全部配方落地 ---
        $this->activateViaHttp(self::PACK)->assertRedirect();
        $this->assertSame(self::PACK, Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));
        $this->assertSame(10, $this->markerBlocks('manufacturing-pro.about')->count());
        $this->assertSame(2, $this->markerBlocks('manufacturing-pro.contact')->count());
        $this->assertSame(14, $this->markerBlocks('manufacturing-pro.homepage')->count());
        $this->assertSame(4, $this->markerBlocks('manufacturing-pro.products')->count());
        $this->assertTrue(TemplateRegistry::has('mfg-home'));
        $this->assertNull(Setting::get(PageCacheInvalidation::PENDING_KEY));
        $this->assertNotNull($this->manualBlock($homeZh), '成功后人工块仍须保留');
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    /** 激活走真实 HTTP（控制器 + 统一激活服务）。 */
    private function activateViaHttp(string $pack)
    {
        return $this->actingAs($this->super)
            ->post(route('admin.templates.activate', ['pack' => $pack]));
    }

    /** 在首个 content 含 "{pack}.{recipeKey}" 的块保存时一次性抛异常。 */
    private function armFailure(string $recipeKey): void
    {
        $this->fired = false;
        $needle = self::PACK.'.'.$recipeKey;
        PageBlock::saving(function (PageBlock $pb) use ($needle) {
            if ($this->fired) {
                return;
            }
            if (str_contains((string) $pb->content, $needle)) {
                $this->fired = true;
                throw new \RuntimeException('NV injected mid-activation failure');
            }
        });
    }

    /** 取某页（按 locale + home/system/page 维度）。 */
    private function page(string $locale, ?string $slug = null, bool $home = false, ?string $systemKey = null): Page
    {
        $q = Page::where('site_id', $this->site->id)->where('locale', $locale);
        if ($home) {
            $q->where('is_home', true);
        }
        if ($systemKey !== null) {
            $q->where('is_system', true)->where('system_key', $systemKey);
        }
        if ($slug !== null) {
            $q->where('is_system', false)->where('slug', $slug);
        }

        return $q->firstOrFail();
    }

    /** 全站范围内某 marker 的配方块。 */
    private function markerBlocks(string $marker): Collection
    {
        return PageBlock::where('site_id', $this->site->id)->get()
            ->filter(fn (PageBlock $pb) => ($pb->cfg()['_recipe'] ?? null) === $marker)
            ->values();
    }

    /** 某页上某 marker 的配方块。 */
    private function markerBlocksOnPage(Page $page, string $marker): Collection
    {
        return $page->blocks()->get()
            ->filter(fn (PageBlock $pb) => ($pb->cfg()['_recipe'] ?? null) === $marker)
            ->values();
    }

    /** 找首页人工块（sort=99、无 _recipe 标记）。 */
    private function manualBlock(Page $home): ?PageBlock
    {
        return $home->blocks()->get()->first(function (PageBlock $pb) {
            return (int) $pb->sort === 99 && ($pb->cfg()['_recipe'] ?? null) === null;
        });
    }

    /**
     * 返回一组块中，同 (slot, _recipe_index) 出现超过一次的键。
     *
     * @return array<int,string>
     */
    private function duplicateKeys(Collection $blocks): array
    {
        $counts = [];
        foreach ($blocks as $pb) {
            $key = $pb->slot.':'.(int) ($pb->cfg()['_recipe_index'] ?? -1);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_keys(array_filter($counts, fn ($c) => $c > 1));
    }

    /** 某 action 的审计记录数。 */
    private function auditCount(string $action): int
    {
        return (int) AuditLog::where('action', $action)->count();
    }

    /** 判断唯一索引集合中是否包含（忽略列顺序）给定列集。 */
    private function hasColumnSet(array $sets, array $expected): bool
    {
        $expected = $this->sortColumns($expected);
        foreach ($sets as $cols) {
            if ($this->sortColumns($cols) === $expected) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,string> $cols @return array<int,string> */
    private function sortColumns(array $cols): array
    {
        $cols = array_values($cols);
        sort($cols);

        return $cols;
    }

    private function throwsQueryException(callable $fn): bool
    {
        try {
            $fn();
        } catch (QueryException) {
            return true;
        }

        return false;
    }
}
