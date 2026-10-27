<?php

namespace Tests\Feature\Concurrency;

use App\Models\Content;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Templates\TemplatePackageManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ProcessBarrier;
use Tests\Support\Concurrency\RealConcurrencyTestCase;
use Tests\Support\Concurrency\WorkerPool;

/**
 * GEO OS V1.4 · P0 real multi-process concurrency verification.
 * ------------------------------------------------------------------
 * These tests spawn REAL OS processes. Every worker opens its OWN database
 * connection and all workers are released onto the business data at the same
 * wall-clock instant by a database-backed barrier.
 *
 * A scenario may only be reported as "real concurrency verified" when
 *   1. every worker actually started (barrier released),
 *   2. the workers' [started_at, finished_at] intervals genuinely intersect
 *      (overlap_ms > 0) — this is asserted, not assumed,
 *   3. the final business state is asserted, not merely "no exception thrown".
 *
 * P0 scenarios:
 *   P0-1  Geoflow: concurrent create / update / duplicate delivery for the
 *         SAME external_id.
 *   P0-2  Template activation: two different template packs activated on the
 *         SAME site at the same time.
 *   P0-3  Business key uniqueness: concurrent creation of the same
 *         slug/locale business key.
 */
class RealConcurrencyP0Test extends RealConcurrencyTestCase
{
    private const WORKERS = 4;

