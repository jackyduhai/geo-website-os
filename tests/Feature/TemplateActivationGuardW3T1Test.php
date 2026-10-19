<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplateRecipeValidator;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * W3-T1 — 真实模板激活路径的负向门禁（纯新增测试，不改生产代码）。
 *
 *   1) 未知 package id 的 POST activate 返回 404，且不写激活态 / 不建页面区块；
 *   2) 未登录访客不能激活（admin.auth 重定向，不写激活态）；
 *   3) 清单(manifest)合法但配方(recipe)非法的包：能越过 exists() 的 404 守卫，
 *      但 TemplateRecipeValidator 报告 ERROR 时控制器中止，绝不写 template_active_pack、
 *      不落地任何 Page / PageBlock（真实 HTTP + 持久化回读，非纯 mock）。
 *
 * 第 3 项需要一个"清单合法但配方非法"的包能被 Manager 扫描到。Manager 的
 * basePath() 固定为 resources/templates（无运行时重定向钩子），因此本测试
 * 在该目录下临时创建 zzz-t1-bad-recipe，并在 finally / tearDown 中递归删除，
 * 保证不污染产品资源与工作树（运行后 git status 必须保持干净）。
 */
class TemplateActivationGuardW3T1Test extends TestCase
{
    use RefreshDatabase;

    private const BAD_PACK = 'zzz-t1-bad-recipe';

    protected Site $site;
    protected User $super;

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
        $this->removeBadPack();
        PageCache::flush();
        parent::tearDown();
    }

    /** 在真实 resources/templates 下临时安装"清单合法、配方非法"的包。 */
    private function installBadPack(): void
    {
        $dir = TemplatePackageManager::basePath() . '/' . self::BAD_PACK;
        File::ensureDirectoryExists($dir . '/recipes');

        $manifest = [
            'id' => self::BAD_PACK,
            'name' => 'W3-T1 Bad Recipe Pack',
            'version' => '1.0.0',
            'industry' => ['saas-technology'],
            'locales' => ['zh-CN', 'en'],
            'theme' => 'default',
            'identity' => ['name' => 'W3-T1', 'industry' => 'saas-technology'],
            'purpose' => ['primary' => 'lead_generation'],
            'entities' => ['Organization'],
            'conversion' => ['primary' => 'inquiry'],
            'seo' => ['recommended_schema' => ['Organization']],
            'requires' => [
                'platform' => '1.0.0',
                'theme_api' => '1.0.0',
                'component_api' => '1.0.0',
                'geo_contract' => '1.0.0',
                'seo_contract' => '1.0.0',
                'components' => ['hero'],
            ],
            'pages' => ['home'],
            'author' => 'w3-t1-test',
            'license' => 'MIT',
        ];

        File::put($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));

        // 配方引用一个未注册的 block 类型 —— Layer2 必须判 ERROR。
        $recipe = [
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [
                ['slot' => 'main', 'type' => 'zzz_not_a_real_block', 'content' => []],
            ],
        ];
        File::put($dir . '/recipes/homepage.json', json_encode($recipe, JSON_UNESCAPED_UNICODE));

        // 重置激活态记忆，避免读到旧的请求缓存。
        TemplatePackageManager::resetRequestMemo();
    }

    private function removeBadPack(): void
    {
        $dir = TemplatePackageManager::basePath() . '/' . self::BAD_PACK;
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
        TemplatePackageManager::resetRequestMemo();
    }

    private function pageCount(): int
    {
        return (int) Page::where('site_id', $this->site->id)->count();
    }

    private function blockCount(): int
    {
        return (int) PageBlock::where('site_id', $this->site->id)->count();
    }

    public function test_unknown_pack_activate_returns_404_and_persists_nothing(): void
    {
        $pagesBefore = $this->pageCount();
        $blocksBefore = $this->blockCount();

        $this->actingAs($this->super)
            ->post(route('admin.templates.activate', 'no-such-pack'))
            ->assertNotFound();

        $this->assertSame('', Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));
        $this->assertSame($pagesBefore, $this->pageCount());
        $this->assertSame($blocksBefore, $this->blockCount());
    }

    public function test_guest_cannot_activate_pack(): void
    {
        $this->post(route('admin.templates.activate', 'saas-pro'))
            ->assertRedirect();

        $this->assertSame('', Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));
    }

    public function test_pack_with_broken_recipe_is_rejected_before_activation(): void
    {
        try {
            $this->installBadPack();

            // 前置：manifest 合法（exists 为 true，能越过 404 守卫）……
            $this->assertTrue(
                TemplatePackageManager::exists(self::BAD_PACK),
                '清单合法的包应能被发现（exists=true）'
            );

            // ……但配方校验必须报告 ERROR。
            $packPath = TemplatePackageManager::basePath() . '/' . self::BAD_PACK;
            $inspect = TemplateRecipeValidator::inspect($packPath);
            $this->assertNotEmpty($inspect['errors'], '非法配方应产生 ERROR');

            $pagesBefore = $this->pageCount();
            $blocksBefore = $this->blockCount();

            $response = $this->actingAs($this->super)
                ->post(route('admin.templates.activate', self::BAD_PACK));

            // 失败：重定向回模板中心并带 error 闪存。
            $response->assertRedirect(route('admin.templates.index'));
            $response->assertSessionHas('error');

            // 激活态未写入。
            $this->assertSame('', Setting::get(TemplatePackageManager::SETTINGS_KEY, ''));

            // 页面与区块数量零变化。
            $this->assertSame($pagesBefore, $this->pageCount());
            $this->assertSame($blocksBefore, $this->blockCount());
        } finally {
            $this->removeBadPack();
        }
    }
}
