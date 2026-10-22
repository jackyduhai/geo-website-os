<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\PageCacheInvalidation;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRegistry;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W3-T2 — 多 recipe 激活「事务原子回滚 + 失败恢复」验证（纯测试，不改生产代码）。
 *
 * 背景：正式激活路径已统一为 TemplateActivationService（单一数据库事务 + 站点行锁 +
 * 提交后可恢复缓存失效）。本测试使用真实包 manufacturing-pro（4 个 recipe，glob 顺序
 * about/contact/homepage/products），在 PageBlock 模型 saving 事件上确定性注入一次
 * 运行时异常（等价于"第二个 recipe 落库时存储层抛错"），核验新契约：
 *
 *   1) 失败后**原子回滚**：激活态不残留、各 recipe 区块均不落地、包级模板注册被
 *      恢复、待失效意图不残留、无成功审计、有且仅有一次失败审计；
 *   2) 控制器按契约捕获异常并返回重定向 error（不再外抛未处理异常）；
 *   3) 移除注入后再次走真实 HTTP 激活：全部 recipe 幂等补齐、页面不重复、
 *      人工区块（无 _recipe 标记）始终不被覆盖；
 *   4) 第三次激活不再新增任何区块（可重复执行）；
 *   5) 最终前台双语页面 200 且内容正确（缓存最终一致）。
 *
 * 证据等级：单进程 SQLite 仅验证原子回滚/幂等/一致性，不代表真实跨进程并发；
 * 真实锁与隔离证据以 MySQLNV_C 多 worker 场景（F2）为准。
 */
class TemplateActivationRecoveryW3T2Test extends TestCase
{
    use RefreshDatabase;

    private const PACK = 'manufacturing-pro';

    protected Site $site;
    protected User $super;

