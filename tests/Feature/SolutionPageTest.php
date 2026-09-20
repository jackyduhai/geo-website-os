<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Facts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.7：应用场景（/solutions/）六页 + 合作方式页 + 工厂页 + 首页场景/参数区块回归。
 * 数据来自 Facts（facts.yaml 编译产物）与 config/pages，替代 v0.6 的 /scenarios。
 */
class SolutionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_solution_index_lists_all_scenes(): void
    {
        $res = $this->get('/solutions/')->assertOk();
        foreach (Facts::scenes() as $scene) {
            $res->assertSee($scene['name']);
        }
    }

    public function test_every_solution_page_renders_with_single_h1(): void
    {
        foreach (Facts::scenes() as $scene) {
            $res = $this->get('/solutions/' . $scene['slug'] . '/')->assertOk();
            $this->assertSame(1, substr_count($res->content(), '<h1'), $scene['slug'] . ' H1 数量异常');
        }
    }

    public function test_unknown_solution_is_404(): void
    {
        $this->get('/solutions/no-such-type/')->assertNotFound();
    }

    public function test_solution_shows_real_combo_without_competitor_terms(): void
    {
        $res = $this->get('/solutions/equipment-manufacturing/')->assertOk();
        // 场景页呈现真实产品组合（装备制造场景组合含环氧富锌底漆）
        $res->assertSee('环氧富锌底漆');
        // 通用合规红线：只呈现自有产品，不出现「XX 同款」式对标或配置中的他方品牌
        $res->assertDontSee('同款');
        foreach (Facts::bannedComparisons() as $competitor) {
            $res->assertDontSee($competitor);
        }
    }

    public function test_cooperation_page_renders_faq_schema_and_five_steps(): void
    {
        $res = $this->get('/cooperation/')->assertOk();
        foreach (Facts::cooperation()['process'] as $step) {
            $res->assertSee($step['name']);
        }
        $this->assertStringContainsString('FAQPage', $res->content());
        $this->assertSame(1, substr_count($res->content(), '<h1'));
    }

    public function test_factory_page_hides_credentials_when_not_verified(): void
    {
        $res = $this->get('/factory/')->assertOk();
        // SC 编号未核齐前，资质「区块」整体隐藏（不出现区块 H2；导航下拉里的同名菜单不算）
        if (! Facts::certificationsReady()) {
            $this->assertStringNotContainsString('<h2 class="sec-h">资质与标准</h2>', $res->content());
        }
        foreach (Facts::workshops() as $ws) {
            $res->assertSee($ws['name']);
        }
    }

    public function test_sitemap_uses_new_ia_without_legacy_or_cases(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->content();
        $this->assertStringContainsString('/solutions/', $xml);
        $this->assertStringContainsString('/cooperation/', $xml);
        $this->assertStringContainsString('/products/coatings/', $xml);
        $this->assertStringNotContainsString('/scenarios', $xml);
        $this->assertStringNotContainsString('/cases', $xml);
    }

    public function test_legacy_scenarios_permanently_redirects_to_solutions(): void
    {
        $this->get('/scenarios')->assertRedirect('/solutions/');
        $this->get('/scenarios/equipment-manufacturing')->assertRedirect('/solutions/equipment-manufacturing/');
    }

    public function test_home_contains_scene_and_param_sections(): void
    {
        $res = $this->get('/')->assertOk();
        $res->assertSee('scene-card', false);
        $res->assertSee('param-table', false);
        $this->assertStringContainsString('var(--cta)', $res->content());
    }

    public function test_admin_block_editor_lists_home_block_types(): void
    {
        $admin = User::firstOrFail();
        $res = $this->actingAs($admin)->get(route('admin.blocks.index'))->assertOk();
        foreach (['scenes', 'params', 'cooperation', 'faqs', 'cases'] as $type) {
            $res->assertSee($type);
        }
    }
}