    /**
     * Run the given scenario with N workers behind a shared barrier.
     *
     * @return array{parsed: array<int, array<string, mixed>>, overlap: array<string, mixed>}
     */
    private function runScenario(string $scenario, array $payload, int $workers): array
    {
        $barrier = new ProcessBarrier();
        $barrier->reset($workers);

        $pool = new WorkerPool(
            scenario: $scenario,
            payload: $payload,
            environment: $this->workerEnvironment(),
        );
        $pool->start($workers);

        $ready = $barrier->waitUntilReady($workers, 60.0);
        $this->assertTrue(
            $ready,
            "屏障未就绪：期望 {$workers} 个 worker 就绪，实际 "
            . DB::table(ProcessBarrier::TABLE)->where('ready', 1)->count()
        );

        $barrier->release(0.3);

        $raw = $pool->wait(120.0);
        $parsed = WorkerPool::parse($raw);
        $overlap = WorkerPool::overlap($parsed);

        $this->assertGreaterThan(
            0.0,
            $overlap['overlap_ms'],
            '未形成真实时间重叠（overlap_ms <= 0），不能算真实并发验证。'
            . ' worker 输出：' . json_encode($parsed, JSON_UNESCAPED_UNICODE)
        );

        $barrier->drop();

        return ['parsed' => $parsed, 'overlap' => $overlap];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function successes(array $parsed): array
    {
        return array_values(array_filter(
            $parsed,
            static fn (array $entry) => ($entry['parsed']['ok'] ?? false) === true
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function failures(array $parsed): array
    {
        return array_values(array_filter(
            $parsed,
            static fn (array $entry) => ($entry['parsed']['ok'] ?? false) !== true
        ));
    }

    private function defaultSiteId(): int
    {
        $site = Site::withoutGlobalScopes()->where('slug', Site::DEFAULT_SLUG)->first();
        $this->assertNotNull($site, '缺少默认站点 fixture');

        return (int) $site->id;
    }

    private function geoflowPayload(string $externalId, array $override = []): array
    {
        return array_merge([
            'external_id' => $externalId,
            'type' => 'article',
            'title' => '真实并发验证文章',
            'slug' => 'real-concurrency-article',
            'category_slug' => 'knowledge',
            'summary' => '摘要。',
            'body' => '正文。',
            'geo_conclusion' => '结论。',
            'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '设备A', 'value' => '配置1', 'source' => '台账'],
                ['label' => '设备B', 'value' => '配置2', 'source' => '实拍'],
            ],
            'owner' => 'GEOFlow',
            'reviewed_at' => '2026-10-27',
        ], $override);
    }

    private function enableGeoflowSync(): void
    {
        Setting::set('sync_geoflow_token', 'test-token-123456');
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');
    }

    /**
     * P0-1a — concurrent duplicate delivery of the SAME payload.
     *
     * Terminal state must be: exactly ONE content row for that external_id,
     * the delivery that created it reports create/update, the others report
     * skip, and NO worker may fail.
     */
    public function test_p0_1a_concurrent_identical_geoflow_delivery_creates_exactly_one_row(): void
    {
        $this->enableGeoflowSync();
        $siteId = $this->defaultSiteId();

        $outcome = $this->runScenario(
            'geoflow-upsert',
            ['site_id' => $siteId, 'content' => $this->geoflowPayload('REAL-CONC-001')],
            self::WORKERS
        );

        $parsed = $outcome['parsed'];
        $failures = $this->failures($parsed);

        $this->assertSame(
            [],
            array_map(static fn (array $f) => [
                'slot' => $f['slot'],
                'error' => $f['parsed']['exception'] ?? $f['parsed']['error'] ?? null,
                'message' => $f['parsed']['message'] ?? null,
            ], $failures),
            '并发重复投递不应出现失败 worker（竞态不得变成异常/500）'
        );

        $rows = Content::withoutGlobalScopes()->where('external_id', 'REAL-CONC-001')->get();
        $this->assertCount(
            1,
            $rows,
            '并发重复投递同一 external_id 必须只产生 1 行，实际 ' . $rows->count()
        );

        $actions = array_map(
            static fn (array $entry) => (string) ($entry['parsed']['action'] ?? 'none'),
            $this->successes($parsed)
        );
        $this->assertSame(
            self::WORKERS,
            count($actions),
            '每个 worker 都应返回契约内结果'
        );

        $writes = array_filter($actions, static fn (string $a) => in_array($a, ['create', 'update'], true));
        $skips = array_filter($actions, static fn (string $a) => $a === 'skip');

        $this->assertGreaterThanOrEqual(
            1,
            count($writes),
            '至少一个 worker 必须实际写入（create/update），实际 actions=' . json_encode($actions)
        );
        $this->assertSame(
            count($actions) - count($writes),
            count($skips),
            '未写入的 worker 必须走幂等 skip，actions=' . json_encode($actions)
        );
    }

    /**
     * P0-1b — concurrent delivery of DIFFERENT payloads under the SAME
     * external_id.
     *
     * This is the dangerous one: a unique-constraint conflict must not be
     * mistaken for an idempotent hit. Terminal state: exactly one row, its
     * content_hash equals one of the delivered payloads' hashes (i.e. the row
     * reflects a real delivery, not a blind skip), and no worker failed.
     */
    public function test_p0_1b_concurrent_different_geoflow_payloads_under_same_external_id_keep_one_consistent_row(): void
    {
        $this->enableGeoflowSync();
        $siteId = $this->defaultSiteId();

        $payloads = [];
        for ($i = 1; $i <= 2; $i++) {
            $payloads[] = [
                'site_id' => $siteId,
                'content' => $this->geoflowPayload('REAL-CONC-002', [
                    'title' => "并发变体 {$i}",
                    'slug' => "real-concurrency-variant-{$i}",
                    'body' => "正文 {$i}。",
                ]),
            ];
        }

        $barrier = new ProcessBarrier();
        $barrier->reset(count($payloads));

        $pools = [];
        foreach ($payloads as $index => $payload) {
            $pool = new WorkerPool(
                scenario: 'geoflow-upsert',
                payload: $payload,
                environment: $this->workerEnvironment(),
            );
            $pool->start(1, $index + 1);
            $pools[$index] = $pool;
        }

        $this->assertTrue(
            $barrier->waitUntilReady(count($payloads), 60.0),
            '屏障未就绪'
        );
        $barrier->release(0.3);

        $parsed = [];
        foreach ($pools as $index => $pool) {
            $raw = $pool->wait(120.0);
            $entry = array_values(WorkerPool::parse($raw))[0];
            $parsed[$index + 1] = $entry;
        }
        $overlap = WorkerPool::overlap($parsed);
        $this->assertGreaterThan(0.0, $overlap['overlap_ms'], '未形成真实时间重叠');
        $barrier->drop();

        $this->assertSame(
            [],
            array_map(static fn (array $f) => $f['parsed']['exception'] ?? $f['parsed']['error'] ?? null, $this->failures($parsed)),
            '并发不同载荷不得出现失败 worker'
        );

        $rows = Content::withoutGlobalScopes()->where('external_id', 'REAL-CONC-002')->get();
        $this->assertCount(1, $rows, '同一 external_id 并发写入必须只保留 1 行');

        $reportedHashes = array_values(array_filter(array_map(
            static fn (array $entry) => $entry['parsed']['content_hash'] ?? null,
            $this->successes($parsed)
        )));

        $this->assertNotEmpty($reportedHashes, '至少一个 worker 应报告写入结果');
        $this->assertContains(
            $rows->first()->content_hash,
            $reportedHashes,
            '最终行的指纹必须来自某个真实投递，不能是「冲突即盲跳」的产物'
        );
    }

    /**
     * P0-2 — two different template packs activated on the SAME site at the
     * same instant.
     *
     * Terminal state: the activation marker (settings) holds exactly ONE
     * active pack, and both workers completed inside the contract (no
     * unhandled exception, no half-applied state).
     */
    public function test_p0_2_concurrent_template_activation_leaves_exactly_one_active_pack(): void
    {
        $siteId = $this->defaultSiteId();

        $packs = ['saas-pro', 'commerce-pro'];

        $barrier = new ProcessBarrier();
        $barrier->reset(count($packs));

        $pools = [];
        foreach ($packs as $index => $pack) {
            $pool = new WorkerPool(
                scenario: 'template-activate',
                payload: ['site_id' => $siteId, 'pack' => $pack],
                environment: $this->workerEnvironment(),
            );
            $pool->start(1, $index + 1);
            $pools[$index + 1] = $pool;
        }

        $this->assertTrue($barrier->waitUntilReady(count($packs), 60.0), '屏障未就绪');
        $barrier->release(0.3);

        $parsed = [];
        foreach ($pools as $slot => $pool) {
            $raw = $pool->wait(120.0);
            $parsed[$slot] = array_values(WorkerPool::parse($raw))[0];
        }
        $overlap = WorkerPool::overlap($parsed);
        $this->assertGreaterThan(0.0, $overlap['overlap_ms'], '未形成真实时间重叠');
        $barrier->drop();

        $this->assertSame(
            [],
            array_map(static fn (array $f) => $f['parsed']['exception'] ?? $f['parsed']['error'] ?? null, $this->failures($parsed)),
            '并发模板激活不得出现失败 worker：'
            . json_encode($this->failures($parsed), JSON_UNESCAPED_UNICODE)
        );

        $markers = Setting::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('key', TemplatePackageManager::SETTINGS_KEY)
            ->get();

        $this->assertCount(
            1,
            $markers,
            '激活标记必须只有 1 行（站点行锁 + (site_id,key) 唯一约束）'
        );

        $this->assertContains(
            (string) $markers->first()->value,
            $packs,
            '最终激活包必须是本次并发激活的两个包之一'
        );
    }

    /**
     * P0-3a — concurrent creation of the SAME slug/locale business key.
     *
     * Terminal state: exactly one row, and the loser must be rejected by the
     * unique constraint (integrity violation), not by an application-level
     * crash.
     */
    public function test_p0_3a_concurrent_duplicate_slug_and_locale_yields_exactly_one_row(): void
    {
        $siteId = $this->defaultSiteId();

        $outcome = $this->runScenario(
            'contents-insert',
            [
                'site_id' => $siteId,
                'slug' => 'real-concurrency-duplicate',
                'locale' => 'zh-CN',
                'title' => '重复业务键',
            ],
            2
        );

        $parsed = $outcome['parsed'];
        $successes = $this->successes($parsed);
        $failures = $this->failures($parsed);

        $this->assertCount(1, $successes, '同 slug/locale 并发插入应恰好 1 个成功');
        $this->assertCount(1, $failures, '同 slug/locale 并发插入应恰好 1 个被拒');

        $sqlState = (string) ($failures[0]['parsed']['sqlstate'] ?? '');
        $this->assertStringStartsWith(
            '23',
            $sqlState,
            '被拒方必须是完整性约束冲突（SQLSTATE class 23），实际=' . $sqlState
            . ' message=' . ($failures[0]['parsed']['message'] ?? '')
        );

        $rows = DB::table('contents')
            ->where('site_id', $siteId)
            ->where('slug', 'real-concurrency-duplicate')
            ->where('locale', 'zh-CN')
            ->count();

        $this->assertSame(1, (int) $rows, '并发插入重复业务键后必须只有 1 行');
    }

    /**
     * P0-3b — the same slug in DIFFERENT locales is a different business key
     * and must be allowed concurrently (bilingual content).
     */
    public function test_p0_3b_concurrent_same_slug_in_different_locales_is_allowed(): void
    {
        $siteId = $this->defaultSiteId();

        $barrier = new ProcessBarrier();
        $barrier->reset(2);

        $pools = [];
        foreach (['zh-CN', 'en'] as $index => $locale) {
            $pool = new WorkerPool(
                scenario: 'contents-insert',
                payload: [
                    'site_id' => $siteId,
                    'slug' => 'real-concurrency-bilingual',
                    'locale' => $locale,
                    'title' => "双语内容 {$locale}",
                ],
                environment: $this->workerEnvironment(),
            );
            $pool->start(1, $index + 1);
            $pools[$index + 1] = $pool;
        }

        $this->assertTrue($barrier->waitUntilReady(2, 60.0), '屏障未就绪');
        $barrier->release(0.3);

        $parsed = [];
        foreach ($pools as $slot => $pool) {
            $raw = $pool->wait(120.0);
            $parsed[$slot] = array_values(WorkerPool::parse($raw))[0];
        }
        $overlap = WorkerPool::overlap($parsed);
        $this->assertGreaterThan(0.0, $overlap['overlap_ms'], '未形成真实时间重叠');
        $barrier->drop();

        $this->assertCount(
            2,
            $this->successes($parsed),
            '同 slug 不同 locale 必须都成功：'
            . json_encode($this->failures($parsed), JSON_UNESCAPED_UNICODE)
        );

        $rows = (int) DB::table('contents')
            ->where('site_id', $siteId)
            ->where('slug', 'real-concurrency-bilingual')
            ->count();

        $this->assertSame(2, $rows, '双语同 slug 应产生 2 行');
    }
}
