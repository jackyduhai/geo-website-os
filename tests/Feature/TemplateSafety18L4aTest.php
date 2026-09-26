<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\Templates\TemplateManifestValidator;
use App\Support\Templates\TemplateRecipeValidator;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-4a — Safety Foundation 防回归（TD-130 / TD-134 / TD-135）。
 *
 * 验证模板生态的安全地基：
 *   - Compatibility Contract：manifest.requires 五契约版本（platform/theme_api/
 *     component_api/geo_contract/seo_contract），主版本不匹配 / 平台过低 / 缺失 / 非 SemVer → ERROR；
 *   - 不可信 recipe 中的按钮 / 链接 URL 经 SafeUrl 裁决，javascript:/data:（含 HTML 实体绕过）→ ERROR；
 *   - 坏 JSON（manifest / recipe）→ ERROR；
 *   - 渲染侧（真实 HTTP）危险 URL 回退 '#'，<script>/PHP 注入被转义不执行；
 *   - 菜单解析、RecipeApplier、PageController、菜单后台校验等入口均 fail-closed。
 */
class TemplateSafety18L4aTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected User $super;

    /** @var array<int,string> */
    private array $tempDirs = [];

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
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        PageCache::flush();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $child) {
            is_dir($child) ? $this->removeDir($child) : @unlink($child);
        }
        @rmdir($dir);
    }

    private function anyContains(array $errors, string $needle): bool
    {
        foreach ($errors as $e) {
            if (str_contains((string) $e, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** 建临时包：$requires 覆盖默认 requires，$homeRecipe 非空时写入配方；返回路径。 */
    private function makeTempPack(array $requires = [], ?array $homeRecipe = null): string
    {
        $tmp = sys_get_temp_dir() . '/geo-4atest-' . uniqid();
        @mkdir($tmp . '/recipes', 0777, true);
        $this->tempDirs[] = $tmp;

        $defaultRequires = [
            'platform' => '1.0.0',
            'theme_api' => '1.0.0',
            'component_api' => '1.0.0',
            'geo_contract' => '1.0.0',
            'seo_contract' => '1.0.0',
            'components' => ['hero'],
        ];

        $manifest = [
            'id' => basename($tmp),
            'name' => 'Temp Pack',
            'version' => '1.0.0',
            'industry' => ['saas-technology'],
            'locales' => ['zh-CN', 'en'],
            'theme' => 'default',
            'identity' => ['name' => 'Temp', 'industry' => 'saas-technology'],
            'purpose' => ['primary' => 'lead_generation'],
            'entities' => ['Organization'],
            'conversion' => ['primary' => 'inquiry'],
            'seo' => ['recommended_schema' => ['Organization']],
            'requires' => array_merge($defaultRequires, $requires),
            'pages' => ['home'],
            'author' => 'test',
            'license' => 'MIT',
        ];

        file_put_contents($tmp . '/manifest.json', json_encode($manifest));
        if ($homeRecipe !== null) {
            file_put_contents(
                $tmp . '/recipes/homepage.json',
                json_encode($homeRecipe, JSON_UNESCAPED_UNICODE)
            );
        }

        return $tmp;
    }

    private function makePage(string $slug, string $locale = 'zh-CN'): Page
    {
        return Page::create([
            'site_id' => $this->site->id,
            'template' => 'landing',
            'slug' => $slug,
            'title' => '安全测试页',
            'status' => Page::STATUS_PUBLISHED,
            'locale' => $locale,
        ]);
    }

    private function makeBlock(Page $page, string $type, array $content,
                               string $slot = 'main', int $sort = 0): PageBlock
    {
        return PageBlock::create([
            'site_id' => $page->site_id,
            'page' => 'page',
            'page_id' => $page->id,
            'slot' => $slot,
            'type' => $type,
            'sort' => $sort,
            'is_active' => true,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // ----------------------------------------------------------------
    // A. Compatibility Contract（manifest 版本）
    // ----------------------------------------------------------------

    public function test_major_version_mismatch_is_blocked(): void
    {
        $tmp = $this->makeTempPack(['platform' => '2.0.0']);
        $errors = TemplateManifestValidator::validate($tmp);
        $this->assertTrue($this->anyContains($errors, '主版本不匹配'), implode("\n", $errors));
    }

    public function test_platform_too_old_is_blocked(): void
    {
        $tmp = $this->makeTempPack(['platform' => '1.5.0']);
        $errors = TemplateManifestValidator::validate($tmp);
        $this->assertTrue($this->anyContains($errors, '平台版本过低'), implode("\n", $errors));
    }

    public function test_missing_contract_version_is_blocked(): void
    {
        $tmp = $this->makeTempPack(['geo_contract' => null]);
        $errors = TemplateManifestValidator::validate($tmp);
        $this->assertTrue($this->anyContains($errors, 'requires.geo_contract'), implode("\n", $errors));
    }

    public function test_bad_semver_contract_is_blocked(): void
    {
        $tmp = $this->makeTempPack(['theme_api' => '1.x']);
        $errors = TemplateManifestValidator::validate($tmp);
        $this->assertTrue($this->anyContains($errors, '语义化版本'), implode("\n", $errors));
    }

    public function test_matching_contract_versions_pass(): void
    {
        $tmp = $this->makeTempPack();
        $this->assertSame([], TemplateManifestValidator::validate($tmp));
    }

    // ----------------------------------------------------------------
    // B. Recipe URL 安全
    // ----------------------------------------------------------------

    public function test_recipe_dangerous_button_url_is_blocked(): void
    {
        $recipe = [
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [[
                'slot' => 'main',
                'type' => 'hero',
                'content' => [
                    'title' => ['zh-CN' => '标题', 'en' => 'Title'],
                    'buttons' => [
                        ['label' => ['zh-CN' => '点我', 'en' => 'Go'],
                            'url' => 'javascript:alert(1)', 'style' => 'primary'],
                    ],
                ],
            ]],
        ];
        $tmp = $this->makeTempPack([], $recipe);
        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertTrue($this->anyContains($r['errors'], '按钮链接'), implode("\n", $r['errors']));
    }

    public function test_recipe_entity_encoded_button_url_is_blocked(): void
    {
        $recipe = [
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [[
                'slot' => 'main',
                'type' => 'hero',
                'content' => [
                    'title' => ['zh-CN' => '标题', 'en' => 'Title'],
                    'buttons' => [
                        ['label' => ['zh-CN' => '点我', 'en' => 'Go'],
                            'url' => '&#106;avascript:alert(1)', 'style' => 'primary'],
                    ],
                ],
            ]],
        ];
        $tmp = $this->makeTempPack([], $recipe);
        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertTrue($this->anyContains($r['errors'], '按钮链接'), implode("\n", $r['errors']));
    }

    public function test_recipe_text_url_field_is_checked(): void
    {
        $recipe = [
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [[
                'slot' => 'main',
                'type' => 'media_text',
                'content' => [
                    'title' => ['zh-CN' => '标题', 'en' => 'Title'],
                    'button_url' => 'data:text/html,x',
                ],
            ]],
        ];
        $tmp = $this->makeTempPack(['components' => ['media_text']], $recipe);
        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertTrue($this->anyContains($r['errors'], '链接字段'), implode("\n", $r['errors']));
    }

    public function test_recipe_safe_urls_pass(): void
    {
        $recipe = [
            'key' => 'homepage',
            'target' => ['type' => 'home'],
            'template' => 'home',
            'blocks' => [[
                'slot' => 'main',
                'type' => 'hero',
                'content' => [
                    'title' => ['zh-CN' => '标题', 'en' => 'Title'],
                    'buttons' => [
                        ['label' => ['zh-CN' => '联系', 'en' => 'Contact'],
                            'url' => '/contact/', 'style' => 'primary'],
                    ],
                ],
            ]],
        ];
        $tmp = $this->makeTempPack([], $recipe);
        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertFalse(
            $this->anyContains($r['errors'], '链接'),
            '安全 URL 不应报错：' . implode("\n", $r['errors'])
        );
    }

    // ----------------------------------------------------------------
    // C. 坏 JSON
    // ----------------------------------------------------------------

    public function test_bad_manifest_json_is_blocked(): void
    {
        $tmp = $this->makeTempPack();
        file_put_contents($tmp . '/manifest.json', '{ not valid json');
        $this->assertFalse(TemplateManifestValidator::isValid($tmp));
    }

    public function test_bad_recipe_json_is_blocked(): void
    {
        $tmp = $this->makeTempPack();
        file_put_contents($tmp . '/recipes/homepage.json', '{bad json');
        $r = TemplateRecipeValidator::inspect($tmp);
        $this->assertTrue($this->anyContains($r['errors'], '合法 JSON'), implode("\n", $r['errors']));
    }

    // ----------------------------------------------------------------
    // D. 渲染侧（真实 HTTP）
    // ----------------------------------------------------------------

    public function test_rendered_hero_strips_dangerous_button_url(): void
    {
        $page = $this->makePage('evil-hero');
        $this->makeBlock($page, 'hero', [
            'title' => '安全首屏',
            'buttons' => [
                ['label' => '点我', 'url' => 'javascript:alert(1)', 'style' => 'primary'],
            ],
        ]);

        $html = $this->get('/evil-hero')->getContent();
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        $this->assertStringContainsString('href="#"', $html);
    }

    public function test_rendered_cta_strips_dangerous_url(): void
    {
        $page = $this->makePage('evil-cta');
        $this->makeBlock($page, 'cta', [
            'title' => '行动号召',
            'buttons' => [
                ['label' => '前往', 'url' => 'data:text/html,x', 'style' => 'primary'],
            ],
        ]);

        $html = $this->get('/evil-cta')->getContent();
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringContainsString('href="#"', $html);
    }

    public function test_script_and_php_in_content_are_escaped_not_executed(): void
    {
        $page = $this->makePage('inject-check');
        $this->makeBlock($page, 'hero', [
            'title' => '<script>alert(1)</script>',
            'subtitle' => '<?php echo "evil"; ?>',
        ]);

        $html = $this->get('/inject-check')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<?php echo', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ----------------------------------------------------------------
    // E. 菜单 / 保存侧入口
    // ----------------------------------------------------------------

    public function test_menu_resolver_strips_dangerous_url(): void
    {
        $m = new \ReflectionMethod(\App\Providers\AppServiceProvider::class, 'resolveMenuHref');
        $m->setAccessible(true);

        $evil = $m->invoke(null, 'javascript:alert(1)');
        $this->assertSame('#', $evil['url']);

        $safe = $m->invoke(null, 'https://example.com');
        $this->assertSame('https://example.com', $safe['url']);
        $this->assertTrue($safe['external']);
    }

    public function test_recipe_applier_localize_url_strips_dangerous(): void
    {
        $m = new \ReflectionMethod(\App\Support\Templates\RecipeApplier::class, 'localizeUrl');
        $m->setAccessible(true);

        $this->assertSame('#', $m->invoke(null, 'javascript:alert(1)', 'en'));
        $this->assertSame('/en/products/', $m->invoke(null, '/products/', 'en'));
        $this->assertSame('/products/', $m->invoke(null, '/products/', 'zh-CN'));
    }

    public function test_extract_buttons_strips_dangerous_url(): void
    {
        $ctrl = app(\App\Http\Controllers\Admin\PageController::class);
        $m = new \ReflectionMethod($ctrl, 'extractButtons');
        $m->setAccessible(true);

        $out = $m->invoke($ctrl, [
            ['label' => 'Evil', 'url' => 'javascript:alert(1)', 'style' => 'primary'],
            ['label' => 'Safe', 'url' => '/products/', 'style' => 'secondary'],
        ]);

        $this->assertSame('#', $out[0]['url']);
        $this->assertSame('/products/', $out[1]['url']);
    }

    public function test_menu_admin_validation_rejects_dangerous_url(): void
    {
        $this->actingAs($this->super)->post(route('admin.menus.store'), [
            'position' => 'main',
            'parent_ref' => '',
            'label' => '恶意菜单',
            'url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('url');
    }
}
