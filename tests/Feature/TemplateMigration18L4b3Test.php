<?php

namespace Tests\Feature;

use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use App\Support\Templates\TemplateMigrationRegistry;
use App\Support\Templates\TemplateMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-4b-3 — Template Migration Lite（TD-131）防回归。
 *
 * 验证「旧 Template Package / Recipe / Composition 在系统升级后可安全迁移」：
 *   规则加载、dry-run 不写库、field rename、deprecated block 替换、token 迁移、
 *   variant rename、保护字段 / 目标 block 校验、SEO 文本不变、GEO 语义重算标注、
 *   命令 apply↔rollback 端到端、8 包兼容、Multi-Site 隔离。
 *
 * 硬边界：规则 JSON + Registry 驱动（无 PHP/Blade/JS migration code）；只改结构键 /
 * type / variant / token 引用，不自动改 SEO 内容、URL、Schema；必须可 dry-run、可回滚。
 */
class TemplateMigration18L4b3Test extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    /** 在系统临时目录构造一个带 migration 规则的包（不污染 resources/templates）。 */
    private function makeTempPack(array $rules, string $id = 'zz-temp'): string
    {
        $dir = sys_get_temp_dir() . '/geo-mig-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/migration.json', json_encode([
            'id' => $id,
            'paths' => [[
                'from' => '1.0.0',
                'to' => '1.1.0',
                'rules' => $rules,
            ]],
        ]));

        return $dir;
    }

    private function makeBlock(string $type, array $content, ?int $siteId = null, int $sort = 0): PageBlock
    {
        return PageBlock::create([
            'site_id' => $siteId ?? $this->default->id,
            'page' => 'page',
            'slot' => 'main',
            'type' => $type,
            'sort' => $sort,
            'is_active' => true,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // 1) 规则加载 -------------------------------------------------------

    public function test_registry_loads_migration_paths(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'subtitle', 'to' => 'description'],
            ],
        ]);

        $this->assertTrue(TemplateMigrationRegistry::hasRules($dir));
        $this->assertCount(1, TemplateMigrationRegistry::paths($dir));

        $resolved = TemplateMigrationRegistry::rulesFor($dir, '1.0.0', '1.1.0');
        $this->assertNotNull($resolved);
        $this->assertSame('1.0.0', $resolved['from']);
        $this->assertSame('1.1.0', $resolved['to']);
        $this->assertCount(1, $resolved['rules'][TemplateMigrationRegistry::RENAME_FIELDS]);

        $this->assertSame([], TemplateMigrationRegistry::validate($dir));
    }

    // 2) dry-run 不写库 --------------------------------------------------

    public function test_dry_run_plan_does_not_write(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'subtitle', 'to' => 'description'],
            ],
        ]);
        $hero = $this->makeBlock('hero', ['title' => 'T', 'subtitle' => '旧副标题']);

        $blocks = PageBlock::where('id', $hero->id)->get();
        $plan = TemplateMigrator::plan($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $this->assertCount(1, $plan['changes']);
        $this->assertSame(1, $plan['summary'][TemplateMigrationRegistry::RENAME_FIELDS]);
        // 数据库内容未被 dry-run 修改
        $this->assertArrayHasKey('subtitle', PageBlock::find($hero->id)->cfg());
    }

    // 3) field rename ----------------------------------------------------

    public function test_field_rename_keeps_value(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'subtitle', 'to' => 'description'],
            ],
        ]);
        $hero = $this->makeBlock('hero', ['title' => 'T', 'subtitle' => '保留我']);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        TemplateMigrator::apply($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $cfg = PageBlock::find($hero->id)->cfg();
        $this->assertArrayNotHasKey('subtitle', $cfg);
        $this->assertSame('保留我', $cfg['description']);
        $this->assertSame('T', $cfg['title']);
    }

    // 4) deprecated block 替换 -------------------------------------------

    public function test_deprecated_block_is_replaced(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::REPLACE_BLOCKS => [
                ['from' => 'feature_list', 'to' => 'feature_grid'],
            ],
        ]);
        $old = $this->makeBlock('feature_list', ['title' => '能力']);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        $result = TemplateMigrator::apply($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $this->assertSame(1, $result['summary'][TemplateMigrationRegistry::REPLACE_BLOCKS]);
        $updated = PageBlock::find($old->id);
        $this->assertSame('feature_grid', $updated->type);
        $this->assertSame('能力', $updated->cfg()['title']); // content 可复用部分保留
    }

    // 5) token 迁移 ------------------------------------------------------

    public function test_token_identifier_is_renamed(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_TOKENS => [
                ['from' => 'primary_color', 'to' => 'brand_primary'],
            ],
        ]);
        $block = $this->makeBlock('rich_text', ['token_ref' => 'primary_color', 'body' => '普通文本 primary_color-ish']);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        TemplateMigrator::apply($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $cfg = PageBlock::find($block->id)->cfg();
        $this->assertSame('brand_primary', $cfg['token_ref']);       // 精确值替换
        $this->assertSame('普通文本 primary_color-ish', $cfg['body']); // 子串不误伤
    }

    // 6) variant rename --------------------------------------------------

    public function test_variant_is_renamed(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_VARIANTS => [
                ['block' => 'hero', 'from' => 'full', 'to' => 'image'],
            ],
        ]);
        $hero = $this->makeBlock('hero', ['variant' => 'full', 'title' => 'T']);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        TemplateMigrator::apply($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $this->assertSame('image', PageBlock::find($hero->id)->cfg()['variant']);
    }

    // 7) 保护字段规则被拒绝 ----------------------------------------------

    public function test_protected_field_rule_is_rejected(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'url', 'to' => 'link_target'],
            ],
        ]);

        $errors = TemplateMigrationRegistry::validate($dir);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('保护字段', implode('; ', $errors));
    }

    // 8) 目标 block 未注册被拒绝 -----------------------------------------

    public function test_unregistered_target_block_is_rejected(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::REPLACE_BLOCKS => [
                ['from' => 'feature_list', 'to' => 'does_not_exist_block'],
            ],
        ]);

        $errors = TemplateMigrationRegistry::validate($dir);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('未注册', implode('; ', $errors));
    }

    // 9) 迁移保持 SEO 文本与公开契约 --------------------------------------

    public function test_migration_preserves_text_and_seo_values(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'subtitle', 'to' => 'description'],
            ],
        ]);
        $hero = $this->makeBlock('hero', [
            'title' => '权威标题',
            'subtitle' => '权威副标题',
            'buttons' => [['label' => '联系', 'url' => '/contact/', 'style' => 'primary']],
        ]);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        TemplateMigrator::apply($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $cfg = PageBlock::find($hero->id)->cfg();
        $this->assertSame('权威标题', $cfg['title']);
        $this->assertSame('权威副标题', $cfg['description']);
        $this->assertSame('/contact/', $cfg['buttons'][0]['url']);   // URL 未被改动
        $this->assertSame('联系', $cfg['buttons'][0]['label']);
    }

    // 10) GEO 语义重算并在计划中标注 -------------------------------------

    public function test_geo_semantic_change_is_annotated_for_replaced_block(): void
    {
        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::REPLACE_BLOCKS => [
                ['from' => 'feature_list', 'to' => 'feature_grid'],
            ],
        ]);
        $this->makeBlock('feature_list', ['title' => 'x']);

        $blocks = PageBlock::where('site_id', $this->default->id)->get();
        $plan = TemplateMigrator::plan($blocks, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $change = $plan['changes'][0];
        $this->assertNotNull($change['semantic']);
        // 新 block 的 section 为 solution（feature_grid 默认语义）
        $this->assertContains('solution', $change['semantic']['new']);
    }

    // 11) 命令 apply → rollback 端到端 ------------------------------------

    public function test_command_apply_and_rollback_end_to_end(): void
    {
        $id = 'zz-mig-test';
        $dir = resource_path('templates/' . $id);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        try {
            file_put_contents($dir . '/manifest.json', json_encode([
                'id' => $id,
                'name' => 'Mig Test',
                'version' => '1.1.0',
                'industry' => ['manufacturing'],
                'locales' => ['zh-CN'],
                'theme' => 'default',
                'requires' => [
                    'platform' => '1.0.0', 'theme_api' => '1.0.0', 'component_api' => '1.0.0',
                    'geo_contract' => '1.0.0', 'seo_contract' => '1.0.0',
                    'components' => ['feature_grid'],
                ],
                'pages' => ['home'],
                'identity' => ['name' => 'Mig Test', 'industry' => 'manufacturing', 'audience' => ['x']],
                'purpose' => ['primary' => 'lead_generation'],
                'entities' => ['Organization'],
                'conversion' => ['primary' => 'inquiry'],
                'seo' => ['recommended_schema' => ['Organization']],
                'migration' => ['from' => '1.0.0', 'to' => '1.1.0', 'supported' => true],
                'author' => 't', 'license' => 'MIT',
            ]));
            file_put_contents($dir . '/migration.json', json_encode([
                'id' => $id,
                'paths' => [[
                    'from' => '1.0.0', 'to' => '1.1.0',
                    'rules' => [TemplateMigrationRegistry::REPLACE_BLOCKS => [
                        ['from' => 'feature_list', 'to' => 'feature_grid'],
                    ]],
                ]],
            ]));

            $old = $this->makeBlock('feature_list', ['title' => '端到端']);

            $this->artisan('template:migrate', ['pack' => $id, '--force' => true])
                ->assertExitCode(0);
            $this->assertSame('feature_grid', PageBlock::find($old->id)->type);

            // 再次 apply 应幂等（already up to date）
            $this->artisan('template:migrate', ['pack' => $id, '--force' => true])
                ->assertExitCode(0);
            $this->assertSame('feature_grid', PageBlock::find($old->id)->type);

            $this->artisan('template:migrate', ['pack' => $id, '--rollback' => true])
                ->assertExitCode(0);
            $this->assertSame('feature_list', PageBlock::find($old->id)->type);
        } finally {
            // 清理临时包与相关备份
            $this->removeDirectory($dir);
            foreach (glob(storage_path('app/template-migration-backups/' . $id . '-*.json')) ?: [] as $backup) {
                @unlink($backup);
            }
        }
    }

    // 12) 8 个真实包全部兼容 ---------------------------------------------

    public function test_all_eight_business_os_packs_validate(): void
    {
        $base = resource_path('templates');
        $packs = ['manufacturing-pro', 'saas-pro', 'commerce-pro', 'service-pro',
            'healthcare-pro', 'education-pro', 'construction-pro', 'export-pro'];

        foreach ($packs as $id) {
            $packPath = $base . '/' . $id;
            $this->assertDirectoryExists($packPath, "missing pack {$id}");
            $this->assertSame([], TemplateMigrationRegistry::validate($packPath), "pack {$id} migration rules invalid");
        }
    }

    // 13) Multi-Site 隔离 -------------------------------------------------

    public function test_migration_is_scoped_per_site(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);

        $dir = $this->makeTempPack([
            TemplateMigrationRegistry::RENAME_FIELDS => [
                ['block' => 'hero', 'from' => 'subtitle', 'to' => 'description'],
            ],
        ]);

        $blockA = $this->makeBlock('hero', ['title' => 'A', 'subtitle' => 'A 副'], $this->default->id);
        $blockB = $this->makeBlock('hero', ['title' => 'B', 'subtitle' => 'B sub'], $siteB->id);

        // 只迁移 Site A 的 blocks
        $blocksA = PageBlock::where('site_id', $this->default->id)->get();
        TemplateMigrator::apply($blocksA, TemplateMigrationRegistry::rulesFor($dir)['rules']);

        $cfgA = PageBlock::find($blockA->id)->cfg();
        $cfgB = PageBlock::withoutSiteScope()->find($blockB->id)->cfg();
        $this->assertArrayNotHasKey('subtitle', $cfgA);
        $this->assertSame('A 副', $cfgA['description']);
        $this->assertArrayHasKey('subtitle', $cfgB); // Site B 未受影响
        $this->assertSame('B sub', $cfgB['subtitle']);
    }

    /** 递归删除目录（测试清理）。 */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            if (is_dir($item)) {
                $this->removeDirectory($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
