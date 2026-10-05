<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-VALID · GEOFlow 载荷校验契约（20G-2 · C-7 · C-11 · C-12）
 * ------------------------------------------------------------------
 * 核心不变量：**所有非法输入都在边界被拒并返回 422，绝不透传到模型层变成 500。**
 *
 * 历史缺陷：
 *   C-7  check() 绕过类型白名单 → type 传数组触发 TypeError → 500
 *   C-11 title / published_at 传数组穿透到模型层（Content 是 $guarded=[]）
 *   C-12 geo_faq 传非法 JSON 被 `json_decode(...) ?: []` 静默吞成空数组
 *
 * 长度 / 枚举规则全部读自 ContentFieldContract，本测试不硬编码任何上限，
 * 契约变更时断言自动跟随——这是「单一规则源」的实践形式。
 */
class GeoflowPayloadValidationTest extends TestCase
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
            'external_id' => 'VAL-001',
            'type' => 'article',
            'title' => '载荷校验验证文章',
            'slug' => 'payload-validation-article',
            'category_slug' => 'knowledge',
            'summary' => '摘要。',
            'body' => '正文。',
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

    protected function pushRaw(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $payload);
    }

    // ─────────────────────────────────────────────────────────
    // 类型边界（C-7 / C-11）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-VALID-001 type 传数组 → 422（不是 500）。
     * 这是 C-7 的原始场景：check() 曾直接读 $payload['type'] 传给 string 型参数。
     */
    public function test_gf_valid_001_type_as_array_is_422_not_500(): void
    {
        $this->pushRaw($this->payload(['type' => ['article']]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /**
     * GF-VALID-002 check 端点同样必须 422。
     * 修复前 check 绕过白名单 → TypeError → 500。
     */
    public function test_gf_valid_002_check_endpoint_rejects_array_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/check', $this->payload(['type' => ['article']]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');
    }

    /**
     * GF-VALID-003 title 传数组 → 422。
     * C-11：Content 是 $guided=[]，数组会直接进模型直到 SQL 才炸。
     */
    public function test_gf_valid_003_title_as_array_is_rejected(): void
    {
        $this->pushRaw($this->payload(['title' => ['a', 'b']]))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-004 published_at 传数组 → 422。
     * 该数组会在 ContentGate 的 preg_match 处触发 TypeError → 500。
     */
    public function test_gf_valid_004_published_at_as_array_is_rejected(): void
    {
        $this->pushRaw($this->payload(['published_at' => ['2026-01-01']]))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-005 body 传对象 → 422（所有标量字段同等约束）。
     */
    public function test_gf_valid_005_body_as_object_is_rejected(): void
    {
        $this->pushRaw($this->payload(['body' => new \stdClass()]))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-006 type 为 null → 422（type 不是 nullable 字段）。
     * null 与「省略」语义必须区分：省略=默认 article，null=非法。
     */
    public function test_gf_valid_006_type_null_is_422_not_default(): void
    {
        $this->pushRaw($this->payload(['type' => null]))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-007 type 省略 → 默认 article（不是 422）。
     */
    public function test_gf_valid_007_type_omitted_defaults_to_article(): void
    {
        $payload = $this->payload();
        unset($payload['type']);

        $this->pushRaw($payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('contents', [
            'external_id' => 'VAL-001',
            'type' => 'article',
        ]);
    }

    /**
     * GF-VALID-008 type 未知字符串 → 422（契约派生枚举）。
     */
    public function test_gf_valid_008_unknown_type_is_422(): void
    {
        $this->pushRaw($this->payload(['type' => 'unknown-type']))
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────
    // 长度边界（契约派生，不硬编码）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-VALID-009 title 超长 → 422。
     * 上限读自契约，契约改了本测试自动跟随。
     */
    public function test_gf_valid_009_title_over_max_length_is_422(): void
    {
        $max = \App\Support\ContentFieldContract::maxOf('title');

        $this->pushRaw($this->payload(['title' => str_repeat('A', $max + 1)]))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-010 title 恰好等于上限 → 通过（边界不含糊）。
     */
    public function test_gf_valid_010_title_at_max_length_passes(): void
    {
        $max = \App\Support\ContentFieldContract::maxOf('title');

        $this->pushRaw($this->payload(['title' => str_repeat('A', $max)]))
            ->assertOk();
    }

    /**
     * GF-VALID-011 slug 格式非法 → 422（契约 format 正则）。
     */
    public function test_gf_valid_011_invalid_slug_format_is_422(): void
    {
        $this->pushRaw($this->payload(['slug' => 'Invalid Slug With Spaces']))
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────
    // 日期与 JSON（C-7 / C-12）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-VALID-012 日期格式非法 → 422。
     */
    public function test_gf_valid_012_invalid_date_is_422(): void
    {
        $this->pushRaw($this->payload(['reviewed_at' => 'not-a-date']))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-013 非法 JSON 字符串 → 422，**不得静默变空数组**。
     * C-12：`json_decode(...) ?: []` 会把上游数据错误吞掉并返回 200。
     */
    public function test_gf_valid_013_invalid_json_string_is_422_not_silently_emptied(): void
    {
        $this->pushRaw($this->payload(['geo_faq' => '{not valid json']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation');

        $this->assertDatabaseMissing('contents', ['external_id' => 'VAL-001']);
    }

    /**
     * GF-VALID-014 合法 JSON 字符串 → 解码为数组后通过。
     */
    public function test_gf_valid_014_valid_json_string_is_accepted(): void
    {
        $this->pushRaw($this->payload([
            'geo_key_facts' => '[{"fact":"键1"},{"fact":"键2"}]',
        ]))->assertOk();

        $this->assertDatabaseHas('contents', ['external_id' => 'VAL-001']);
    }

    // ─────────────────────────────────────────────────────────
    // 引用型字段：invalid ≠ null（评审第 2 条）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-VALID-015 category_slug 不存在 → 422，**不得当作清空**。
     *
     * 这是评审第 2 条的核心：把「解析失败」当null 处理，等于把
     * 上游输入错误转换成合法的数据清空动作—— 上游拼错一个 slug，
     * 官网就把该内容的栏目归属清空了，还返回 200。
     */
    public function test_gf_valid_015_nonexistent_category_is_422_not_silent_null(): void
    {
        $this->pushRaw($this->payload(['category_slug' => 'no-such-category-xyz']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('contents', ['external_id' => 'VAL-001']);
    }

    /**
     * GF-VALID-016 group_slug 不存在 → 422。
     */
    public function test_gf_valid_016_nonexistent_group_is_422(): void
    {
        $this->pushRaw($this->payload(['group_slug' => 'no-such-group-xyz']))
            ->assertStatus(422);
    }

    /**
     * GF-VALID-017 合法 category_slug → 解析为 category_id 落库。
     */
    public function test_gf_valid_017_valid_category_slug_resolves_to_id(): void
    {
        $this->pushRaw($this->payload())->assertOk();

        $expected = \App\Models\Category::where('slug', 'knowledge')->firstOrFail()->id;

        $this->assertDatabaseHas('contents', [
            'external_id' => 'VAL-001',
            'category_id' => $expected,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // 必填
    // ─────────────────────────────────────────────────────────

    /**
     * GF-VALID-018 external_id 缺失 → 422。
     */
    public function test_gf_valid_018_missing_external_id_is_422(): void
    {
        $payload = $this->payload();
        unset($payload['external_id']);

        $this->pushRaw($payload)->assertStatus(422);
    }

    /**
     * GF-VALID-019 title 为空字符串 → 422。
     */
    public function test_gf_valid_019_empty_title_is_422(): void
    {
        $this->pushRaw($this->payload(['title' => '   ']))->assertStatus(422);
    }

    /**
     * GF-VALID-020 已删除的 legacy 字段：静默忽略且不写入，**不是 422**。
     *
     * 契约依据：contents 表的 seo_title / seo_desc 列已随迁移删除。
     * 正确行为是「白名单不含该字段 → 忽略」，而不是 422——
     * 上游按旧契约多传一个字段就整条拒绝，会让新旧版本切换时的
     * 推送队列大面积失败。关键是**绝不能写入**（那才是 SQL 500 的来源）。
     *
     * 与 GF-VALID-015 的区别：category_slug 是**引用字段**，解析失败必须 422
     * （否则等于把输入错误当清空）；seo_title 是**已废弃字段**，忽略即可。
     */
    public function test_gf_valid_020_legacy_seo_fields_are_ignored_not_written(): void
    {
        $this->pushRaw($this->payload([
            'seo_title' => 'legacy seo title',
            'seo_desc' => 'legacy seo desc',
        ]))->assertOk()->assertJsonPath('ok', true);

        $content = \App\Models\Content::where('external_id', 'VAL-001')->firstOrFail();

        $this->assertFalse(
            array_key_exists('seo_title', $content->getAttributes()),
            'legacy 字段不得写入（否则 SQL 500）'
        );
        $this->assertFalse(
            array_key_exists('seo_desc', $content->getAttributes()),
            'legacy 字段不得写入（否则 SQL 500）'
        );
    }

    /**
     * GF-VALID-021 载荷内非契约字段被忽略而非报错。
     * 白名单语义：未知字段不写入，也不应让整个请求失败。
     */
    public function test_gf_valid_021_unknown_field_is_ignored_not_fatal(): void
    {
        $this->pushRaw($this->payload(['some_unknown_field' => 'x']))
            ->assertOk();

        $this->assertDatabaseMissing('contents', ['title' => 'x']);
    }
}
