<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首页整体装修：区块显隐、条目编辑、手动选文章驱动前台渲染
 */
class HomeBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_home_renders_seeded_builder_sections(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // 迁移 000011 写入的默认能力点与车间条目应渲染
        $this->assertStringContainsString('定制研发', $html);
        $this->assertStringContainsString('原料处理车间', $html); // 迁移默认条目已中性化（P-STEP 02）
        $this->assertStringContainsString('需求对接', $html);
    }

    public function test_disabling_a_block_hides_its_section(): void
    {
        $workshops = PageBlock::where('type', 'workshops')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/blocks/{$workshops->id}", [
            'is_active' => 0,
            'sort' => $workshops->sort,
        ])->assertRedirect();

        $html = $this->get('/')->getContent();
        // 车间区块标题消失（车间名同时出现在主体事实表，故用区块标题判定）
        $this->assertStringNotContainsString('四大车间一体协同', $html);
        // 其它区块仍在
        $this->assertStringContainsString('定制研发', $html);
    }

    public function test_capability_items_are_editable_and_rendered(): void
    {
        $cap = PageBlock::where('type', 'capabilities')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/blocks/{$cap->id}", [
            'title' => '自定义能力标题',
            'sort' => $cap->sort,
            'is_active' => 1,
            'items' => [
                ['icon' => 'flame', 'title' => '自定义能力A', 'text' => '能力A说明'],
                ['icon' => '', 'title' => '', 'text' => '空标题行应被丢弃'],
                ['icon' => 'gear', 'title' => '自定义能力B', 'text' => '能力B说明'],
            ],
        ])->assertRedirect();

        $fresh = $cap->fresh();
        $this->assertCount(2, $fresh->items()); // 空行不保存

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('自定义能力标题', $html);
        $this->assertStringContainsString('自定义能力A', $html);
        $this->assertStringContainsString('自定义能力B', $html);
        $this->assertStringNotContainsString('空标题行应被丢弃', $html);
        $this->assertStringNotContainsString('按餐饮品牌需求定向研发风味', $html); // 旧条目已被替换
    }

    public function test_knowledge_block_supports_manual_pick(): void
    {
        $knowledge = PageBlock::where('type', 'knowledge')->firstOrFail();
        $target = Content::published()->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/blocks/{$knowledge->id}", [
            'sort' => $knowledge->sort,
            'is_active' => 1,
            'category_id' => $knowledge->category_id,
            'limit' => 6,
            'pick_ids' => [$target->id],
        ])->assertRedirect();

        $this->assertSame([$target->id], $knowledge->fresh()->pickedIds());

        // 手动指定后，首页知识区只出现所选文章
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString($target->title, $html);
    }

    public function test_block_order_follows_sort_value(): void
    {
        $stats = PageBlock::where('type', 'stats')->firstOrFail();
        $hero = PageBlock::where('type', 'hero')->firstOrFail();

        // 把 stats 排到 hero 之前
        $this->actingAs($this->admin)->put("/admin/blocks/{$stats->id}", [
            'sort' => 5, 'is_active' => 1,
        ])->assertRedirect();
        $this->actingAs($this->admin)->put("/admin/blocks/{$hero->id}", [
            'sort' => 10, 'is_active' => 1,
        ])->assertRedirect();

        $ordered = PageBlock::forPage('home')->active()->pluck('type')->all();
        $this->assertSame('stats', $ordered[0]);
    }
}
