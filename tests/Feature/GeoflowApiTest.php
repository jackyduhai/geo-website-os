<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GEOFlow 对接接口测试：鉴权、开关、门禁同标准、幂等、人工锁、下架
 */
class GeoflowApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test-token-123456';

    protected function setUp(): void
    {
        parent::setUp();
        // 只播结构/事实/设置，不播骨架内容，便于断言内容计数
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
        Setting::set('sync_geoflow_token', $this->token);
    }

    protected function validPayload(array $override = []): array
    {
        return array_merge([
            'external_id' => 'GF-1001',
            'type' => 'article',
            'title' => 'GEOFlow 推送的工业涂料知识文章',
            'slug' => 'gf-coating-knowledge',
            'category_slug' => 'knowledge',
            'summary' => '工业涂料知识摘要。',
            'body' => '正文内容，介绍工业防护涂料施工工艺。',
            'geo_conclusion' => '涂层防护性能取决于配方标准化与固化工艺参数控制。',
            'geo_explanation' => '通过高速分散与恒温固化，使涂层性能稳定均匀。',
            'geo_boundary' => '不同基材与工况参数不同，需以实际打样为准。',
            'geo_evidence' => [
                ['label' => '高速分散机', 'value' => '多台配置', 'source' => '设备台账'],
                ['label' => '恒温固化炉', 'value' => '温控分区', 'source' => '车间实拍'],
            ],
            'owner' => 'GEOFlow',
            'reviewed_at' => '2026-09-14',
        ], $override);
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_push_without_token_is_unauthorized(): void
    {
        $this->postJson('/api/v1/geoflow/contents', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_push_refused_when_switch_disabled(): void
    {
        // 默认开关关闭：Token 正确也拒绝写入
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->validPayload())
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, Content::where('external_id', 'GF-1001')->count());
    }

    public function test_gate_fail_is_rejected_even_when_enabled(): void
    {
        Setting::set('sync_geoflow_enabled', '1');
        $bad = $this->validPayload(['geo_conclusion' => '']); // 缺结论层

        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $bad)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, Content::count());
    }

    public function test_valid_push_creates_draft_when_auto_publish_off(): void
    {
        Setting::set('sync_geoflow_enabled', '1');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('action', 'create');

        $content = Content::where('external_id', 'GF-1001')->firstOrFail();
        $this->assertSame('draft', $content->status);
        $this->assertSame('geoflow', $content->external_source);
    }

    public function test_auto_publish_when_enabled(): void
    {
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->validPayload())
            ->assertOk();

        $this->assertSame('published', Content::where('external_id', 'GF-1001')->firstOrFail()->status);
    }

    public function test_duplicate_push_is_idempotent_skip(): void
    {
        Setting::set('sync_geoflow_enabled', '1');
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->validPayload())->assertOk();

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('action', 'skip');

        $this->assertSame(1, Content::where('external_id', 'GF-1001')->count());
    }

    public function test_manual_lock_blocks_overwrite_and_reports_conflict(): void
    {
        Setting::set('sync_geoflow_enabled', '1');
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->validPayload())->assertOk();

        $content = Content::where('external_id', 'GF-1001')->firstOrFail();
        $content->update(['lock_manual' => true, 'body' => '人工修改过的版本，与上游不同']);
        $content->content_hash = $content->computeHash();
        $content->save();

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->validPayload(['summary' => '上游又改了内容']))
            ->assertStatus(409)
            ->assertJsonPath('action', 'conflict');

        // 人工版本保留
        $this->assertStringContainsString('人工修改过', Content::find($content->id)->body);
    }

    public function test_unpublish_archives_content(): void
    {
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');
        $this->withToken($this->token)->postJson('/api/v1/geoflow/contents', $this->validPayload())->assertOk();

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'GF-1001'])
            ->assertOk();

        $this->assertSame('archived', Content::where('external_id', 'GF-1001')->firstOrFail()->status);
    }

    public function test_upsert_without_external_id_is_validation_error_not_500(): void
    {
        // 回归：缺 external_id 时曾因 reject(null) 类型错误返回 500
        Setting::set('sync_geoflow_enabled', '1');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', ['title' => '无外部ID'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_collection_list_endpoint_is_method_not_allowed(): void
    {
        // 无集合列表路由：GET /contents 必须 405，而不是落入通配路由
        $this->withToken($this->token)
            ->getJson('/api/v1/geoflow/contents')
            ->assertStatus(405);
    }

    public function test_check_endpoint_reports_gate_result_without_writing(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/check', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('passed', true);

        $this->assertSame(0, Content::count());
    }
}
