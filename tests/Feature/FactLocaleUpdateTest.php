<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-LOCALE-01 回归：事实「编辑」路径的语言语义与共享列广播。
 *
 * 修复前（两个真实缺陷，一先一后）：
 *
 * 缺陷一 · 编辑非默认语言行必然失败（静默）
 *   编辑态表单不渲染语言选择器，请求里没有 locale；
 *   FactController::validateData() 用 `input('locale') ?: 默认语言` 解析语言，
 *   于是一次「编辑英文事实」会被写成 locale=zh-CN，撞上唯一索引
 *   facts_site_id_key_locale_unique(site_id, key, locale)，
 *   UniqueConstraintViolationException 被转成「key 已被占用。」的字段错误，
 *   HTTP 302，数据库一行未改 —— 运营看到的是一条与真实原因无关的错误提示，
 *   而改动全部丢失。
 *
 * 缺陷二 · 共享列在非权威行「假生效」
 *   is_public / group / key / unit 等是跨语言共享列，语义上以默认语言
 *   权威行为准。修复缺陷一之前，编辑非权威行根本保存不下来，掩盖了
 *   「共享列只写当前行」的分裂：中文行仍 is_public=true、英文行已
 *   is_public=false，中英文站点对同一条事实给出不同的对外输出口径。
 *
 * 本测试全部显式按 locale 取行，不依赖 `where(key)->firstOrFail()`
 * 的返回顺序 —— 修复前的失败正是由数据库行序不同触发的。
 */
class FactLocaleUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** 取某条 key 在指定语言下的事实行（不依赖行序）。 */
    protected function factRow(string $key, string $locale): Fact
    {
        return Fact::withoutSiteScope()
            ->where('key', $key)
            ->where('locale', $locale)
            ->firstOrFail();
    }

    // F4-LOCALE-01 · 编辑非默认语言行不得改写该行的语言
    public function test_updating_non_default_locale_row_keeps_its_locale(): void
    {
        $en = $this->factRow('FACT-COMPANY-001', 'en');

        $response = $this->actingAs($this->admin)
            ->put('/admin/facts/' . $en->id, [
                'group'     => $en->group,
                'key'       => $en->key,
                'label'     => 'Company Name (edited)',
                'value'     => 'Example Manufacturing Co., Ltd.',
                'is_public' => '1',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $after = $this->factRow('FACT-COMPANY-001', 'en');

        $this->assertSame('en', $after->locale, '编辑不得改写该行的语言');
        $this->assertSame('Company Name (edited)', $after->label);
        $this->assertDatabaseMissing('facts', [
            'id'    => $en->id,
            'locale' => 'zh-CN',
        ]);
    }

    // F4-LOCALE-01 · 编辑非默认语言行必须真正落库（修复前因唯一约束被静默丢弃）
    public function test_updating_non_default_locale_row_persists_changes(): void
    {
        $en = $this->factRow('FACT-COMPANY-001', 'en');

        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $en->id, [
                'group'     => $en->group,
                'key'       => $en->key,
                'label'     => $en->label,
                'value'     => 'Edited English Value',
                'is_public' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('facts', [
            'id'    => $en->id,
            'value' => 'Edited English Value',
        ]);
    }

    // F4-LOCALE-01 · 共享列从非权威行写入时，必须广播到所有语言行
    public function test_shared_column_edit_on_non_anchor_row_broadcasts_to_all_locales(): void
    {
        $zh = $this->factRow('FACT-COMPANY-001', 'zh-CN');
        $en = $this->factRow('FACT-COMPANY-001', 'en');

        $this->assertTrue((bool) $zh->is_public);
        $this->assertTrue((bool) $en->is_public);

        // 在英文行上取消公开 —— 共享列语义上「对所有语言生效」
        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $en->id, [
                'group'     => $en->group,
                'key'       => $en->key,
                'label'     => $en->label,
                'value'     => $en->value,
                'is_public' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->factRow('FACT-COMPANY-001', 'zh-CN')->is_public);
        $this->assertFalse((bool) $this->factRow('FACT-COMPANY-001', 'en')->is_public);
    }

    // F4-LOCALE-01 · 空值发布门禁在非权威行上同样生效，并广播到所有语言行
    public function test_empty_value_gate_on_non_anchor_row_forces_unpublished_everywhere(): void
    {
        $en = $this->factRow('FACT-COMPANY-001', 'en');

        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $en->id, [
                'group'     => $en->group,
                'key'       => $en->key,
                'label'     => $en->label,
                'value'     => '',
                'is_public' => '1',   // 清空内容却仍要求公开
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('facts', [
            'id'        => $en->id,
            'value'     => '',
            'is_public' => 0,
        ]);
        // 权威行也必须同步下架，否则中文站仍对外输出这条空事实
        $this->assertDatabaseHas('facts', [
            'id'        => $this->factRow('FACT-COMPANY-001', 'zh-CN')->id,
            'is_public' => 0,
        ]);
    }

    // F4-LOCALE-01 · 权威行（默认语言）编辑仍按原语义工作：共享列广播、翻译列独立
    public function test_updating_anchor_row_still_broadcasts_shared_columns(): void
    {
        $zh = $this->factRow('FACT-COMPANY-001', 'zh-CN');
        $en = $this->factRow('FACT-COMPANY-001', 'en');

        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $zh->id, [
                'group'     => $zh->group,
                'key'       => $zh->key,
                'label'     => '公司全称（已改）',
                'value'     => '示例制造有限公司',
                'is_public' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 翻译列各语言独立：英文行的 label 不受权威行改动影响
        $this->assertSame('Company Name', $this->factRow('FACT-COMPANY-001', 'en')->label);
        $this->assertSame('公司全称（已改）', $this->factRow('FACT-COMPANY-001', 'zh-CN')->label);
        // 共享列广播
        $this->assertFalse((bool) $this->factRow('FACT-COMPANY-001', 'en')->is_public);
    }
}
