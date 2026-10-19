<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRegistry;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W3-T2 — 多 recipe 激活中途异常的失败恢复验证（纯新增测试，不改生产代码）。
 *
 * 背景：正式激活路径（TemplateController::activate → PackageManager::activate
 * 写激活态 → 逐个 RecipeApplier::apply）当前没有 DB::transaction。本测试使用
 * 真实包 manufacturing-pro（4 个 recipe，glob 顺序 about/contact/homepage/products），
 * 在 PageBlock 模型 saving 事件上确定性注入一次运行时异常（等价于"第二个 recipe
 * 落库时存储层抛错"），然后核验：
 *
 *   1) 异常后：激活态已写入（发生在 foreach 之前）、第一个 recipe 已落地、
 *      被中断及其后的 recipe 未落地 —— 即"中间状态"真实存在；
 *   2) 移除注入后再次走真实 HTTP 激活：全部 recipe 幂等补齐、页面不重复、
 *      人工区块（无 _recipe 标记）始终不被覆盖；
 *   3) 第三次激活不再新增任何区块（可重复执行）；
 *   4) 最终前台双语页面 200 且内容正确（缓存最终一致）。
 *
 * 本测试不预设"必须加事务"，仅用事实呈现中间状态是否可恢复，供事务取舍。
 */
class TemplateActivationRecoveryW3T2Test extends TestCase
{
    use RefreshDatabase;

    private const PACK = 'manufacturing-pro';
    private const INJECTED_MESSAGE = 'W3-T2 injected mid-activation failure';

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
                throw new \RuntimeException(self::INJECTED_MESSAGE);
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

    public function test_mid_activation_failure_is_recoverable_and_idempotent(): void
    {
        // 真实 recipe 应用顺序（glob 字母序），锁定顺序避免静默错位。
        $order = array_keys(TemplatePackageManager::recipes(self::PACK));
        $this->assertSame(['about', 'contact', 'homepage', 'products'], $order);

        $first = $order[0];   // about：先成功落地
        $second = $order[1];  // contact：在此注入异常

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

        // 预热首页页面缓存（用于观察异常路径未刷新缓存）。
        $this->get('http://localhost/')->assertOk();

        $pages0 = $this->pageCount();
        $blocks0 = $this->blockCount();

        // ---- 第一次激活：在第二个 recipe 中途注入异常 ----
        $this->armFailure($second);
        $this->withoutExceptionHandling();

        $this->assertThrows(function (): void {
            $this->actingAs($this->super)
                ->post(route('admin.templates.activate', self::PACK));
        }, \RuntimeException::class, self::INJECTED_MESSAGE);

        $this->assertSame(1, $this->fired, '注入异常必须恰好触发一次');

        // ---- 中间状态 S1 ----
        // 激活态已写入（PackageManager::activate 在 foreach 之前完成）。
        $this->assertSame(
            self::PACK,
            Setting::get(TemplatePackageManager::SETTINGS_KEY, ''),
            '无事务时激活态在首个 recipe 之前已落库'
        );
        // 包级模板已注册（register 同样在 foreach 之前）。
        $this->assertTrue(TemplateRegistry::has('mfg-home'));

        // 第一个 recipe 已完整落地（about：每页 5 块 × 中英两语 = 10）。
        $this->assertSame(10, $this->recipeBlockCount($first));
        // 被中断的 recipe 与其后的 recipe 未落地。
        $this->assertSame(0, $this->recipeBlockCount('contact'));
        $this->assertSame(0, $this->recipeBlockCount('homepage'));
        $this->assertSame(0, $this->recipeBlockCount('products'));

        // about 落地页已创建（中英各一行，且不重复）。
        $this->assertSame(1, Page::where('slug', 'about-company')->where('locale', 'zh-CN')->count());
        $this->assertSame(1, Page::where('slug', 'about-company')->where('locale', 'en')->count());

        $pages1 = $this->pageCount();
        $blocks1 = $this->blockCount();
        $this->assertGreaterThan($pages0, $pages1);
        $this->assertGreaterThan($blocks0, $blocks1);

        // ---- 恢复：移除注入，再次真实 HTTP 激活（幂等补齐）----
        $this->activateViaHttp();

        // 全部 recipe 落地。
        $this->assertSame(10, $this->recipeBlockCount('about'));
        $this->assertGreaterThan(0, $this->recipeBlockCount('contact'));
        $this->assertSame(14, $this->recipeBlockCount('homepage'), 'homepage 7 块 × 2 语言');
        $this->assertSame(4, $this->recipeBlockCount('products'), 'products sidebar 2 块 × 2 语言');

        // 页面不重复（每个 locale 恰好一行 about 落地页）。
        $this->assertSame(1, Page::where('slug', 'about-company')->where('locale', 'zh-CN')->count());
        $this->assertSame(1, Page::where('slug', 'about-company')->where('locale', 'en')->count());

        // 人工区块原样保留；首页 main 为 7 配方块 + 1 人工块。
        $manual = $this->homeZh()->blocks()->where('sort', 99)->first();
        $this->assertNotNull($manual);
        $this->assertSame('W3-T2 手动保留区块', $manual->cfg()['title']);
        $this->assertSame(8, $this->homeZh()->blocks()->where('slot', 'main')->count());

        // ---- 第三次激活：不新增任何区块（可重复执行）----
        $blocksAfterRecovery = $this->blockCount();
        $this->activateViaHttp();
        $this->assertSame(
            $blocksAfterRecovery,
            $this->blockCount(),
            '再次激活必须幂等，不产生重复区块'
        );

        // 激活态保持。
        $this->assertSame(self::PACK, Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));

        // ---- 最终前台双语页面 200（缓存最终一致）----
        $this->get('http://localhost/')->assertOk()->assertSee('值得信赖的制造合作伙伴');
        $this->get('http://localhost/en/')->assertOk()->assertSee('Your Trusted Manufacturing Partner');
        $this->get('http://localhost/about-company')->assertOk();
        $this->get('http://localhost/en/about-company')->assertOk();
        $this->get('http://localhost/products/')->assertOk();
        $this->get('http://localhost/contact/')->assertOk();
    }
}
