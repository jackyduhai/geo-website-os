<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-STATE · GEOFlow 状态与安全边界契约（20G-2 · C-2 · C-6）
 * ------------------------------------------------------------------
 * 核心不变量：
 *   I1  总开关关闭时，**所有写入口**都必须拒绝（C-2）。
 *       历史缺陷：开关只保护 upsert，unpublish 仍可批量下架全站内容——
 *       运维的紧急止杀手段在最危险路径上失效。
 *   I2  lock_manual=1 的内容，upsert 与 unpublish 都不得改动（C-6）。
 *       历史缺陷：upsert 有 409 冲突保护，unpublish 直接归档，
 *       且不写 ContentRevision，事后无法还原。
 *   I3  状态流转必须留痕：unpublish 也要写 Revision 快照。
 */
class GeoflowStateSafetyTest extends TestCase
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
            'external_id' => 'STATE-001',
            'type' => 'article',
            'title' => '状态安全验证文章',
            'slug' => 'state-safety-article',
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

    protected function push(array $override = [])
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload($override));
    }

    // ─────────────────────────────────────────────────────────
    // I1 · 总开关是真正的 Kill Switch（C-2）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-STATE-001 开关关闭 → upsert 拒绝 403。
     */
    public function test_gf_state_001_switch_off_blocks_upsert(): void
    {
        $this->push()->assertOk();
        Setting::set('sync_geoflow_enabled', '0');

        $this->push(['title' => '改标题'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'disabled');
    }

    /**
     * GF-STATE-002 开关关闭 → **unpublish 也必须拒绝**。
     *
     * 这是 C-2 的核心断言。修复前 unpublish 完全不检查开关，
     * 运维关闭开关后仍能用它把全站内容批量下架。
     */
    public function test_gf_state_002_switch_off_blocks_unpublish(): void
    {
        $this->push()->assertOk();
        $before = Content::where('external_id', 'STATE-001')->firstOrFail()->status;

        Setting::set('sync_geoflow_enabled', '0');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'STATE-001'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'disabled');

        $after = Content::where('external_id', 'STATE-001')->firstOrFail()->status;

        $this->assertSame(
            $before,
            $after,
            'GF-STATE-002 失败：开关关闭时 unpublish 不得改变内容状态'
        );
        $this->assertSame('published', $after);
    }

    /**
     * GF-STATE-003 check 是只读预检，不受写开关约束。
     *
     * 开关语义是「关闭写入能力」，不是「关闭一切可访问性」——
     * GEOFlow 上线前要反复调预检，被拦住就无法自检。
     * 但响应必须回带 push_enabled 让上游能感知状态。
     */
    public function test_gf_state_003_check_is_readonly_and_reports_push_enabled(): void
    {
        Setting::set('sync_geoflow_enabled', '0');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/check', $this->payload())
            ->assertOk()
            ->assertJsonPath('push_enabled', false);
    }

    /**
     * GF-STATE-004 开关关闭时不写库（拒绝必须无副作用）。
     */
    public function test_gf_state_004_switch_off_writes_nothing(): void
    {
        Setting::set('sync_geoflow_enabled', '0');

        $this->push()->assertStatus(403);

        $this->assertDatabaseMissing('contents', ['external_id' => 'STATE-001']);
    }

    // ─────────────────────────────────────────────────────────
    // I2 · lock_manual 保护（C-6）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-STATE-005 lock_manual=1 → upsert 变更返回 409。
     */
    public function test_gf_state_005_manual_lock_blocks_upsert(): void
    {
        $this->push()->assertOk();
        Content::where('external_id', 'STATE-001')->firstOrFail()->update(['lock_manual' => true]);

        $this->push(['title' => '上游想改标题'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflict');

        $this->assertSame(
            '状态安全验证文章',
            Content::where('external_id', 'STATE-001')->firstOrFail()->title,
            '人工锁定后上游不得覆盖'
        );
    }

    /**
     * GF-STATE-006 lock_manual=1 → **unpublish 必须返回 409 而非执行**。
     *
     * 这是 C-6 的核心断言。lock_manual 的语义是「已被人工接管」，
     * 自动同步不得改 status。若确需强制下架，应走后台人工操作并留 AuditLog。
     */
    public function test_gf_state_006_manual_lock_blocks_unpublish(): void
    {
        $this->push()->assertOk();
        Content::where('external_id', 'STATE-001')->firstOrFail()->update(['lock_manual' => true]);

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'STATE-001'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflict');

        $this->assertSame(
            'published',
            Content::where('external_id', 'STATE-001')->firstOrFail()->status,
            'GF-STATE-006 失败：人工锁定内容不得被上游下架'
        );
    }

    /**
     * GF-STATE-007 lock_manual=1 → unpublish 被拒时写 conflict 日志（可审计）。
     */
    public function test_gf_state_007_blocked_unpublish_is_audited(): void
    {
        $this->push()->assertOk();
        Content::where('external_id', 'STATE-001')->firstOrFail()->update(['lock_manual' => true]);

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'STATE-001'])
            ->assertStatus(409);

        $this->assertDatabaseHas('sync_logs', [
            'external_id' => 'STATE-001',
            'action' => 'conflict',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // I3 · 状态流转必须留痕
    // ─────────────────────────────────────────────────────────

    /**
     * GF-STATE-008 unpublish 成功必须写 ContentRevision 快照。
     *
     * 修复前 unpublish 只写 SyncLog 不写 Revision，事后无法还原。
     * 快照需含 before/after 状态与来源，便于追溯「谁、什么时间、
     * 从什么状态变成什么状态」。
     */
    public function test_gf_state_008_unpublish_writes_revision_snapshot(): void
    {
        $this->push()->assertOk();
        $revisionsBefore = ContentRevision::where('content_id', Content::where('external_id', 'STATE-001')->firstOrFail()->id)->count();

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'STATE-001'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $contentId = Content::where('external_id', 'STATE-001')->firstOrFail()->id;
        $revisionsAfter = ContentRevision::where('content_id', $contentId)->count();

        $this->assertGreaterThan(
            $revisionsBefore,
            $revisionsAfter,
            'GF-STATE-008 失败：unpublish 必须写 ContentRevision 以便回滚'
        );

        $latest = ContentRevision::where('content_id', $contentId)
            ->orderByDesc('id')->firstOrFail();

        $snapshot = is_array($latest->snapshot) ? $latest->snapshot : json_decode((string) $latest->snapshot, true);

        $this->assertIsArray($snapshot, '快照必须是可读结构');
        $this->assertSame('published', $snapshot['before']['status'] ?? null, '快照应记录变更前状态');
        $this->assertSame('archived', $snapshot['after']['status'] ?? null, '快照应记录变更后状态');
        $this->assertSame('geoflow', $snapshot['source'] ?? null, '快照应记录来源');
        $this->assertSame('STATE-001', $snapshot['external_id'] ?? null, '快照应记录 external_id 便于追溯');
    }

    /**
     * GF-STATE-009 快照含状态字段（status 不在 hash 但必须在快照）。
     */
    public function test_gf_state_009_revision_includes_state_fields(): void
    {
        $revisionable = \App\Support\ContentFieldContract::revisionableFields();

        $this->assertContains('status', $revisionable);
        $this->assertContains('lock_manual', $revisionable);
        $this->assertContains('external_id', $revisionable);
    }

    /**
     * GF-STATE-010 unpublish 目标不存在 → 404（不是 500）。
     */
    public function test_gf_state_010_unpublish_unknown_id_is_404(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'NO-SUCH-ID'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    /**
     * GF-STATE-011 unpublish 缺 external_id → 422。
     */
    public function test_gf_state_011_unpublish_without_external_id_is_422(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', [])
            ->assertStatus(422);
    }
}
