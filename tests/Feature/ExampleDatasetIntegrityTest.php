<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Providers\AppServiceProvider;
use App\Support\Copy;
use App\Support\Facts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP1 业务脱敏 / Generic Example 数据集完整性回归。
 *
 * 前一阶段 Demo 数据由「某真实食品企业」替换为虚构的「示例制造有限公司」
 * （工业材料制造：3 产品线 / 8 产品（4 核心）/ 3 应用场景 / 4 车间 / 3 案例）。
 * 本测试逐条目锁定新 Example 数据集的规模、可渲染性与可解析性，并对所有主要
 * 前台页面做「渲染层零业务污染」反向断言（防 fresh 安装前台回显旧客户内容，
 * 即 P-STEP14 Issue A 的回归保护）。
 *
 * 说明：BusinessPollutionZeroTest 扫描的是源码文件；本测试扫描的是真实 HTTP
 * 渲染输出（含 Blade / meta / JSON-LD / 注释），两者互补、不重复。
 */
class ExampleDatasetIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** 旧客户身份 / 行业 / 竞品词，任何正式前台页面的渲染输出都不得出现 */
    private const BANNED = [
        'Demo Tenant A', 'Demo Tenant A', 'demo-tenant-a', 'DEMO TENANT A',
        'Sample Snack', 'Sample Marinade', 'Sample Spice', 'Sample Flavor', 'Sample Chain', 'Sample Port', 'Sample Person',
        'Sample City', 'Sample Province', 'Sample Road',
        'Sample SaaS', 'Sample SaaS', 'sample-saas',
    ];

    /** 主要公开前台页面（均应 200 且无业务污染） */
    private const PAGES = [
        '/',
        '/products/',
        '/products/coatings/',
        '/products/adhesives/',
        '/products/additives/',
        '/solutions/',
        '/solutions/equipment-manufacturing/',
        '/solutions/construction-infrastructure/',
        '/solutions/automotive-parts/',
        '/factory/',
        '/cooperation/',
        '/about/profile/',
        '/contact',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        AppServiceProvider::forgetNavCache();
        Copy::flush();
    }

    public function test_company_is_a_generic_fictional_example(): void
    {
        $c = Facts::company();
        $this->assertSame('示例制造有限公司', $c['name']);
        $this->assertSame('示例制造', $c['brand']);
        $this->assertSame('example.com', $c['domain']);
        $this->assertSame('工业材料制造', $c['industry']);
        $this->assertStringContainsString('示例', json_encode($c['address'], JSON_UNESCAPED_UNICODE));
    }

    public function test_three_product_lines_are_complete(): void
    {
        $lines = Facts::productLines();
        $this->assertCount(3, $lines);

        $expect = [
            'coatings'  => '工业防护涂料',
            'adhesives' => '工业胶粘剂',
            'additives'  => '功能助剂',
        ];
        foreach ($lines as $line) {
            $this->assertArrayHasKey($line['slug'], $expect);
            $this->assertSame($expect[$line['slug']], $line['name']);
            $this->assertNotEmpty($line['name']);
        }
    }

    public function test_eight_products_four_of_which_are_core(): void
    {
        $products = Facts::products();
        $this->assertCount(8, $products);

        $core = Facts::coreProductSlugs();
        $this->assertCount(4, $core);
        $this->assertSame(
            ['epoxy-primer-100', 'polyurethane-topcoat-200', 'structural-adhesive-a10', 'leveling-agent-l01'],
            $core
        );

        foreach ($core as $slug) {
            $this->assertTrue(Facts::isCoreProduct($slug), "{$slug} 应为核心产品");
        }

        $nonCore = ['heat-resistant-coating-300', 'silicone-sealant-s20', 'thickener-t02', 'curing-agent-c03'];
        $this->assertCount(4, $nonCore);
        foreach ($nonCore as $slug) {
            $this->assertFalse(Facts::isCoreProduct($slug), "{$slug} 应为非核心产品");
        }
    }

    public function test_three_scenes_and_every_combo_product_resolves(): void
    {
        $scenes = Facts::scenes();
        $this->assertCount(3, $scenes);

        $expectSlugs = ['equipment-manufacturing', 'construction-infrastructure', 'automotive-parts'];
        foreach ($scenes as $i => $scene) {
            $this->assertSame($expectSlugs[$i], $scene['slug']);
            $this->assertNotEmpty($scene['name']);
            foreach (Facts::sceneCombo($scene) as $product) {
                // 场景组合里的每款产品都必须能在产品库中解析，杜绝悬空 slug
                $this->assertNotNull(Facts::product($product['slug']));
                $this->assertNotEmpty($product['name']);
            }
        }
    }

    public function test_four_workshops_are_named_generically(): void
    {
        $workshops = Facts::workshops();
        $this->assertCount(4, $workshops);
        $names = array_map(fn ($w) => $w['name'], $workshops);
        foreach (['原料处理车间', '配料混合车间', '成型加工车间', '品控包装车间'] as $expect) {
            $this->assertContains($expect, $names);
        }
    }

    public function test_three_cases_are_present(): void
    {
        $this->assertCount(3, Facts::cases());
    }

    /**
     * 事实库规模（RC-9 D-02 后）。
     *
     * facts 是行级翻译模型，公开事实每个 key 各有 zh-CN / en 两行：
     *   17 公开 key × 2 语言 = 34 公开行
     *   6 个待补充 key（地址 / 电话 / MOQ / 交付周期 / 资质 / 标准号）
     *   只存在于 zh-CN —— 它们本就没有值，不补en 行，
     *   门禁下不出现在任何 AI 出口。
     * 合计 40 行。
     */
    public function test_fact_library_has_17_public_keys_in_two_locales_and_6_gaps(): void
    {
        // 公开行：两个语言各17 条
        $this->assertSame(17, Fact::where('locale', 'zh-CN')->where('is_public', true)->count());
        $this->assertSame(17, Fact::where('locale', 'en')->where('is_public', true)->count());

        // 待补充项：只在默认语言，无英文占位
        $this->assertSame(6, Fact::where('locale', 'zh-CN')->where('is_public', false)->count());
        $this->assertSame(0, Fact::where('locale', 'en')->where('is_public', false)->count());

        $this->assertSame(40, Fact::count());

        // 两个语言的公开 key 集合必须完全一致 —— 否则英文站会缺事实
        $zh = Fact::where('locale', 'zh-CN')->where('is_public', true)->pluck('key')->sort()->values();
        $en = Fact::where('locale', 'en')->where('is_public', true)->pluck('key')->sort()->values();
        $this->assertSame($zh->all(), $en->all(), 'zh-CN 与 en 的公开事实 key 集合不一致');
    }

    public function test_main_menu_structure_matches_example_dataset(): void
    {
        $menu = AppServiceProvider::mainMenu();
        $this->assertSame(
            ['产品中心', '应用场景', '工厂与资质', '知识中心', '关于我们'],
            array_column($menu, 'name')
        );

        $byName = fn ($n) => collect($menu)->firstWhere('name', $n);

        // 产品中心 = 3 条产品线
        $productChildren = array_column($byName('产品中心')['children'], 'name');
        $this->assertCount(3, $productChildren);
        foreach (['工业防护涂料', '工业胶粘剂', '功能助剂'] as $line) {
            $this->assertContains($line, $productChildren);
        }

        // 应用场景 = 3 个场景
        $this->assertCount(3, $byName('应用场景')['children']);
        // 知识中心由启用内容分组驱动，Demo 固定 3 个频道
        $this->assertCount(3, $byName('知识中心')['children']);
        // 关于我们 = 4 个子页
        $this->assertCount(4, $byName('关于我们')['children']);
    }

    public function test_customer_type_options_are_six_generic_industries(): void
    {
        $options = Copy::form()['fields']['customerType']['options'];
        $this->assertCount(6, $options);
        foreach (['企业客户', '工程客户', '品牌客户', '渠道伙伴', '个人客户', '其他'] as $opt) {
            $this->assertContains($opt, $options);
        }
    }

    public function test_product_line_pages_render_with_single_h1(): void
    {
        foreach (['coatings', 'adhesives', 'additives'] as $slug) {
            $res = $this->get('/products/' . $slug . '/')->assertOk();
            $this->assertSame(1, substr_count($res->content(), '<h1'), "/products/{$slug}/ H1 异常");
        }
    }

    public function test_core_product_pages_render_and_non_core_are_404(): void
    {
        foreach (Facts::coreProductSlugs() as $slug) {
            $this->get('/products/' . $slug)->assertOk();
        }
        foreach (['heat-resistant-coating-300', 'silicone-sealant-s20', 'thickener-t02', 'curing-agent-c03'] as $slug) {
            $this->get('/products/' . $slug)->assertNotFound();
        }
    }

    public function test_scene_pages_render_name_and_resolved_combo(): void
    {
        foreach (Facts::scenes() as $scene) {
            $res = $this->get('/solutions/' . $scene['slug'] . '/')->assertOk();
            $this->assertSame(1, substr_count($res->content(), '<h1'), $scene['slug'] . ' H1 异常');
            $res->assertSee($scene['name']);
        }

        // 装备制造场景组合首推环氧富锌底漆，必须真实渲染（而非空组合）
        $this->get('/solutions/equipment-manufacturing/')->assertOk()->assertSee('环氧富锌底漆');
    }

    public function test_home_renders_scenes_while_workshops_live_on_factory_page(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        // 首页 service_grid 展示三大应用场景（服务）。
        foreach (['装备制造', '建筑工程', '汽车零部件'] as $scene) {
            $this->assertStringContainsString($scene, $home);
        }

        // 车间（生产能力）归属工厂页 /factory；首页作为通用门面不展示，避免垂直 IA 假设。
        foreach (['原料处理车间', '配料混合车间', '成型加工车间', '品控包装车间'] as $ws) {
            $this->assertStringNotContainsString($ws, $home);
        }

        // 车间内容仍完整存在于工厂页（内容不丢失）。
        $factory = $this->get('/factory/')->assertOk()->getContent();
        foreach (['原料处理车间', '配料混合车间', '成型加工车间', '品控包装车间'] as $ws) {
            $this->assertStringContainsString($ws, $factory);
        }
    }

    public function test_rendered_public_pages_contain_no_business_pollution(): void
    {
        foreach (self::PAGES as $path) {
            $html = $this->get($path)->assertOk($path)->getContent();
            // 去掉内联脚本/样式里的第三方 URL 干扰前，先对全文做最严格的身份词检查
            foreach (self::BANNED as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $word,
                    $html,
                    "页面 {$path} 出现旧客户业务词「{$word}」"
                );
            }
        }
    }
}
