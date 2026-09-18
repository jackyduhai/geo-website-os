<?php

namespace Tests\Feature;

use App\Models\ContentRevision;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5.4-B: 验证3张空表（content_revisions, inquiries, sync_logs）的 site_id 迁移。
 *
 * 覆盖：
 * - site_id 列存在且 NOT NULL
 * - FK site_id → sites.id 生效
 * - 不存在的 site_id 被拒绝
 * - 多 Site 数据可区分
 * - 原有测试不受影响
 */
class SiteIdEmptyTablesTest extends TestCase
{
    use RefreshDatabase;

    // ── Test 1: 三个表存在 site_id 列 ─────────────────────────────

    public function test_content_revisions_has_site_id_column(): void
    {
        $cols = collect(DB::select('PRAGMA table_info(content_revisions)'));
        $this->assertNotNull($cols->firstWhere('name', 'site_id'));
    }

    public function test_inquiries_has_site_id_column(): void
    {
        $cols = collect(DB::select('PRAGMA table_info(inquiries)'));
        $this->assertNotNull($cols->firstWhere('name', 'site_id'));
    }

    public function test_sync_logs_has_site_id_column(): void
    {
        $cols = collect(DB::select('PRAGMA table_info(sync_logs)'));
        $this->assertNotNull($cols->firstWhere('name', 'site_id'));
    }

    // ── Test 2: site_id NOT NULL ──────────────────────────────────

    public function test_site_id_is_not_null_on_all_three_tables(): void
    {
        foreach (['content_revisions', 'inquiries', 'sync_logs'] as $table) {
            $col = collect(DB::select("PRAGMA table_info($table)"))->firstWhere('name', 'site_id');
            $this->assertEquals(1, $col->notnull, "$table.site_id should be NOT NULL");
        }
    }

    // ── Test 3: FK site_id → sites.id ─────────────────────────────

    public function test_foreign_key_site_id_to_sites_exists(): void
    {
        foreach (['content_revisions', 'inquiries', 'sync_logs'] as $table) {
            $fks = DB::select("PRAGMA foreign_key_list($table)");
            $siteFk = collect($fks)->firstWhere('from', 'site_id');
            $this->assertNotNull($siteFk, "$table should have FK on site_id");
            $this->assertEquals('sites', $siteFk->table);
            $this->assertEquals('id', $siteFk->to);
        }
    }

    // ── Test 4: 不存在的 site_id 无法插入 ─────────────────────────

    public function test_invalid_site_id_is_rejected_by_fk(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('inquiries')->insert([
            'site_id' => 999999,
            'name' => 'fk-test',
            'phone' => '000',
            'message' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Test 5: 合法 Site A 可以插入 ──────────────────────────────

    public function test_valid_site_a_can_insert_inquiry(): void
    {
        $siteA = Site::create(['name' => 'Site A', 'slug' => 'site-a', 'status' => 'active']);

        $inquiry = Inquiry::create([
            'site_id' => $siteA->id,
            'name' => 'Test User',
            'phone' => '13800000000',
            'message' => 'Hello',
        ]);

        $this->assertEquals($siteA->id, $inquiry->site_id);
        $this->assertDatabaseHas('inquiries', ['id' => $inquiry->id, 'site_id' => $siteA->id]);
    }

    // ── Test 6: 合法 Site B 可以插入 ──────────────────────────────

    public function test_valid_site_b_can_insert_sync_log(): void
    {
        $siteB = Site::create(['name' => 'Site B', 'slug' => 'site-b', 'status' => 'active']);

        $log = SyncLog::create([
            'site_id' => $siteB->id,
            'direction' => 'inbound',
            'action' => 'test',
            'status' => 'ok',
        ]);

        $this->assertEquals($siteB->id, $log->site_id);
    }

    // ── Test 7: 三个表的数据可以按照 site_id 区分 ─────────────────

    public function test_data_is_isolated_by_site_id(): void
    {
        $siteA = Site::create(['name' => 'Site A', 'slug' => 'site-a', 'status' => 'active']);
        $siteB = Site::create(['name' => 'Site B', 'slug' => 'site-b', 'status' => 'active']);

        Inquiry::create(['site_id' => $siteA->id, 'name' => 'A User', 'phone' => '111', 'message' => 'A']);
        Inquiry::create(['site_id' => $siteA->id, 'name' => 'A User 2', 'phone' => '222', 'message' => 'A2']);
        Inquiry::create(['site_id' => $siteB->id, 'name' => 'B User', 'phone' => '333', 'message' => 'B']);

        $this->assertEquals(2, Inquiry::where('site_id', $siteA->id)->count());
        $this->assertEquals(1, Inquiry::where('site_id', $siteB->id)->count());
    }

    // ── Test 8: Model site() 关系正常 ─────────────────────────────

    public function test_model_site_relation_works(): void
    {
        $site = Site::default();

        $inquiry = Inquiry::create([
            'site_id' => $site->id,
            'name' => 'Relation Test',
            'phone' => '999',
            'message' => 'test relation',
        ]);

        $this->assertInstanceOf(Site::class, $inquiry->site);
        $this->assertEquals($site->id, $inquiry->site->id);
    }

    // ── Test 9: ContentRevision site() 关系 ───────────────────────

    public function test_content_revision_site_relation(): void
    {
        $site = Site::default();

        // content_revisions 需要 content_id，创建一个临时 content
        $content = \App\Models\Content::create([
            'title' => 'Temp',
            'slug' => 'temp-for-revision',
            'body' => 'temp',
            'status' => 'draft',
        ]);

        $revision = ContentRevision::create([
            'site_id' => $site->id,
            'content_id' => $content->id,
            'snapshot' => ['title' => 'Temp'],
        ]);

        $this->assertEquals($site->id, $revision->site->id);

        // 清理
        $revision->delete();
        $content->forceDelete();
    }

    // ── Test 10: foreign_key_check 无违规 ─────────────────────────

    public function test_foreign_key_check_has_no_violations(): void
    {
        $violations = DB::select('PRAGMA foreign_key_check');
        $this->assertEmpty($violations);
    }
}
