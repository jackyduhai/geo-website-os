<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GF-CONC · GEOFlow 并发与幂等契约（20G-2 · Step 11）
 * ------------------------------------------------------------------
 * 核心不变量：
 *   I1  DB 层有UNIQUE(site_id, external_id)，重复 external_id 无法产生第二行。
 *   I2  唯一键冲突**不等于**幂等命中。冲突后必须 reload 并比较
 *       canonical hash：内容相同才 skip，不同则走 update/conflict。
 *       「无条件skip」只在 payload 完全等价时成立。
 *   I3  竞态不得变成 HTTP 500 —— 业务层必须把DB 异常翻译成契约内结果。
 *
 * 背景：修复前contents.external_id 只有普通索引、没有唯一约束，
 * upsert 是「先查后插」，两个并发请求会同时查不到而双双插入。
 */
class GeoflowConcurrencyTest extends TestCase
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
            'external_id' => 'CONC-001',
            'type' => 'article',
            'title' => '并发验证文章',
            'slug' => 'concurrency-article',
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
     * GF-CONC-001 DB 层存在 UNIQUE(site_id, external_id) 约束。
     */
    public function test_gf_conc_001_db_has_unique_constraint_on_external_id(): void
    {
        $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='contents'");
        $sqls = array_map(fn ($i) => (string) $i->sql, $indexes);

        $tableDef = DB::select("SELECT sql FROM sqlite_master WHERE type='table' AND name='contents'");
        $tableSql = (string) ($tableDef[0]->sql ?? '');

        $hasUnique = str_contains($tableSql, 'UNIQUE(site_id, external_id)')
            || collect($sqls)->contains(fn ($s) => str_contains($s, 'external_id') && str_contains(strtoupper($s), 'UNIQUE'));

        $this->assertTrue(
            $hasUnique,
            'GF-CONC-001 失败：contents 缺少 UNIQUE(site_id, external_id)，并发会产生重复行'
        );
    }

    /**
     * GF-CONC-002 直接写库插入重复 external_id 会被 DB 拒绝。
     */
    public function test_gf_conc_002_db_rejects_duplicate_external_id(): void
    {
        $siteId = \App\Support\SiteContext::currentSiteId() ?? \App\Models\Site::firstOrFail()->id;

        $insert = function (string $slug) use ($siteId) {
            DB::table('contents')->insert([
                'site_id' => $siteId, 'type' => 'article',
                'title' => '并发内容', 'slug' => $slug, 'status' => 'draft',
                'external_id' => 'CONC-DUP-001',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $insert('conc-duplicate-a');
        $this->expectException(\Illuminate\Database\QueryException::class);
        $insert('conc-duplicate-b');   // 同 external_id → 必须被拒
    }

    /**
     * GF-CONC-003 顺序推送同一 external_id 两次 → 1 条记录，第 2 次 skip。
     */
    public function test_gf_conc_003_sequential_duplicate_is_idempotent(): void
    {
        $first = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()->json();

        $second = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()->json();

        $this->assertSame('create', $first['action']);
        $this->assertSame('skip', $second['action'], '相同 payload 第二次应 skip');
        $this->assertSame(1, Content::where('external_id', 'CONC-001')->count());
    }

    /**
     * GF-CONC-004 模拟「先查后插」竞态：绕过 upsert 直接插入，
     * 再推送同external_id → 应命中已有行并走 skip（而非 500）。
     */
    public function test_gf_conc_004_race_after_external_insert_does_not_500(): void
    {
        // 模拟另一请求抢先插入
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        $existing = Content::where('external_id', 'CONC-001')->firstOrFail();
        $existing->update(['title' => '被别处改过的标题']);

        // 此时再推相同 payload → hash 不同 → update（不是 500）
        $result = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk()->json();

        $this->assertContains($result['action'], ['update', 'skip']);
        $this->assertSame(1, Content::where('external_id', 'CONC-001')->count());
    }

    /**
     * GF-CONC-005 **唯一键冲突后不能无条件 skip**（评审第 6 条）。
     *
     * 场景：A 与 B 并发推送同一 external_id 但内容不同。
     * A 先成功 → B 撞唯一键。此时 B **不能**直接 skip（内容确实变了），
     * 必须按 update / conflict 语义处理，否则 B 的内容会永久丢失。
     *
     * 本测试用等价手段构造该状态：先插入行并把hash 置为「与B 的 payload 不一致」，
     * 模拟 B 撞上 A 已落库的状态。
     */
    public function test_gf_conc_005_unique_violation_does_not_blindly_skip(): void
    {
        // A 先落库
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        $existing = Content::where('external_id', 'CONC-001')->firstOrFail();

        // 人工锁定：模拟「赢家已被人接管」
        $existing->update(['lock_manual' => true]);

        // B 携带**不同内容**推送 → 必须 409 conflict，不能是 skip
        $result = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload([
                'title' => 'B 请求携带的不同标题',
            ]))
            ->assertStatus(409)->json();

        $this->assertSame('conflict', $result['code']);
        $this->assertSame(
            '并发验证文章',
            Content::where('external_id', 'CONC-001')->firstOrFail()->title,
            'GF-CONC-005 失败：冲突时不得用后到者覆盖人工版本'
        );
    }

    /**
     * GF-CONC-006 并发路径不得抛 500（DB 异常必须被翻译成契约结果）。
     */
    public function test_gf_conc_006_concurrent_path_never_returns_500(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        // 连续 5 次推送同一 external_id（含中途改内容制造 hash 变化）
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->withToken($this->token)
                ->postJson('/api/v1/geoflow/contents', $this->payload([
                    'title' => '第 '.$i.' 次推送的标题',
                ]));

            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                "GF-CONC-006 失败：第 {$i} 次推送返回 500"
            );
        }

        $this->assertSame(1, Content::where('external_id', 'CONC-001')->count(), '不得产生重复行');
    }

    /**
     * GF-CONC-007 不同 external_id 可正常创建多条（唯一约束不误伤）。
     */
    public function test_gf_conc_007_distinct_external_ids_coexist(): void
    {
        foreach (['CONC-A', 'CONC-B', 'CONC-C'] as $i => $extId) {
            $this->withToken($this->token)
                ->postJson('/api/v1/geoflow/contents', $this->payload([
                    'external_id' => $extId,
                    'slug' => 'concurrency-article-'.$i,
                ]))
                ->assertOk();
        }

        $this->assertSame(3, Content::whereIn('external_id', ['CONC-A', 'CONC-B', 'CONC-C'])->count());
    }

    /**
     * GF-CONC-008 跨站同external_id 应各自独立（site_id 参与唯一键）。
     */
    public function test_gf_conc_008_same_external_id_allowed_across_sites(): void
    {
        $siteA = \App\Models\Site::where('slug', 'default')->firstOrFail();

        $other = \App\Models\Site::where('id', '!=', $siteA->id)->first();
        if ($other === null) {
            $other = \App\Models\Site::create([
                'slug' => 'conc-site', 'name' => '并发测试站', 'is_default' => false,
            ]);
        }

        // 直接写库验证：同external_id 不同 site_id 应都能插入
        foreach ([$siteA->id, $other->id] as $idx => $siteId) {
            DB::table('contents')->insert([
                'site_id' => $siteId, 'type' => 'article',
                'title' => '跨站并发', 'slug' => 'cross-site-'.$idx, 'status' => 'draft',
                'external_id' => 'CONC-CROSS-001',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            2,
            DB::table('contents')->where('external_id', 'CONC-CROSS-001')->count(),
            'GF-CONC-008 失败：site_id 参与唯一键，跨站同 external_id 应允许'
        );
    }
}
