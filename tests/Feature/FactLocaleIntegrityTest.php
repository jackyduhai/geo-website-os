<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Site;
use App\Support\Localization\LocaleContext;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20G-3 · facts 行级翻译契约（FAC-001~010）
 * ------------------------------------------------------------------
 * 本类锁定的核心不变量：
 *
 *  1. `key` 是语义身份、`translation_group` 是翻译身份，二者职责不混；
 *  2. 取数口（publicRows / publicMap / publicByLabel / groupMap）**按 locale 确定性**，
 *     同一进程内先后读 zh / en 不得串档；
 *  3. **禁止跨语言 fallback**：en 缺翻译时宁可少一条，不回退中文。
 *  4. UNIQUE(site_id, key, locale) 允许同 key 中英共存，同时拒绝同站同语言重复。
 *
 * 这些断言曾以「源码里有没有 forLocale」的形式出现过（20G-8 的 MRF），
 * 那种判据只守得住字符串存在性、守不住实际行为。本类一律用**真实写入 + 真实读取**
 * 判定 —— 注入「去掉 forLocale」的变异后必须变红（见 docs/audit/20G/gates/）。
 */
class FactLocaleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::where('is_default', true)->first()
            ?? Site::create([
                'name' => 'T', 'slug' => 't-site', 'domain' => 't.test',
                'status' => 'active', 'is_default' => true,
            ]);

        SiteContext::setSite($this->site);
        Fact::flushMemo();

        // 三个事实，每条都有中英两行（同 key、同 translation_group）
        foreach ([
            ['FACT-CO-001', '公司名称', 'Demo Tenant A', 'Company Name', 'Demo Tenant A Ltd.'],
            ['FACT-CO-002', '成立时间', '2017 年 3 月', 'Founded', 'March 2017'],
            ['FACT-CO-003', '员工规模', '200 人', 'Headcount', '200 staff'],
        ] as [$key, $zhLabel, $zhValue, $enLabel, $enValue]) {
            $group = Fact::groupForKey($key);
            Fact::create([
                'key' => $key, 'label' => $zhLabel, 'value' => $zhValue,
                'group' => 'company', 'is_public' => true, 'sort' => 1,
                'locale' => 'zh-CN', 'translation_group' => $group,
            ]);
            Fact::create([
                'key' => $key, 'label' => $enLabel, 'value' => $enValue,
                'group' => 'company', 'is_public' => true, 'sort' => 1,
                'locale' => 'en', 'translation_group' => $group,
            ]);
        }

        Fact::flushMemo();
    }

    protected function tearDown(): void
    {
        LocaleContext::clear();
        Fact::flushMemo();

        parent::tearDown();
    }

    /** FAC-001  schema 必须有 locale / translation_group */
    public function test_fac_001_facts_schema_has_i18n_columns(): void
    {
        $this->assertTrue(\Schema::hasColumn('facts', 'locale'), 'facts 缺 locale 列');
        $this->assertTrue(\Schema::hasColumn('facts', 'translation_group'), 'facts 缺 translation_group 列');
    }

    /** FAC-002  UNIQUE(site_id, key, locale) 存在，且不含旧的 UNIQUE(site_id, key) */
    public function test_fac_002_unique_constraint_includes_locale(): void
    {
        $uniques = $this->uniqueColumnSets('facts');

        $this->assertContains('site_id,key,locale', $uniques,
            'facts 必须有 UNIQUE(site_id, key, locale) 才能让同 key 中英共存；实际：'
            .implode(' | ', $uniques));
        $this->assertNotContains('site_id,key', $uniques,
            '旧的 UNIQUE(site_id, key) 会禁止同 key 的中英两行共存，必须已移除');
    }

    /**
     * FAC-003  行为型约束（不看 sqlite_master，用真实写入判定）
     *   · 同站+同语言+同 key → 必须被拒
     *   · 同站+不同语言+同 key → 必须允许
     */
    public function test_fac_003_behavioral_unique_constraint(): void
    {
        // 同站同语言同 key → 拒绝
        $rejected = false;
        try {
            Fact::create([
                'key' => 'FACT-CO-001', 'label' => '重复', 'value' => 'DUP',
                'is_public' => true, 'locale' => 'zh-CN',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException|\PDOException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, '同站+同语言+同 key 写入必须被唯一约束拒绝');

        // 同站不同语言同 key → 允许（上一段已插过 zh 与 en）
        $this->assertSame(2, Fact::query()->where('key', 'FACT-CO-001')->count(),
            'FACT-CO-001 应有 zh / en 两行');
    }

    /** FAC-004  translation_group 按 key 确定性派生（不是随机 UUID） */
    public function test_fac_004_translation_group_is_deterministic_per_key(): void
    {
        $row = Fact::query()->where('key', 'FACT-CO-001')->where('locale', 'zh-CN')->first();

        $this->assertSame('TG-FACT-FACT-CO-001', $row->translation_group,
            'translation_group 必须由 key 派生（TG-FACT-{key}），否则后台无法只凭 key 归组');

        // 另一条 key 派生不同group
        $other = Fact::query()->where('key', 'FACT-CO-002')->where('locale', 'zh-CN')->first();
        $this->assertNotSame($row->translation_group, $other->translation_group,
            '不同 key 必须派生不同 translation_group');
    }

    /**
     * FAC-004b  translation_group 的 **creating 钩子**必须在未显式指定时自动派生
     *
     * ⚠️ 上一条 FAC-004 用例在 setUp 里显式传了 translation_group，
     * 会**完全绕过** creating 钩子 —— 于是「钩子失效」这个变异抓不到它。
     * 契约必须覆盖真实写入路径（后台新增事实时运营不会填 group）。
     */
    public function test_fac_004b_creating_hook_derives_group_when_not_given(): void
    {
        $row = Fact::create([
            'key' => 'FACT-NEW-001', 'label' => '新事实', 'value' => 'V',
            'is_public' => true, 'locale' => 'zh-CN',
            // ⚠️ 刻意不传 translation_group
        ]);

        $this->assertSame('TG-FACT-FACT-NEW-001', $row->translation_group,
            '未显式指定 translation_group 时，creating 钩子必须按 key 派生'
            .'（后台新增事实时运营人员不填 group id）');
    }

    /** 已有值不覆盖：允许数据修复场景显式指定归组 */
    public function test_fac_004c_creating_hook_respects_explicit_group(): void
    {
        $row = Fact::create([
            'key' => 'FACT-FIX-001', 'label' => '修复', 'value' => 'V',
            'is_public' => true, 'locale' => 'zh-CN',
            'translation_group' => 'TG-CUSTOM',
        ]);

        $this->assertSame('TG-CUSTOM', $row->translation_group,
            '显式指定的 translation_group 不应被creating 钩子覆盖');
    }

    /** FAC-005  同 key 的中英行必须共享同一 translation_group */
    public function test_fac_005_same_key_translations_share_group(): void
    {
        $groups = Fact::query()
            ->where('key', 'FACT-CO-001')
            ->pluck('translation_group')
            ->unique();

        $this->assertCount(1, $groups, '同 key 的多语言行必须同组，实际：'.$groups->implode(','));
    }

    /**
     * FAC-006（**本轮核心**）  publicRows() 的语言确定性
     *
     * 变异测试目标：把 publicRows() 的 forLocale 去掉后，本断言必须变红。
     */
    public function test_fac_006_public_rows_is_locale_deterministic(): void
    {
        $zh = Fact::publicRows('zh-CN');
        $en = Fact::publicRows('en');

        $this->assertCount(3, $zh, 'zh 应有 3 条事实');
        $this->assertCount(3, $en, 'en 应有 3 条事实');

        $this->assertSame(['zh-CN'], $zh->pluck('locale')->unique()->values()->all(),
            'publicRows(zh-CN) 必须只返回 zh 行');
        $this->assertSame(['en'], $en->pluck('locale')->unique()->values()->all(),
            'publicRows(en) 必须只返回 en 行');

        $zhLabels = $zh->pluck('label')->all();
        $enLabels = $en->pluck('label')->all();
        $this->assertContains('公司名称', $zhLabels);
        $this->assertContains('Company Name', $enLabels);
        $this->assertNotContains('Company Name', $zhLabels, '中文输出不得混入英文标签');
    }

    /**
     * FAC-007（**本轮 P1 不可妥协项**）  publicMap() 的语言确定性
     *
     * publicMap 用 pluck('value','key') 建索引。加入多语言行后，
     * 若不加 forLocale，同一 key 会有两条候选 → 取到任意一条。
     */
    public function test_fac_007_public_map_is_locale_deterministic(): void
    {
        $zh = Fact::publicMap('zh-CN');
        $en = Fact::publicMap('en');

        $this->assertSame('Demo Tenant A', $zh['FACT-CO-001'] ?? null, '中文 map 应为中文 value');
        $this->assertSame('Demo Tenant A Ltd.', $en['FACT-CO-001'] ?? null, '英文 map 应为英文 value');
        $this->assertSame('2017 年 3 月', $zh['FACT-CO-002'] ?? null);
        $this->assertSame('March 2017', $en['FACT-CO-002'] ?? null);

        // 两种语言的 map 不得相同（若相同说明取到同一语言）
        $this->assertNotSame($zh, $en, 'publicMap 必须按语言返回不同结果');
    }

    /**
     * FAC-008  禁止跨语言 fallback
     *
     * 英文站缺某事实的翻译时，en 的输出里**不能**出现中文那一条。
     * 「宁可缺字段，也不要英文 GEO 输出中文事实」是 20G-3 的硬约束。
     */
    public function test_fac_008_no_cross_locale_fallback(): void
    {
        // 删掉 FACT-CO-003 的英文行
        Fact::query()->where('key', 'FACT-CO-003')->where('locale', 'en')->delete();
        Fact::flushMemo();

        $enKeys = Fact::publicRows('en')->pluck('key')->all();

        $this->assertNotContains('FACT-CO-003', $enKeys,
            '英文缺少翻译时不得回退到中文行（GEO 场景下这是数据污染）');

        $zhKeys = Fact::publicRows('zh-CN')->pluck('key')->all();
        $this->assertContains('FACT-CO-003', $zhKeys, '中文行应仍存在');
    }

    /**
     * FAC-009  memo 必须按locale 分片
     *
     * 同一进程内先后读 zh / en，不得命中前一次语言的缓存。
     * 回归症状：先读 zh 再读 en，en 拿到中文 label。
     */
    public function test_fac_009_memo_is_partitioned_by_locale(): void
    {
        $first = Fact::publicRows('zh-CN')->pluck('value')->all();
        $second = Fact::publicRows('en')->pluck('value')->all();
        $third = Fact::publicRows('zh-CN')->pluck('value')->all();

        $this->assertSame($first, $third, '同语言重复读取应命中缓存（结果一致）');
        $this->assertNotSame($first, $second, 'memo 必须按 locale 分片，否则 en 会拿到 zh 的缓存');
    }

    /**
     * FAC-010  共享列由默认语言权威行同步；翻译列不跟随
     *
     * 改中文行的 is_public / sort → 英文行同步（口径一致）；
     * 改中文行的 label / value → 英文行**不**被覆盖（翻译内容独立）。
     */
    public function test_fac_010_shared_columns_sync_but_translated_ones_do_not(): void
    {
        $zh = Fact::query()->where('key', 'FACT-CO-001')->where('locale', 'zh-CN')->first();
        $en = Fact::query()->where('key', 'FACT-CO-001')->where('locale', 'en')->first();

        $zh->update(['sort' => 99, 'value' => '中文改过的值']);

        $en->refresh();

        $this->assertSame(99, $en->sort, '共享列 sort 应由默认语言权威行同步到 en');
        $this->assertSame('Demo Tenant A Ltd.', $en->value, '翻译列 value 不得被 zh 行覆盖');
    }

    /**
     * 语义引用跨语言稳定：Content.fact_refs / ContentGate 查的是 key，
     * 必须与 locale 无关 —— 否则英文内容引用的事实会「不存在」。
     */
    public function test_fac_011_semantic_key_lookup_is_locale_independent(): void
    {
        $known = Fact::query()->whereIn('key', ['FACT-CO-001', 'FACT-CO-003'])
            ->distinct()->pluck('key')->toArray();

        sort($known);
        $this->assertSame(['FACT-CO-001', 'FACT-CO-003'], $known,
            '按 key 的语义查询不应受 locale 影响（ContentGate 的 fact_refs 校验依赖它）');
    }

    /** @return array<int,string> 形如 'site_id,key,locale' */
    private function uniqueColumnSets(string $table): array
    {
        $pdo = \DB::connection()->getPdo();
        $out = [];
        foreach ($pdo->query('PRAGMA index_list('.$table.')') as $idx) {
            if ((int) $idx['unique'] !== 1) {
                continue;
            }
            $cols = [];
            foreach ($pdo->query('PRAGMA index_info('.$pdo->quote($idx['name']).')') as $c) {
                $cols[] = (string) $c['name'];
            }
            $out[] = implode(',', $cols);
        }

        return $out;
    }
}
