<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Fact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台内容管理与 GEO 门禁的端到端测试
 */
class AdminContentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    /** 一份满足门禁的完整表单数据 */
    protected function validPayload(array $override = []): array
    {
        $factKey = Fact::where('is_public', true)->first()->key;

        return array_merge([
            'type' => 'article',
            'title' => '工业涂料定制开发流程说明',
            'slug' => 'coating-custom-process',
            'category_id' => Category::where('slug', 'knowledge')->value('id'),
            'summary' => '本文说明工业涂料定制从需求对接到试样确认的完整流程。',
            'body' => "## 定制流程\n\n需求对接、配方打样、试样确认、批量交付四个阶段。",
            'geo_conclusion' => '工业涂料定制分为需求对接、配方打样、试样确认与批量交付四个阶段，通常 2–3 轮试样。',
            'geo_explanation' => '需求阶段确认性能方向与成本区间；打样阶段出 2–3 个版本；试样通过后锁定配方。',
            'geo_boundary' => '具体周期与起订量需结合品类与订单量由业务确认，本文不构成交付承诺。',
            'ev_label' => ['研发打样间', '分散与研磨设备'],
            'ev_value' => ['可同时开展多版本配方打样', '高速分散机支持工艺参数固化'],
            'ev_source' => ['车间实拍', '设备台账'],
            'ev_url' => ['', ''],
            'faq_q' => ['定制一般需要几轮试样？'],
            'faq_a' => ['通常 2–3 轮，具体以性能确认进度为准。'],
            'kf_key' => ['试样轮次'],
            'kf_value' => ['2–3 轮'],
            'owner' => 'Administrator',
            'reviewed_at' => '2026-09-14',
            'review_due' => '2027-03-14',
            'source_note' => '研发流程内部资料（演示）',
            'fact_refs' => [$factKey],
        ], $override);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_contents_index_without_tab_returns_ok(): void
    {
        $this->actingAs($this->admin)->get('/admin/contents')->assertOk();
    }

    public function test_settings_index_without_group_returns_ok(): void
    {
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
    }

    public function test_admin_can_login_with_seeded_credential(): void
    {
        $this->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'Admin@123456',
        ])->assertRedirect('/admin');

        $this->assertAuthenticated();
    }

    public function test_incomplete_draft_can_be_saved(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/contents', [
                'type' => 'article', 'title' => '草稿', 'slug' => 'draft-one',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contents', ['slug' => 'draft-one', 'status' => 'draft']);
    }

    public function test_publish_is_rejected_without_four_layers(): void
    {
        $this->actingAs($this->admin);

        $id = Content::create([
            'type' => 'article', 'title' => '缺层内容', 'slug' => 'missing-layers',
            'status' => 'draft',
        ])->id;

        $this->post("/admin/contents/$id/publish")
            ->assertRedirect()
            ->assertSessionHas('gateErrors');

        $this->assertSame('draft', Content::find($id)->status);
    }

    public function test_complete_content_passes_gate_and_renders_publicly(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/contents', $this->validPayload())->assertRedirect();
        $content = Content::where('slug', 'coating-custom-process')->firstOrFail();

        $this->post("/admin/contents/{$content->id}/publish")->assertRedirect();
        $this->assertSame('published', $content->fresh()->status);

        // 前台可访问，且输出 JSON-LD
        $url = '/' . $content->category->slug . '/' . $content->slug;
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('结论', $html);
    }

    public function test_banned_extreme_word_blocks_publish(): void
    {
        $payload = $this->validPayload([
            'geo_conclusion' => '本企业是技术领先的工业材料供应商，全国销量第一。',
        ]);

        $this->actingAs($this->admin)->postJson('/admin/contents/check', $payload)
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonStructure(['errors', 'warnings']);
    }

    public function test_placeholder_marker_blocks_publish(): void
    {
        // 通用发布硬约束：正文不得残留「【待提供】」之类占位符（替代旧的客户专属错误口径检测）
        $payload = $this->validPayload([
            'body' => '厂区年产能数据见【待提供】，后续补充。',
        ]);

        $res = $this->actingAs($this->admin)->postJson('/admin/contents/check', $payload)->json();
        $this->assertFalse($res['passed']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, '占位符')));
    }

    public function test_comparison_same_style_phrase_is_blocked(): void
    {
        // 通用对标检测：不内置任何具体品牌名单，但「XX 同款」式对标句式一律拦截
        $payload = $this->validPayload([
            'geo_explanation' => '性能与进口大牌同款，稳定性更好。',
        ]);

        $res = $this->actingAs($this->admin)->postJson('/admin/contents/check', $payload)->json();
        $this->assertFalse($res['passed']);
        $this->assertTrue(collect($res['errors'])->contains(fn ($e) => str_contains($e, '同款')));
    }

    public function test_every_save_creates_revision(): void
    {
        $this->actingAs($this->admin);
        $this->post('/admin/contents', $this->validPayload())->assertRedirect();
        $content = Content::where('slug', 'coating-custom-process')->firstOrFail();

        $this->assertGreaterThanOrEqual(1, $content->revisions()->count());
    }
}
