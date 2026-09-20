<?php

namespace Tests\Feature;

use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首页条目型区块（应用场景/合作方式/合作剪影/车间）：
 * 缺省由统一默认源 HomeBlockDefaults 渲染；后台保存条目后以自定义为准（文案/图/链接均可改）。
 */
class HomeBlockItemsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    private function block(string $type): PageBlock
    {
        return PageBlock::where('page', 'home')->where('type', $type)->firstOrFail();
    }

    public function test_scenes_render_default_three_cards_with_links(): void
    {
        $html = $this->get('/')->getContent();
        // 默认三类应用场景都在，且卡片为可点链接（指向 /solutions/{slug}/）
        $this->assertStringContainsString('id="s02"', $html);
        $this->assertStringContainsString('装备制造', $html);
        $this->assertStringContainsString('建筑工程', $html);
        $this->assertStringContainsString('汽车零部件', $html);
        $this->assertStringContainsString('<a class="scene-card"', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*solutions/equipment-manufacturing/?~', $html);
    }

    public function test_admin_scene_items_override_default_copy_and_link(): void
    {
        $blk = $this->block('scenes');

        $this->actingAs($this->admin)->put("/admin/blocks/{$blk->id}", [
            'sort' => $blk->sort, 'is_active' => 1,
            'title' => '自定义场景标题',
            'items' => [
                0 => ['title' => '我的自定义业态', 'text' => '自定义说明XYZ', 'link' => '/cooperation/'],
            ],
        ])->assertRedirect();

        $items = $blk->fresh()->items();
        $this->assertCount(1, $items);
        $this->assertSame('/cooperation/', $items[0]['link']);

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('我的自定义业态', $html);
        $this->assertStringContainsString('自定义说明XYZ', $html);
        $this->assertStringContainsString('自定义场景标题', $html);
    }

    public function test_saving_scene_keeps_derived_tags_and_empty_icon(): void
    {
        $blk = $this->block('scenes');
        // 模拟后台预填默认行后直接保存（icon 选“无图标”，tags/reveal 以隐藏域回传）
        $this->actingAs($this->admin)->put("/admin/blocks/{$blk->id}", [
            'sort' => $blk->sort, 'is_active' => 1,
            'items' => [
                0 => ['icon' => '', 'title' => '装备制造', 'text' => '说明', 'link' => '/solutions/equipment-manufacturing/',
                    'tags' => ['防腐涂装', '结构粘接'], 'reveal' => '干膜 60–80 μm'],
            ],
        ])->assertRedirect();

        $it = $blk->fresh()->items()[0];
        $this->assertNull($it['icon']);
        $this->assertSame(['防腐涂装', '结构粘接'], $it['tags']);
        $this->assertSame('干膜 60–80 μm', $it['reveal']);
    }

    public function test_cooperation_and_cases_render_defaults(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('id="s06"', $html); // 合作方式
        $this->assertStringContainsString('id="s07"', $html); // 合作剪影
        $this->assertStringContainsString('coop-points', $html);
    }

    public function test_workshop_items_carry_text_after_backfill(): void
    {
        $html = $this->get('/')->getContent();
        // 四大车间说明不应因字段升级而丢失，名称与通用事实源（facts.workshops）保持一致
        $this->assertStringContainsString('原料处理车间', $html);
        $this->assertStringContainsString('配料混合车间', $html);
        $this->assertStringContainsString('成型加工车间', $html);
        $this->assertStringContainsString('品控包装车间', $html);
    }
}
