<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Setting;
use App\Services\Sync\GeoflowSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-HASH · GEOFlow 幂等指纹契约（20G-2 · C-4）
 * ------------------------------------------------------------------
 * 覆盖 8 组契约中的 Hash 组。核心不变量：
 *
 *   I1  指纹必须作用在「最终将要持久化的状态」上。
 *       历史缺陷：hash 在 auto_publish 补 published_at 之前计算，
 *       导致同一 payload 第二次推送 hash 必然不同 → 误判 changed
 *       → 每次推送都写 revision + synclog，同步噪音永不收敛。
 *   I2  指纹覆盖范围由 ContentFieldContract::hashableFields() 决定，
 *       改任一受管字段必须导致 hash 变化。
 *   I3  系统生成字段（synced_at / status / external_source 等）
 *       不得进入指纹，否则每次同步都判变化。
 *   I4  HASH_SCHEMA_VERSION 只参与计算，不得触发存量重算。
 *
 * @see \App\Support\ContentFieldContract
 */
class GeoflowHashTest extends TestCase
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
            'external_id' => 'HASH-001',
            'type' => 'article',
            'title' => '哈希幂等验证文章',
            'slug' => 'hash-idempotency-article',
            'category_slug' => 'knowledge',
            'summary' => '摘要内容。',
            'body' => '正文内容。',
            'geo_conclusion' => '结论。',
            'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '高速分散机', 'value' => '多台配置', 'source' => '设备台账'],
                ['label' => '恒温固化炉', 'value' => '温控分区', 'source' => '车间实拍'],
            ],
            'owner' => 'GEOFlow',
            'reviewed_at' => '2026-09-14',
        ], $override);
    }

    protected function push(array $override = []): array
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload($override))
            ->assertOk()
            ->json();
    }

    // ─────────────────────────────────────────────────────────
    // I1 · 指纹作用在最终持久化状态上（评审第 1 条）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-HASH-001 同一 payload 连续推送 3 次，第 2/3 次必须恒为 skip。
     *
     * 这条直接锁住 published_at 缺陷：payload 不含 published_at，
     * 首次由 auto_publish 补 now()。若 hash 早于该默认值计算，
     * 第二次的 hash 输入就与库中实际值不同 → 必然误判 changed。
     */
    public function test_gf_hash_001_repeated_identical_push_is_always_skip(): void
    {
        $first = $this->push();
        $c1 = Content::where('external_id', 'HASH-001')->firstOrFail();
        $hash1 = $c1->content_hash;
        $pa1 = (string) $c1->published_at;

        $this->assertSame('create', $first['action']);
        $this->assertNotNull($pa1, 'auto_publish=1 时首次推送应落定published_at');

        $second = $this->push();
        $c2 = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertSame('skip', $second['action'], '第二次推送相同 payload 必须 skip');
        $this->assertSame($hash1, $c2->content_hash, 'content_hash 必须稳定');
        $this->assertSame($pa1, (string) $c2->published_at, 'published_at 不得被二次覆盖');

        $third = $this->push();
        $c3 = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertSame('skip', $third['action'], '第三次推送仍必须 skip');
        $this->assertSame($hash1, $c3->content_hash);

        $this->assertSame(1, Content::where('external_id', 'HASH-001')->count(), '不得产生重复行');
    }

    /**
     * GF-HASH-002 部分推送（只传 title）不得改变指纹。
     * 省略字段 = 不修改，不是「置空」。
     */
    public function test_gf_hash_002_partial_push_does_not_change_hash(): void
    {
        $this->push();
        $before = Content::where('external_id', 'HASH-001')->firstOrFail()->content_hash;

        $result = $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', [
                'external_id' => 'HASH-001',
                'title' => '哈希幂等验证文章',
            ])
            ->assertOk()
            ->json();

        $after = Content::where('external_id', 'HASH-001')->firstOrFail()->content_hash;

        $this->assertSame('skip', $result['action'], '部分推送且有效值未变应 skip');
        $this->assertSame($before, $after);
    }

    /**
     * GF-HASH-003 显式 null 清空字段 → hash 必须变化。
     * 这是 C-5 的核心断言：null ≠ missing。
     *
     * 用 summary 而非 GEO 四要素做清空对象——清空 geo_boundary 会触发
     * 门禁「证据不足」被拒，测的就不是「null 语义」而是「门禁」了。
     */
    public function test_gf_hash_003_explicit_null_changes_hash(): void
    {
        $this->push();
        $before = Content::where('external_id', 'HASH-001')->firstOrFail()->content_hash;

        $result = $this->push(['summary' => null]);

        $after = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertSame('update', $result['action'], '显式清空字段应判为更新');
        $this->assertNotSame($before, $after->content_hash, '清空字段必须改变 hash');
        $this->assertNull($after->summary, 'summary 应已被清空');
    }

    // ─────────────────────────────────────────────────────────
    // I2 · 指纹覆盖范围由契约决定（数据驱动，不硬编码字段名）
    // ─────────────────────────────────────────────────────────

    /**
     * GF-HASH-004 改动任一 hashable 字段 → hash 必须变化。
     *
     * 用 ContentFieldContract::hashableFields() 驱动，新增字段时
     * 自动纳入断言——这正是「消灭 Contract Drift」的实践形式：
     * 契约是唯一事实源，测试从契约派生而非另列一份清单。
     */
    public function test_gf_hash_004_every_hashable_field_affects_hash(): void
    {
        $this->push();
        $content = Content::where('external_id', 'HASH-001')->firstOrFail();
        $baseHash = $content->content_hash;

        $mutations = [
            'type' => 'page',
            'title' => '另一个标题',
            'slug' => 'another-slug',
            'summary' => '另一个摘要',
            'body' => '另一个正文',
            'category_id' => 9999,      // 结构归属：换栏目必须改 hash
            'group_id' => 8888,
            'geo_conclusion' => '另一个结论',
            'geo_explanation' => '另一个解释',
            'geo_evidence' => [['label' => 'X', 'value' => 'Y']],
            'geo_boundary' => '另一个边界',
            'geo_faq' => [['q' => '问题', 'a' => '答案']],
            'geo_key_facts' => ['事实一'],
            'owner' => '别人',
            'source_note' => '另一个来源',
            'reviewed_at' => '2026-10-01',
            'review_due' => '2027-01-01',
            'published_at' => '2026-10-02 10:00:00',
            // C-20 · RC-5：locale 进入契约（hashable）。
            // 语义依据：DB 约束是 UNIQUE(site_id, external_id) —— external_id
            // 是内容身份键且**不含 locale**，故一个 external_id 只对应一行内容，
            // locale 是该行的属性而非身份维度 → 换语言就是内容变更 → 必须进 hash。
            'locale' => 'en',
        ];

        foreach (\App\Support\ContentFieldContract::hashableFields() as $field) {
            if (! array_key_exists($field, $mutations)) {
                //契约声明 hashable 但没有对应突变样本 → 说明契约与测试脱节
                $this->fail("契约字段 {$field} 缺少 hash 突变样本，请补测试或调整契约");
            }

            $mutated = clone $content;
            $mutated->fill([$field => $mutations[$field]]);

            $this->assertNotSame(
                $baseHash,
                $mutated->computeHash(),
                "GF-HASH-004 失败：修改受管字段 {$field} 未改变 hash"
            );
        }
    }

    /**
     * GF-HASH-005 category_slug 变化 → category_id 变化 → hash 必须变化。
     * 引用型字段经解析后进hash（category_id 是 hashable 的）。
     */
    public function test_gf_hash_005_category_change_affects_hash(): void
    {
        $this->push();
        $before = Content::where('external_id', 'HASH-001')->firstOrFail()->content_hash;

        $other = \App\Models\Category::where('slug', '!=', 'knowledge')->first();
        $this->assertNotNull($other, '测试库需至少两个栏目才能验证变更');

        $this->push(['category_slug' => $other->slug]);

        $after = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertNotSame($before, $after->content_hash, '换栏目必须改变 hash');
        $this->assertSame($other->id, $after->category_id);
    }

    // ─────────────────────────────────────────────────────────
    // I3 · 系统生成字段不得进入指纹
    // ─────────────────────────────────────────────────────────

    /**
     * GF-HASH-006 系统生成字段变化不得影响 hash。
     * 若synced_at 进了 hash，每次同步都会判 changed，幂等完全失效。
     */
    public function test_gf_hash_006_system_generated_fields_do_not_affect_hash(): void
    {
        $this->push();
        $content = Content::where('external_id', 'HASH-001')->firstOrFail();
        $baseHash = $content->content_hash;

        $mutated = clone $content;
        $mutated->synced_at = now()->addDays(5);
        $mutated->external_source = 'other';
        $mutated->status = 'draft';

        $this->assertSame(
            $baseHash,
            $mutated->computeHash(),
            'GF-HASH-006 失败：系统生成字段（synced_at/external_source/status）不应影响 hash'
        );
    }

    /**
     * GF-HASH-007 契约自检通过：hashable ⊆ syncable，且 state 字段进快照。
     */
    public function test_gf_hash_007_field_contract_self_audit_passes(): void
    {
        $problems = \App\Support\ContentFieldContract::audit();

        $this->assertSame([], $problems, '字段契约自检失败：'.implode('; ', $problems));
    }

    /**
     * GF-HASH-008 status / lock_manual 属于状态字段：进快照但不进 hash。
     * unpublish 能改status 却不该让「内容指纹」变化——两者语义不同。
     */
    public function test_gf_hash_008_state_fields_are_revisionable_but_not_hashable(): void
    {
        $hashable = \App\Support\ContentFieldContract::hashableFields();
        $revisionable = \App\Support\ContentFieldContract::revisionableFields();

        foreach (['status', 'lock_manual'] as $stateField) {
            $this->assertNotContains($stateField, $hashable, "{$stateField} 不应进 hash");
            $this->assertContains($stateField, $revisionable, "{$stateField} 必须进 Revision 快照");
        }
    }

    /**
     * GF-HASH-009 HASH_SCHEMA_VERSION 存在且为 int。
     * 版本只参与计算，不触发存量重算（那是迁移里禁止的行为）。
     */
    public function test_gf_hash_009_hash_schema_version_is_declared(): void
    {
        $this->assertIsInt(Content::HASH_SCHEMA_VERSION);
        $this->assertGreaterThanOrEqual(2, Content::HASH_SCHEMA_VERSION, 'v2 起含受管字段投影');
    }

    // ─────────────────────────────────────────────────────────
    // Service 级：resolveFinalPersistedState 的四态规则
    // ─────────────────────────────────────────────────────────

    /**
     * GF-HASH-010 published_at 四态：省略且已有值 → 保留原值。
     */
    public function test_gf_hash_010_missing_published_at_preserves_existing(): void
    {
        $this->push(['published_at' => '2026-09-01 08:00:00']);
        $original = Content::where('external_id', 'HASH-001')->firstOrFail()->published_at;

        // 第二次不传 published_at
        $this->push(['external_id' => 'HASH-001', 'title' => '哈希幂等验证文章']);

        $after = Content::where('external_id', 'HASH-001')->firstOrFail()->published_at;

        $this->assertSame(
            (string) $original,
            (string) $after,
            'GF-HASH-010 失败：省略 published_at 时必须保留原值，不得被重置'
        );
    }

    /**
     * GF-HASH-012 · C-20 · RC-5
     *
     * payload **不传** locale 时，连续推送必须恒为 skip。
     *
     * 根因背景：`contents.locale` 是 **DB 默认值列**（DEFAULT 'zh-CN'）。
     * 修复前 locale 进入 hash 覆盖集，而 payload 不传它：
     *   首次推送 → 新建内存模型无该属性 → hash 侧读到 NULL
     *   二次推送 → 从库读回'zh-CN'        → hash 侧读到 'zh-CN'
     *   ⇒ hash 不稳定 ⇒ 幂等 skip 退化为 changed（与 20G-2 修掉的
     *     published_at 缺陷**完全同型**）。
     *
     * 本断言锁死修复：`resolveFinalPersistedState()` 必须在算 hash 前
     * 把 locale 解析到最终态。
     */
    public function test_gf_hash_012_missing_locale_keeps_hash_stable(): void
    {
        $first = $this->push();
        $c1 = Content::where('external_id', 'HASH-001')->firstOrFail();
        $hash1 = $c1->content_hash;

        // payload 明确不含 locale（push() 的默认 payload 就没有 locale）
        $second = $this->push();
        $c2 = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertSame('create', $first['action']);
        $this->assertSame('skip', $second['action'],
            'GF-HASH-012 失败：payload 不含 locale 时，二次推送必须仍为 skip');
        $this->assertSame($hash1, $c2->content_hash,
            'GF-HASH-012 失败：省略 locale 时 content_hash 必须稳定'
            . '（DB 默认值列参与 hash 的幂等陷阱）');
    }

    /**
     * GF-HASH-013 · C-20 · RC-5
     *
     * 已显式指定 locale 的内容，后续**省略** locale 不得被改回默认语言。
     * 否则「上游漏传一个字段」就会把已发布的英文内容静默改成中文。
     */
    public function test_gf_hash_013_missing_locale_preserves_existing(): void
    {
        $this->push(['locale' => 'en']);
        $original = Content::where('external_id', 'HASH-001')->firstOrFail()->locale;
        $this->assertSame('en', $original, '前置：首次推送应落定 locale=en');

        // 第二次不传 locale
        $this->push(['external_id' => 'HASH-001', 'title' => '哈希幂等验证文章']);

        $after = Content::where('external_id', 'HASH-001')->firstOrFail()->locale;

        $this->assertSame('en', $after,
            'GF-HASH-013 失败：省略 locale 时必须保留原值，'
            . '不得被静默改回站点默认语言');
    }

    /**
     * GF-HASH-011 unpublish 改status 后，内容指纹不应变化。
     * status 是状态字段不是内容事实。
     */
    public function test_gf_hash_011_unpublish_does_not_change_content_hash(): void
    {
        $this->push();
        $before = Content::where('external_id', 'HASH-001')->firstOrFail()->content_hash;

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/unpublish', ['external_id' => 'HASH-001'])
            ->assertOk();

        $after = Content::where('external_id', 'HASH-001')->firstOrFail();

        $this->assertSame('archived', $after->status, 'unpublish 应归档');
        $this->assertSame(
            $before,
            $after->content_hash,
            'GF-HASH-011 失败：下架只改状态，不应改变内容指纹'
        );
    }
}
