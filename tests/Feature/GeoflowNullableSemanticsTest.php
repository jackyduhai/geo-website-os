<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-NULL · GEOFlow 字段四态语义契约（20G-2 · C-5）
 * ------------------------------------------------------------------
 * 核心不变量：四种输入形态必须严格区分，**互不混淆**。
 *
 *   missing        字段不存在 → 不修改（保留库中现值）
 *   null           显式 null → 清空
 *   valid value    合法值   → 规范化后写入
 *   invalid value  非法值   → 422 拒绝，不写库
 *
 * 历史缺陷（C-5）：`array_filter($attrs, fn($v) => $v !== null)`
 * 把 null 过滤掉，于是「上游明确要求清空」被误解成「上游没传」，
 * GEO 事实字段（geo_boundary 等）永远无法清空。
 *
 * 另一类历史缺陷（评审第 2 条）：把「引用解析失败」当成 null（清空），
 * 等于把上游的输入错误转换成合法的数据清空动作——
 * 上游拼错一个 slug，官网就把该内容的栏目归属清空了，还返回 200。
 */
class GeoflowNullableSemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test-token-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');
    }

    protected function payload(array $override = []): array
    {
        return array_merge([
            'external_id' => 'NULL-001',
            'type' => 'article',
            'title' => '四态语义验证文章',
            'slug' => 'nullable-semantics-article',
            'category_slug' => 'knowledge',
            'summary' => '初始摘要。',
            'body' => '初始正文。',
            'geo_conclusion' => '结论。',
            'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '设备A', 'value' => '配置1', 'source' => '台账'],
                ['label' => '设备 B', 'value' => '配置 2', 'source' => '实拍'],
            ],
            'owner' => 'GEOFlow',
            'reviewed_at' => '2026-09-14',
        ], $override);
    }

    protected function push(array $override = [])
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload($override));
    }

    // ─────────────────────────────────────────────────────────
    // 态1 · missing
    // ─────────────────────────────────────────────────────────

    /**
     * GF-NULL-001 字段缺失 → 库中原值保留不变。
     */
    public function test_gf_null_001_missing_field_keeps_existing_value(): void
    {
        $this->push()->assertOk();
        $original = Content::where('external_id', 'NULL-001')->firstOrFail()->summary;

        $this->assertSame('初始摘要。', $original);

        // 只推 title，不含 summary
        $payload = $this->payload();
        unset($payload['summary']);
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $payload)->assertOk();

        $after = Content::where('external_id', 'NULL-001')->firstOrFail()->summary;

        $this->assertSame(
            '初始摘要。',
            $after,
            'GF-NULL-001 失败：字段缺失时必须保留库中原值'
        );
    }

    /**
     * GF-NULL-002 缺失字段不产生 changed_fields 条目。
     */
    public function test_gf_null_002_missing_field_not_reported_as_changed(): void
    {
        $this->push()->assertOk();

        $payload = $this->payload();
        unset($payload['summary'], $payload['body']);
        $payload['title'] = '四态语义验证文章';   // 值未变

        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $payload)
            ->assertOk()->json();

        $this->assertSame('skip', $json['action'], '有效值均未变应 skip');
        $this->assertSame([], $json['changed_fields']);
    }

    // ─────────────────────────────────────────────────────────
    // 态2 · null（显式清空）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-NULL-003 显式 null → 字段被清空。
     * 这是 C-5 的核心断言。
     */
    public function test_gf_null_003_explicit_null_clears_field(): void
    {
        $this->push()->assertOk();
        $this->assertNotNull(Content::where('external_id', 'NULL-001')->firstOrFail()->summary);

        $this->push(['summary' => null])->assertOk();

        $this->assertNull(
            Content::where('external_id', 'NULL-001')->firstOrFail()->summary,
            'GF-NULL-003 失败：显式 null 必须清空字段'
        );
    }

    /**
     * GF-NULL-004 null 与 missing 行为必须不同（这是 C-5 的本质）。
     */
    public function test_gf_null_004_null_differs_from_missing(): void
    {
        $this->push()->assertOk();
        $c = Content::where('external_id', 'NULL-001')->firstOrFail();
        $c->update(['summary' => '基准摘要']);

        // missing → 保留
        $payload = $this->payload();
        unset($payload['summary']);
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $payload)->assertOk();
        $afterMissing = Content::where('external_id', 'NULL-001')->firstOrFail()->summary;
        $this->assertSame('基准摘要', $afterMissing, 'missing 应保留');

        // null → 清空
        $this->push(['summary' => null])->assertOk();
        $afterNull = Content::where('external_id', 'NULL-001')->firstOrFail()->summary;
        $this->assertNull($afterNull, 'null 应清空');

        $this->assertNotSame(
            $afterMissing,
            $afterNull,
            'GF-NULL-004 失败：null 与 missing 必须产生不同结果'
        );
    }

    /**
     * GF-NULL-005 清空 nullable 字段被契约允许（不报 422）。
     */
    public function test_gf_null_005_null_on_nullable_field_is_accepted(): void
    {
        $this->assertTrue(
            \App\Support\ContentFieldContract::isNullable('summary'),
            '契约应声明 summary 可为 null'
        );

        $this->push(['summary' => null])->assertOk();
    }

    /**
     * GF-NULL-006 非 nullable 字段传 null → 422。
     * type 是非 nullable 的代表（省略=默认，null=非法）。
     */
    public function test_gf_null_006_null_on_non_nullable_field_is_422(): void
    {
        $this->assertFalse(
            \App\Support\ContentFieldContract::isNullable('type'),
            '契约应声明 type 不可为 null'
        );

        $this->push(['type' => null])->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────
    // 态3 · valid
    // ─────────────────────────────────────────────────────────

    /**
     * GF-NULL-007 合法值写入并可被后续读取。
     */
    public function test_gf_null_007_valid_value_is_written(): void
    {
        $this->push(['summary' => '新的摘要内容'])->assertOk();

        $this->assertSame(
            '新的摘要内容',
            Content::where('external_id', 'NULL-001')->firstOrFail()->summary
        );
    }

    /**
     * GF-NULL-008 空串被归一为 null（既有行为，非本次修复范围）。
     *
     * 实测：推 summary='' 后 DB 中为 NULL。原因是 Eloquent 属性层
     * 把空串归一为 null（20F.1 之前既有）。
     *
     * 业务上可接受：空串与 null 语义几乎等价——门禁对两者都视为
     * 「缺少摘要」，列表页与 meta description 同样退化为正文首段。
     * 归一为 null 反而更安全（不会把「有摘要但内容为空」误判为有摘要）。
     *
     * 本测试锁定**当前真实行为**而非理想行为。若将来产品要求区分
     * 空串与 null，需在模型层显式区分并同步修改此处断言。
     */
    public function test_gf_null_008_empty_string_is_normalized_to_null(): void
    {
        $json = $this->push(['summary' => ''])->assertOk()->json();

        $stored = Content::where('external_id', 'NULL-001')->firstOrFail()->getRawOriginal('summary');

        $this->assertNull(
            $stored,
            'GF-NULL-008 失败：空串按既有行为归一为 null'
        );
        $this->assertContains(
            '缺少摘要，列表页与 meta description 将退化为正文首段',
            $json['warnings'],
            '空串应产生「缺少摘要」warning（与 null 语义一致）'
        );
    }

    // ─────────────────────────────────────────────────────────
    // 态4 · invalid（拒绝，绝不降级）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-NULL-009 引用字段解析失败 → 422，**绝不当作清空**。
     *
     * 评审第 2 条的核心场景：若把解析失败当 null，
     * 上游拼错一个 slug 就会把官网的栏目归属清空，还返回 200。
     */
    public function test_gf_null_009_unresolvable_reference_is_422_not_clear(): void
    {
        $this->push()->assertOk();
        $before = Content::where('external_id', 'NULL-001')->firstOrFail()->category_id;
        $this->assertNotNull($before);

        $this->push(['category_slug' => '不存在的栏目'])
            ->assertStatus(422);

        $after = Content::where('external_id', 'NULL-001')->firstOrFail()->category_id;

        $this->assertSame(
            $before,
            $after,
            'GF-NULL-009 失败：引用解析失败不得清空栏目归属'
        );
    }

    /**
     * GF-NULL-010 引用字段显式 null → 允许清空（与解析失败不同）。
     */
    public function test_gf_null_010_explicit_null_on_reference_clears_it(): void
    {
        $this->push()->assertOk();
        $this->assertNotNull(Content::where('external_id', 'NULL-001')->firstOrFail()->category_id);

        $this->push(['category_slug' => null])->assertOk();

        $this->assertNull(
            Content::where('external_id', 'NULL-001')->firstOrFail()->category_id,
            'GF-NULL-010 失败：显式 null 应允许清空栏目归属'
        );
    }

    /**
     * GF-NULL-011 非法值一律不写库（无副作用）。
     */
    public function test_gf_null_011_invalid_value_writes_nothing(): void
    {
        $this->push(['type' => 'bad-type'])->assertStatus(422);
        $this->assertDatabaseMissing('contents', ['external_id' => 'NULL-001']);

        $this->push(['published_at' => 'not-a-date'])->assertStatus(422);
        $this->assertDatabaseMissing('contents', ['external_id' => 'NULL-001']);
    }

    /**
     * GF-NULL-012 契约自检：nullable 声明与实现一致。
     */
    public function test_gf_null_012_contract_declares_nullability_consistently(): void
    {
        $contract = \App\Support\ContentFieldContract::all();

        // type 非 nullable，其余内容字段 nullable
        $this->assertFalse($contract['type']['nullable']);
        foreach (['title', 'summary', 'body', 'geo_boundary', 'owner'] as $field) {
            $this->assertTrue($contract[$field]['nullable'], "{$field} 应可显式清空");
        }
    }
}
