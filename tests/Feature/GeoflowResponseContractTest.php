<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-RESP · GEOFlow 响应契约（20G-2 · Step 10）
 * ------------------------------------------------------------------
 * 核心不变量：所有端点的成功与失败响应结构**对称且可机器解析**。
 *
 * 修复前的契约问题：
 *   - check 端点无 ok / code 字段，且恒返 200，上游只看状态码会误判成功；
 *   - unpublish 直接返回整个 Eloquent 模型，泄露 body / lock_manual / content_hash；
 *   - 无机器可读错误码，只有中文 message，上游无法 switch 分支；
 *   - 响应不带 external_id，上游无法确认对齐了哪条内容；
 *   - 无 request_id，线上排障无法做「上游日志 ↔ SyncLog ↔ 响应」三方对账。
 */
class GeoflowResponseContractTest extends TestCase
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
            'external_id' => 'RESP-001',
            'type' => 'article',
            'title' => '响应契约验证文章',
            'slug' => 'response-contract-article',
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

    /**
     * 成功响应的必备键。
     */
    protected function assertSuccessShape(array $json): void
    {
        foreach (['ok', 'action', 'code', 'external_id', 'request_id', 'changed_fields', 'data'] as $key) {
            $this->assertArrayHasKey($key, $json, "成功响应缺少字段 {$key}");
        }
        $this->assertTrue($json['ok']);
        $this->assertIsArray($json['changed_fields'], 'changed_fields 必须是数组');
    }

    /**
     * 失败响应的必备键。
     */
    protected function assertFailureShape(array $json): void
    {
        foreach (['ok', 'code', 'message', 'external_id', 'request_id', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $json, "失败响应缺少字段 {$key}");
        }
        $this->assertFalse($json['ok']);
        $this->assertIsArray($json['errors'], 'errors 必须是数组');
    }

    // ─────────────────────────────────────────────────────────
    // 成功响应
    // ─────────────────────────────────────────────────────────

    /**
     * GF-RESP-001 upsert 成功响应结构完整。
     */
    public function test_gf_resp_001_upsert_success_shape(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()
            ->json();

        $this->assertSuccessShape($json);
        $this->assertSame('RESP-001', $json['external_id']);
        $this->assertSame('create', $json['action']);
    }

    /**
     * GF-RESP-002 成功响应不得泄露整个模型（禁止 body / lock_manual 外泄）。
     */
    public function test_gf_resp_002_success_does_not_leak_model(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()
            ->json();

        $data = $json['data'];

        // 只允许白名单字段
        $allowed = ['id', 'external_id', 'status', 'title', 'slug', 'content_hash', 'url', 'synced_at'];
        foreach (array_keys($data) as $key) {
            $this->assertContains($key, $allowed, "data 泄露了非契约字段 {$key}");
        }

        $this->assertArrayNotHasKey('body', $data, '正文不得出现在响应里');
        $this->assertArrayNotHasKey('lock_manual', $data, '人工锁标记不得外泄');
    }

    /**
     * GF-RESP-003 changed_fields 列出实际变动字段。
     */
    public function test_gf_resp_003_changed_fields_reports_real_changes(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload(['title' => '改了标题']))
            ->assertOk()
            ->json();

        $this->assertContains('title', $json['changed_fields'], '变更标题应在 changed_fields 中');
        $this->assertNotContains('body', $json['changed_fields'], '未变更的 body 不应出现');
    }

    /**
     * GF-RESP-004 skip 响应的 changed_fields 为空数组。
     */
    public function test_gf_resp_004_skip_has_empty_changed_fields(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->payload())->assertOk();

        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()
            ->json();

        $this->assertSame('skip', $json['action']);
        $this->assertSame([], $json['changed_fields']);
    }

    /**
     * GF-RESP-005 status 端点结构对称（含 external_id 与 request_id）。
     */
    public function test_gf_resp_005_status_shape(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->payload())->assertOk();

        $json = $this->withToken($this->token)
            ->getJson('/api/v1/geoflow/contents/RESP-001')
            ->assertOk()
            ->json();

        $this->assertTrue($json['ok']);
        $this->assertSame('RESP-001', $json['external_id']);
        $this->assertArrayHasKey('request_id', $json);
        $this->assertArrayHasKey('data', $json);
    }

    // ─────────────────────────────────────────────────────────
    // 失败响应
    // ─────────────────────────────────────────────────────────

    /**
     * GF-RESP-006 校验失败 422 结构完整 + 机器可读 code。
     */
    public function test_gf_resp_006_validation_failure_shape(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload(['type' => 'bad-type']))
            ->assertStatus(422)
            ->json();

        $this->assertFailureShape($json);
        $this->assertSame('validation', $json['code']);
        $this->assertNotEmpty($json['errors'], '必须给出字段级原因');
    }

    /**
     * GF-RESP-007 开关关闭 403 + code=disabled。
     */
    public function test_gf_resp_007_disabled_shape(): void
    {
        Setting::set('sync_geoflow_enabled', '0');

        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(403)
            ->json();

        $this->assertFailureShape($json);
        $this->assertSame('disabled', $json['code']);
    }

    /**
     * GF-RESP-008 人工锁冲突 409 + code=conflict。
     */
    public function test_gf_resp_008_conflict_shape(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->payload())->assertOk();
        Content::where('external_id', 'RESP-001')->firstOrFail()->update(['lock_manual' => true]);

        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload(['title' => '改标题']))
            ->assertStatus(409)
            ->json();

        $this->assertFailureShape($json);
        $this->assertSame('conflict', $json['code']);
    }

    /**
     * GF-RESP-009 not_found 404 结构完整。
     */
    public function test_gf_resp_009_not_found_shape(): void
    {
        $json = $this->withToken($this->token)
            ->getJson('/api/v1/geoflow/contents/NO-SUCH-ID')
            ->assertStatus(404)
            ->json();

        $this->assertFailureShape($json);
        $this->assertSame('not_found', $json['code']);
        $this->assertSame('NO-SUCH-ID', $json['external_id'], '失败响应也必须带 external_id');
    }

    /**
     * GF-RESP-010 check 端点必须有 ok / code / passed（修复前完全缺失）。
     */
    public function test_gf_resp_010_check_has_machine_readable_result(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/check', $this->payload())
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('ok', $json);
        $this->assertArrayHasKey('code', $json, 'check 必须有机器可读 code');
        $this->assertArrayHasKey('passed', $json, 'check 必须回带门禁判定');
        $this->assertArrayHasKey('request_id', $json);
    }

    // ─────────────────────────────────────────────────────────
    // request_id 三方对账
    // ─────────────────────────────────────────────────────────

    /**
     * GF-RESP-011 上游传入的 X-Request-Id 必须透传（便于三方对账）。
     */
    public function test_gf_resp_011_request_id_is_echoed(): void
    {
        $upstreamId = 'geoflow-trace-abc123';

        $json = $this->withToken($this->token)
            ->withHeader('X-Request-Id', $upstreamId)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()
            ->json();

        $this->assertSame($upstreamId, $json['request_id'], '上游的 request id 必须原样回传');
    }

    /**
     * GF-RESP-012 缺失时自动生成 request_id。
     */
    public function test_gf_resp_012_request_id_generated_when_absent(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()
            ->json();

        $this->assertNotEmpty($json['request_id'], '缺失时必须生成 request_id');
        $this->assertNotNull($json['request_id']);
    }

    /**
     * GF-RESP-013 失败响应同样带 request_id（排障不能只查成功路径）。
     */
    public function test_gf_resp_013_failure_also_has_request_id(): void
    {
        $json = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload(['type' => 'bad']))
            ->assertStatus(422)
            ->json();

        $this->assertNotEmpty($json['request_id']);
    }
}