    private bool $armed = false;
    private int $fired = 0;
    private string $failMarker = '';

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
        ]);

        PageCache::flush();
    }

    protected function tearDown(): void
    {
        $this->armed = false;
        PageCache::flush();
        parent::tearDown();
    }

    /** 在指定 recipe 的区块落库时确定性抛一次异常（仅首次激活）。 */
    private function armFailure(string $recipeKey): void
    {
        $this->failMarker = self::PACK . '.' . $recipeKey;
        $this->armed = true;
        $this->fired = 0;

        PageBlock::saving(function (PageBlock $pb): void {
            if (! $this->armed) {
                return;
            }
            if (str_contains((string) $pb->content, $this->failMarker)) {
                $this->armed = false;
                $this->fired++;
                throw new \RuntimeException('W3-T2 injected mid-activation failure');
            }
        });
    }

    private function activateViaHttp(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.templates.activate', self::PACK))
            ->assertRedirect();
    }

    /** 统计站点内某 recipe 标记的区块数（_recipe === pack.key）。 */
    private function recipeBlockCount(string $recipeKey): int
    {
        $marker = self::PACK . '.' . $recipeKey;

        return (int) PageBlock::where('site_id', $this->site->id)
            ->get()
            ->filter(fn (PageBlock $pb) => ($pb->cfg()['_recipe'] ?? null) === $marker)
            ->count();
    }

    private function pageCount(): int
    {
        return (int) Page::where('site_id', $this->site->id)->count();
    }

    private function blockCount(): int
    {
        return (int) PageBlock::where('site_id', $this->site->id)->count();
    }

    private function auditCount(string $action): int
    {
        return (int) AuditLog::where('action', $action)->count();
    }

    private function homeZh(): Page
    {
        return Page::where('is_home', true)->where('locale', 'zh-CN')->firstOrFail();
    }

    /** 创建最小双语站点组织（products 系统页前台渲染需要 company）。 */
    private function makeCompany(): void
    {
        $org = Entity::create([
            'site_id' => $this->site->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'site-organization',
            'name' => '示例公司',
            'summary' => '示例公司简介',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => ['is_site_organization' => true],
        ]);
        $org->createTranslation('en', [
            'slug' => 'site-organization',
            'name' => 'Example Company',
            'summary' => 'Example company profile',
            'description' => 'Example company profile',
        ]);
        Catalog::flush();
        PageCache::flush();
    }

    public function test_mid_activation_failure_is_atomic_and_recoverable(): void
    {
        // 真实 recipe 应用顺序（glob 字母序），锁定顺序避免静默错位。
        $order = array_keys(TemplatePackageManager::recipes(self::PACK));
        $this->assertSame(['about', 'contact', 'homepage', 'products'], $order);

        $second = $order[1]; // contact：在此注入异常

        // 人工区块（无 _recipe 标记），全程必须原样保留。
        $home = $this->homeZh();
        PageBlock::create([
            'site_id' => $this->site->id,
            'page' => 'home',
            'slot' => 'main',
            'type' => 'rich_text',
            'sort' => 99,
            'limit' => 0,
            'is_active' => true,
            'page_id' => $home->id,
            'content' => json_encode(['title' => 'W3-T2 手动保留区块'], JSON_UNESCAPED_UNICODE),
        ]);

        // products 系统页前台渲染需要站点组织（与既有 TemplatePackage18L3aTest 一致）。
        $this->makeCompany();

        // 预热首页页面缓存。
        $this->get('http://localhost/')->assertOk();

        $pages0 = $this->pageCount();
        $blocks0 = $this->blockCount();

        // ---- 第一次激活：在第二个 recipe 中途注入异常 ----
        $this->armFailure($second);
        $response = $this->actingAs($this->super)
            ->post(route('admin.templates.activate', self::PACK));

        // 控制器按契约捕获异常、重定向 error（不再外抛未处理异常）。
        $response->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, $this->fired, '注入异常必须恰好触发一次');

        // ---- 原子回滚：无任何残留（激活态 / 区块 / 注册 / 意图 / 审计）----
        $this->assertSame(
            '',
            Setting::get(TemplatePackageManager::SETTINGS_KEY, ''),
            '回滚后激活态不应残留'
        );
        $this->assertFalse(TemplateRegistry::has('mfg-home'), '回滚后包级模板注册应被恢复');
        // 全部 recipe 区块均不落地（含本应先成功的 about）。
        $this->assertSame(0, $this->recipeBlockCount('about'), 'about 不应残留');
        $this->assertSame(0, $this->recipeBlockCount('contact'), 'contact 不应残留');
        $this->assertSame(0, $this->recipeBlockCount('homepage'), 'homepage 不应残留');
        $this->assertSame(0, $this->recipeBlockCount('products'), 'products 不应残留');
        // 页/块总数回到激活前（不增加）。
        $this->assertSame($pages0, $this->pageCount(), '回滚后页面数应回到激活前');
        $this->assertSame($blocks0, $this->blockCount(), '回滚后区块数应回到激活前');
        // 待失效意图不残留。
        $this->assertNull(Setting::get(PageCacheInvalidation::PENDING_KEY), '待失效意图不应残留');
        // 审计：无成功、有且仅有一次失败。
        $this->assertSame(0, $this->auditCount('template.activate'), '回滚不应留下成功审计');
        $this->assertSame(1, $this->auditCount('template.activate_failed'), '应记录一次失败审计');
        // 人工区块在回滚后仍保留。
        $manual = $this->homeZh()->blocks()->where('sort', 99)->first();
        $this->assertNotNull($manual, '回滚后人工区块必须保留');

        // ---- 恢复：移除注入，再次真实 HTTP 激活（幂等补齐）----
        $this->activateViaHttp();

        // 全部 recipe 落地。
        $this->assertSame(10, $this->recipeBlockCount('about'), 'about 5×2=10');
        $this->assertSame(2, $this->recipeBlockCount('contact'), 'contact 1×2=2');
        $this->assertSame(14, $this->recipeBlockCount('homepage'), 'homepage 7×2=14');
        $this->assertSame(4, $this->recipeBlockCount('products'), 'products sidebar 2×2=4');

        // 页面不重复（每个 locale 恰好一行 about 落地页）。
        $this->assertSame(1, Page::where('site_id', $this->site->id)
            ->where('slug', 'about-company')->where('locale', 'zh-CN')->count());
        $this->assertSame(1, Page::where('site_id', $this->site->id)
            ->where('slug', 'about-company')->where('locale', 'en')->count());

        // 人工区块原样保留；首页 main 为 7 配方块 + 1 人工块 = 8。
        $manual = $this->homeZh()->blocks()->where('sort', 99)->first();
        $this->assertNotNull($manual);
        $this->assertSame('W3-T2 手动保留区块', $manual->cfg()['title']);
        $this->assertSame(8, $this->homeZh()->blocks()->where('slot', 'main')->count(),
            '首页 main 应为 7 配方块 + 1 人工块');

        // ---- 第三次激活：不新增任何区块（可重复执行）----
        $blocksAfterRecovery = $this->blockCount();
        $this->activateViaHttp();
        $this->assertSame(
            $blocksAfterRecovery,
            $this->blockCount(),
            '再次激活必须幂等，不产生重复区块'
        );

        // 激活态保持；待失效意图不残留。
        $this->assertSame(self::PACK, Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));
        $this->assertNull(Setting::get(PageCacheInvalidation::PENDING_KEY));

        // ---- 最终前台双语页面 200（缓存最终一致）----
        $this->get('http://localhost/')->assertOk()->assertSee('值得信赖的制造合作伙伴');
        $this->get('http://localhost/en/')->assertOk()->assertSee('Your Trusted Manufacturing Partner');
        $this->get('http://localhost/about-company')->assertOk();
        $this->get('http://localhost/en/about-company')->assertOk();
        $this->get('http://localhost/products/')->assertOk();
        $this->get('http://localhost/contact/')->assertOk();
    }
}
