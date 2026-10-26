<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-01 / F4-02 / F4-03 回归：Fact 表单的重复键、空值与 key 格式校验。
 *
 * 修复前：
 * - 重复 key 触发唯一约束 → 未处理 500（F4-01）；
 * - 框架全局 ConvertEmptyStringsToNull 把空值转 null，违反 facts.value NOT NULL → 500（F4-02）；
 * - key 无格式规则，「INVALID KEY!!」可通过（F4-03）。
 */
class FactValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    // F4-01 · 创建时重复 key 应返回字段校验错误，而非 500
    public function test_duplicate_key_on_create_is_rejected(): void
    {
        // FactSeeder 同时录入 zh-CN 与 en，故该 key 初始有 2 行（每种语言一行）。
        $before = Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->count();

        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group' => 'company',
                'key'   => 'FACT-COMPANY-001', // 已由 FactSeeder 录入
                'label' => 'Duplicate',
                'value' => 'x',
            ])
            ->assertSessionHasErrors('key');

        // 没有产生额外记录
        $this->assertEquals(
            $before,
            Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->count()
        );
    }

    // F4-01 · 更新为另一条已存在的 key 应被拒绝
    public function test_duplicate_key_on_update_is_rejected(): void
    {
        $a = Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->firstOrFail();
        $b = Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-002')->firstOrFail();

        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $b->id, [
                'group' => $b->group,
                'key'   => $a->key, // 改成已存在的 key
                'label' => $b->label,
                'value' => $b->value,
            ])
            ->assertSessionHasErrors('key');

        // b 的 key 未被改动
        $this->assertEquals(
            'FACT-COMPANY-002',
            Fact::withoutSiteScope()->where('id', $b->id)->value('key')
        );
    }

    // F4-01 · 并发下预检通过但仍触发唯一约束时，应被捕获并转为字段错误（不 500）
    public function test_unique_constraint_race_is_converted_to_validation_error(): void
    {
        // 在唯一校验通过后、实际 INSERT 前，用 saving 事件插入一条冲突行，
        // 模拟并发请求抢先写入，使当前 INSERT 触发唯一约束。
        Fact::saving(function ($fact): void {
            // saving 早于 creating（BelongsToSite 此刻尚未写入 site_id），
            // 故直接取当前站点上下文。
            $now = now();
            \DB::table('facts')->insert([
                'site_id'           => \App\Support\SiteContext::currentSiteId(),
                'key'               => $fact->key,
                'locale'            => $fact->locale,
                'translation_group' => Fact::groupForKey($fact->key),
                'group'             => 'test',
                'label'             => 'race',
                'value'             => 'race',
                'is_public'         => 0,
                'sort'              => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        });

        $before = Fact::withoutSiteScope()->where('key', 'RACE-KEY')->count();

        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group' => 'test',
                'key'   => 'RACE-KEY',
                'label' => 'Race',
                'value' => 'v',
            ])
            ->assertSessionHasErrors('key');

        // 仅存在并发插入的 1 行，失败请求未产生额外记录。
        $this->assertEquals(
            $before + 1,
            Fact::withoutSiteScope()->where('key', 'RACE-KEY')->count()
        );
    }

    // F4-02 · 空值（待补事实）应被接受，不能 500
    public function test_empty_value_is_persisted_as_pending(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group'     => 'test',
                'key'       => 'PENDING-001',
                'label'     => 'Pending',
                'value'     => '',
                'is_public' => '0', // 待补，不对外
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key'       => 'PENDING-001',
            'is_public' => 0,
            'value'     => '',
        ]);
    }

    // F4-FINDING-01A · 创建时即使提交 is_public=1，空值事实也必须被强制设为不公开
    public function test_empty_value_on_create_is_forced_unpublished(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group'     => 'test',
                'key'       => 'GATE-CREATE-001',
                'label'     => 'Gate Create',
                'value'     => '',
                'is_public' => '1', // 试图公开空事实
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key'       => 'GATE-CREATE-001',
            'value'     => '',
            'is_public' => 0,
        ]);

        // 空值事实不进入任何公共取数口
        $this->assertArrayNotHasKey('GATE-CREATE-001', Fact::publicMap());
        $this->assertSame(
            0,
            Fact::publicRows()->where('key', 'GATE-CREATE-001')->count()
        );
    }

    // F4-FINDING-01A · 编辑时把内容清空并保持公开，也必须被强制设为不公开
    public function test_empty_value_on_update_is_forced_unpublished(): void
    {
        $fact = Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->firstOrFail();

        $this->actingAs($this->admin)
            ->put('/admin/facts/' . $fact->id, [
                'group'     => $fact->group,
                'key'       => $fact->key,
                'label'     => $fact->label,
                'value'     => '',
                'is_public' => '1', // 清空内容却仍要求公开
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'id'        => $fact->id,
            'value'     => '',
            'is_public' => 0,
        ]);
    }

    // F4-FINDING-01A · 非空事实保持公开，门禁不产生误伤
    public function test_nonempty_value_remains_published(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group'     => 'test',
                'key'       => 'GATE-NONEMPTY-001',
                'label'     => 'Gate Nonempty',
                'value'     => '有内容',
                'is_public' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key'       => 'GATE-NONEMPTY-001',
            'is_public' => 1,
        ]);
        $this->assertArrayHasKey('GATE-NONEMPTY-001', Fact::publicMap());
    }

    // F4-03 · 非法 key 格式应被拒绝
    public function test_invalid_key_format_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/facts', [
                'group' => 'test',
                'key'   => 'INVALID KEY!!',
                'label' => 'Bad',
                'value' => 'v',
            ])
            ->assertSessionHasErrors('key');

        $this->assertEquals(
            0,
            Fact::withoutSiteScope()->where('key', 'INVALID KEY!!')->count()
        );
    }

    // F4-03 · 合法 key（大写连字符、小写下划线）均可正常创建
    public function test_valid_key_formats_are_accepted(): void
    {
        foreach (['FACT-UPPER-001', 'lower_key_002'] as $key) {
            $this->actingAs($this->admin)
                ->post('/admin/facts', [
                    'group' => 'test',
                    'key'   => $key,
                    'label' => 'OK',
                    'value' => 'v',
                ])
                ->assertRedirect();
        }

        $this->assertDatabaseHas('facts', ['key' => 'FACT-UPPER-001']);
        $this->assertDatabaseHas('facts', ['key' => 'lower_key_002']);
    }

    /**
     * F4-FINDING-01C · 建一个仅含默认语言（zh-CN）的公开锚点，用于补录翻译。
     */
    private function makeAnchor(string $key): Fact
    {
        return Fact::create([
            'key'               => $key,
            'label'             => '锚点',
            'value'             => '有内容',
            'group'             => 'test',
            'is_public'         => true,
            'locale'            => 'zh-CN',
            'translation_group' => Fact::groupForKey($key),
        ]);
    }

    // 01C · 空字符串补录翻译：不 500，落库为 ''
    public function test_empty_value_translation_is_saved_as_empty_string(): void
    {
        $anchor = $this->makeAnchor('TRANS-EMPTY-001');

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Empty', 'value' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key' => 'TRANS-EMPTY-001', 'locale' => 'en', 'value' => '',
        ]);
    }

    // 01C · 纯空白补录翻译：经 Trim/ConvertEmpty 后落库为 ''
    public function test_whitespace_only_translation_is_saved_as_empty_string(): void
    {
        $anchor = $this->makeAnchor('TRANS-WS-001');

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Ws', 'value' => '   ',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key' => 'TRANS-WS-001', 'locale' => 'en', 'value' => '',
        ]);
    }

    // 01C · 缺省 value 字段：不报错，落库为 ''
    public function test_missing_value_translation_is_saved_as_empty_string(): void
    {
        $anchor = $this->makeAnchor('TRANS-MISS-001');

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Miss',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key' => 'TRANS-MISS-001', 'locale' => 'en', 'value' => '',
        ]);
    }

    // 01C · 正常翻译（有 value）正确保存，归一不影响正常内容
    public function test_normal_translation_saves_value(): void
    {
        $anchor = $this->makeAnchor('TRANS-NORM-001');

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Norm', 'value' => 'English content',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('facts', [
            'key' => 'TRANS-NORM-001', 'locale' => 'en', 'value' => 'English content',
        ]);
    }

    // 01C · 同 locale 重复补录仍被拒绝，不产生额外行
    public function test_duplicate_locale_translation_is_rejected(): void
    {
        $anchor = Fact::withoutSiteScope()
            ->where('key', 'FACT-COMPANY-001')->where('locale', 'zh-CN')->firstOrFail();
        $before = Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->count();

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Dup', 'value' => 'dup',
            ])
            ->assertSessionHas('error');

        $this->assertEquals(
            $before,
            Fact::withoutSiteScope()->where('key', 'FACT-COMPANY-001')->count()
        );
    }

    // 01C · 空翻译不进入 geo.json 与 llms.txt（与 01B/llms 空值过滤联动）
    public function test_empty_translation_is_excluded_from_geo_outputs(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        $anchor = $this->makeAnchor('TRANS-GEO-001');

        $this->actingAs($this->admin)
            ->post('/admin/facts/'.$anchor->id.'/translations', [
                'locale' => 'en', 'label' => 'Geo', 'value' => '',
            ])
            ->assertRedirect();

        $enGeo = $this->get('/en/geo.json')->assertOk()->json();
        $this->assertNotContains(
            'TRANS-GEO-001',
            array_column($enGeo['facts'], 'key'),
            '空翻译不得进入 geo.json'
        );

        $this->assertStringNotContainsString(
            'TRANS-GEO-001',
            $this->get('/en/llms.txt')->assertOk()->getContent(),
            '空翻译不得进入 llms.txt'
        );
    }
}
