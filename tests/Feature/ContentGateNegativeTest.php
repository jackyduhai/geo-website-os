<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Fact;
use App\Models\User;
use App\Services\Gate\ContentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ContentGate 真实发布门禁的负向契约测试（Phase 2C.1）。
 *
 * 覆盖此前缺失的拒绝/警告分支：
 *  - 证据缺来源（L97）
 *  - 标题为空（L112）
 *  - slug 为空（L115）/ 非法格式（L117）
 *  - fact_refs 引用不存在的事实（L156）
 *  - NAP 电话不一致（L175-177，警告，不阻断）
 *
 * 阻断类一律经真实发布入口 admin.contents.publish 验证：断言被拒绝、状态保持 draft、
 * 且 gateErrors 命中具体业务文案；电话警告经同一门禁服务 check() 验证其文案。
 */
class ContentGateNegativeTest extends TestCase
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

    /** 断言发布被拒，状态保持 draft，且错误命中指定业务文案。 */
    protected function assertPublishRejectedWith(Content $content, string $errorFragment): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/contents/{$content->id}/publish")
            ->assertRedirect()
            ->assertSessionHas('gateErrors', function ($errors) use ($errorFragment) {
                return collect($errors)->contains(fn ($e) => str_contains($e, $errorFragment));
            });

        $this->assertSame('draft', $content->fresh()->status, '被门禁拒绝的内容不得变为 published');
    }

    public function test_evidence_item_without_source_blocks_publish(): void
    {
        // 两条证据满足数量，但其中一条缺 source（来源）：证据链可溯源性失效，必须拒绝。
        $content = $this->makePassableContent([
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二'], // 缺 source
            ],
        ]);

        $this->assertPublishRejectedWith($content, '缺少来源');
    }

    public function test_empty_title_blocks_publish(): void
    {
        $content = $this->makePassableContent(['title' => '']);

        $this->assertPublishRejectedWith($content, '标题不能为空');
    }

    public function test_whitespace_only_title_blocks_publish(): void
    {
        $content = $this->makePassableContent(['title' => '   ']);

        $this->assertPublishRejectedWith($content, '标题不能为空');
    }

    public function test_empty_slug_blocks_publish(): void
    {
        $content = $this->makePassableContent(['slug' => '']);

        $this->assertPublishRejectedWith($content, 'URL 段（slug）不能为空');
    }

    public function test_invalid_slug_format_blocks_publish(): void
    {
        // 含大写字母与空格，不符合「小写字母/数字/连字符」语义化 URL 规则。
        $content = $this->makePassableContent(['slug' => 'Invalid Slug']);

        $this->assertPublishRejectedWith($content, '小写字母、数字与连字符');
    }

    public function test_dangling_fact_reference_blocks_publish(): void
    {
        // fact_refs 引用了事实库中不存在的 key：悬空引用，必须拒绝。
        $content = $this->makePassableContent([
            'fact_refs' => ['FACT-DOES-NOT-EXIST'],
        ]);

        $this->assertPublishRejectedWith($content, '不存在的事实');
    }

    public function test_phone_mismatch_adds_warning_but_does_not_block_publish(): void
    {
        // 官方电话口径
        Fact::where('key', 'FACT-COMPANY-013')->update(['value' => '13800138000']);

        // 正文出现与官方口径不一致的手机号
        $content = $this->makePassableContent([
            'body' => '业务咨询可拨打 13912345678 联系我们。',
        ]);

        // 经同一门禁服务验证：应产生 NAP 电话不一致警告
        $result = app(ContentGate::class)->check($content);
        $this->assertTrue($result['passed'], '警告不应阻断发布');
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w, '13912345678') && str_contains($w, '电话')),
            '应警告出现与官方口径不一致的电话，实际警告：' . json_encode($result['warnings'], JSON_UNESCAPED_UNICODE)
        );

        // 真实发布入口：带警告仍可发布，状态变为 published
        $this->actingAs($this->admin)
            ->post("/admin/contents/{$content->id}/publish")
            ->assertRedirect();
        $this->assertSame('published', $content->fresh()->status);
    }

    public function test_matching_official_phone_produces_no_phone_warning(): void
    {
        Fact::where('key', 'FACT-COMPANY-013')->update(['value' => '13800138000']);

        $content = $this->makePassableContent([
            'body' => '官方咨询电话 13800138000，欢迎联系。',
        ]);

        $result = app(ContentGate::class)->check($content);
        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w, '电话')),
            '与官方口径一致的电话不应产生警告，实际：' . json_encode($result['warnings'], JSON_UNESCAPED_UNICODE)
        );
    }
}
