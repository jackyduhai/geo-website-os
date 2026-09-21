<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 后台事实库管理（HTTP 层）回归。
 *
 * UAT Bug#6：fact-form 表单与 FactController 暴露 unit、source_url，但 facts
 * 建表迁移遗漏这两列，后台新增事实必然以 "table facts has no column named unit"
 * 返回 500。补齐迁移后，带这两个字段的事实必须能正常持久化。
 */
class FactAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_facts_table_has_unit_and_source_url_columns(): void
    {
        $cols = collect(DB::select('PRAGMA table_info(facts)'))->pluck('name')->all();
        $this->assertContains('unit', $cols);
        $this->assertContains('source_url', $cols);
    }

    public function test_admin_can_create_fact_with_unit_and_source_url(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group'       => 'uat',
                'key'         => 'uat_metric',
                'label'       => 'UAT Metric',
                'value'       => '100',
                'unit'        => 'items',
                'source'      => 'UAT generic sample',
                'source_url'  => 'https://example.com/source',
                'is_public'   => '1',
                'reviewed_at' => '2026-09-21',
                'review_due'  => '2027-03-21',
                'sort'        => '99',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'site_id'    => Site::defaultId(),
            'key'        => 'uat_metric',
            'label'      => 'UAT Metric',
            'unit'       => 'items',
            'source_url' => 'https://example.com/source',
        ]);
    }

    public function test_duplicate_fact_does_not_500_on_missing_label(): void
    {
        // label 必填：缺字段应回到表单校验，而不是 500。
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group' => 'uat',
                'key'   => 'no_label',
            ])
            ->assertSessionHasErrors('label');

        $this->assertEquals(0, Fact::withoutSiteScope()->where('key', 'no_label')->count());
    }
}
