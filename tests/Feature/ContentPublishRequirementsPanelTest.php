<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Services\Gate\ContentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 内容编辑页「发布要求」面板的展示与一致性契约测试（第 1 批）。
 *
 * 面板是展示规格（ContentGate::requirementSpec），不是发布裁决；唯一裁决仍是 check()。
 * 本测试保证：
 *  - 动态证据阈值 N 由真实配置产出，无硬编码；
 *  - 强制模式说明与实际行为一致（手动发布始终强制；isEnforced 仅作用于 GEOFlow）；
 *  - 空的 BANNED_COMPARISON / CONFLICT_VALUES 不被描述为当前执行的检查；
 *  - 展示规格与 check() 结果一致（警告不阻断、阈值同步）。
 */
class ContentPublishRequirementsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    /** 构造一份满足全部门禁条件、可直接发布的草稿（持久化）。 */
    protected function makePassableContent(array $override = []): Content
    {
        return Content::create(array_merge([
            'type'           => 'article',
            'title'          => '完整可发布内容',
            'slug'           => 'passable-content',
            'summary'        => '这是一份结构完整的摘要。',
            'body'           => '正文内容。',
            'geo_conclusion' => '这是直接回答问题的结论。',
            'geo_explanation' => '这是说明原因与做法的解释。',
            'geo_boundary'   => '这是写明不适用场景与限制的边界。',
            'geo_evidence'   => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二', 'source' => '来源二'],
            ],
            'owner'          => 'Administrator',
            'reviewed_at'    => '2026-09-14',
            'fact_refs'      => [],
        ], $override));
    }

    protected function editPage(Content $content)
    {
        return $this->actingAs($this->admin)->get("/admin/contents/{$content->id}/edit");
    }

    public function test_panel_is_rendered_with_default_threshold_two(): void
    {
        $content = $this->makePassableContent();

        $this->editPage($content)
            ->assertOk()
            ->assertSee('发布要求', false)
            ->assertSee('证据不少于 2 条', false)
            ->assertSee('至少 2 条', false)
            ->assertSee('手动发布强制校验', false);
    }

    public function test_evidence_threshold_is_dynamic_from_config(): void
    {
        config(['geo.gate.min_evidence' => 3]);
        $content = $this->makePassableContent();

        $this->editPage($content)
            ->assertOk()
            ->assertSee('证据不少于 3 条', false)
            ->assertSee('至少 3 条', false)
            ->assertDontSee('证据不少于 2 条', false);
    }

    public function test_persistent_required_lines_present(): void
    {
        $content = $this->makePassableContent();

        $this->editPage($content)
            ->assertOk()
            // 四层标题常驻必填行
            ->assertSee('四层均为发布必填', false)
            // 证据层常驻动态提示
            ->assertSee('每条必填来源', false);
    }

    public function test_manual_publish_is_always_enforced(): void
    {
        // 即使关闭 enforce_on_publish，仅影响 GEOFlow；手动发布仍显示强制，
        // 且实际发布仍被门禁拒绝。
        config(['geo.gate.enforce_on_publish' => false]);
        $this->assertFalse(app(ContentGate::class)->isEnforced());

        $content = $this->makePassableContent([
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
            ],
        ]);

        $this->editPage($content)
            ->assertOk()
            ->assertSee('手动发布强制校验', false)
            ->assertSee('GEOFlow 自动推送：当前为「降级为警告，不阻断」', false)
            ->assertDontSee('GEOFlow 自动推送：当前为「强制校验', false);

        // 手动发布仍被拒绝（不看 isEnforced）。
        $this->actingAs($this->admin)
            ->post("/admin/contents/{$content->id}/publish")
            ->assertRedirect();
        $this->assertSame('draft', $content->fresh()->status);
    }

    public function test_geoflow_note_shows_enforced_by_default(): void
    {
        $content = $this->makePassableContent();

        $this->editPage($content)
            ->assertOk()
            ->assertSee('GEOFlow 自动推送：当前为「强制校验，未通过即拒绝」', false);
    }

    public function test_empty_extension_rules_are_not_described_as_active(): void
    {
        // 默认产品 BANNED_COMPARISON / CONFLICT_VALUES 为空：面板不应把它们描述为已启用检查。
        $this->assertSame([], ContentGate::BANNED_COMPARISON);
        $this->assertSame([], ContentGate::CONFLICT_VALUES);

        $content = $this->makePassableContent();

        $this->editPage($content)
            ->assertOk()
            ->assertDontSee('禁用品牌', false)
            ->assertDontSee('口径冲突', false);
    }

    public function test_warning_conditions_do_not_block(): void
    {
        // 标题 >60 字仅警告；摘要为空仅警告：满足硬条件时仍应通过 check()。
        $content = $this->makePassableContent([
            'title'   => str_repeat('很', 61),
            'slug'    => 'long-title-content',
            'summary' => '',
        ]);

        $result = app(ContentGate::class)->check($content);
        $this->assertTrue($result['passed'], '警告项不应阻断，错误：' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => str_contains($w, '60 字')));
    }

    public function test_precheck_endpoint_uses_gate_without_persisting(): void
    {
        // 已有预检端点：对表单数据跑 check()，不落库、不改状态。
        $content = $this->makePassableContent();

        $payload = [
            'type'       => 'article',
            'title'      => '',
            'slug'       => '',
            'ev_label'   => [],
            'ev_value'   => [],
            'ev_source'  => [],
            'ev_url'     => [],
        ];

        $this->actingAs($this->admin)
            ->postJson('/admin/contents/check', $payload)
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonStructure(['passed', 'errors', 'warnings']);

        // 不落库：内容状态不变。
        $this->assertSame('draft', $content->fresh()->status);
    }

    public function test_requirement_spec_matches_gate_threshold(): void
    {
        config(['geo.gate.min_evidence' => 4]);
        $spec = app(ContentGate::class)->requirementSpec();

        $this->assertSame(4, $spec['min_evidence']);
        // 每项层级标记合法、文案非空。
        foreach ($spec['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $this->assertContains($item['level'], ['must', 'should']);
                $this->assertNotSame('', trim($item['text']));
            }
        }
    }
}
