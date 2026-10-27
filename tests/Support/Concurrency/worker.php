<?php

/**
 * GEO OS V1.4 — real multi-process concurrency worker.
 *
 * Runs as an INDEPENDENT OS process with its OWN database connection, so that
 * row-level locking (SELECT ... FOR UPDATE), transaction isolation and unique
 * constraint arbitration behave exactly as they do in production. A single
 * PHPUnit process cannot reproduce any of that: SQLite has no row locks, and a
 * single connection serialises everything.
 *
 * Protocol (see Tests\Support\Concurrency\ProcessBarrier):
 *   1. connect to the shared database
 *   2. mark this slot as "ready" in the barrier table
 *   3. wait until the controller releases the barrier
 *   4. wait until the shared release timestamp (microtime) — this is the
 *      synchronised start instant, so all workers really do overlap
 *   5. run the scenario
 *   6. print one JSON object on stdout
 *
 * Usage:
 *   php tests/Support/Concurrency/worker.php <scenario> <slot> <payload-b64> [barrier-table]
 *
 * Database connection settings are taken from the environment (DB_CONNECTION,
 * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD) so the worker always
 * talks to the very same database as the controller process.
 */

use App\Models\Site;
use App\Services\Sync\GeoflowSync;
use App\Support\SiteContext;
use App\Support\Templates\TemplateActivationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scenario = $argv[1] ?? '';
$slot = (int) ($argv[2] ?? 0);
$payload = json_decode(base64_decode($argv[3] ?? ''), true) ?: [];
$barrierTable = $argv[4] ?? 'concurrency_barrier';

$emit = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
};

try {
    // ── 1. register as ready ──────────────────────────────────────────────
    DB::statement("UPDATE {$barrierTable} SET ready = 1, ready_at = ? WHERE slot = ?", [
        microtime(true),
        $slot,
    ]);

    // ── 2. wait for the controller to release the barrier ──────────────────
    $deadline = microtime(true) + 60.0;
    $goAt = null;
    while (true) {
        $row = DB::selectOne("SELECT go, go_at FROM {$barrierTable} WHERE slot = ?", [$slot]);
        if ($row !== null && (int) $row->go === 1) {
            $goAt = (float) $row->go_at;
            break;
        }
        if (microtime(true) > $deadline) {
            $emit(['slot' => $slot, 'ok' => false, 'phase' => 'barrier', 'error' => 'barrier timeout']);
            exit(3);
        }
        usleep(500);
    }

    // ── 3. spin until the shared release instant ───────────────────────────
    // Busy-wait (not sleep) so every worker starts within a fraction of a
    // millisecond of the same instant; this is what makes the operations
    // genuinely overlap instead of merely starting "close together".
    while (microtime(true) < $goAt) {
        usleep(100);
    }
    $startedAt = microtime(true);

    // ── 4. run the scenario ────────────────────────────────────────────────
    $siteId = (int) ($payload['site_id'] ?? 0);
    $result = ['slot' => $slot, 'scenario' => $scenario, 'started_at' => $startedAt];

    if ($scenario === 'geoflow-upsert') {
        $site = Site::withoutGlobalScopes()->find($siteId);
        if ($site === null) {
            $result += ['ok' => false, 'error' => 'site not found'];
        } else {
            $outcome = SiteContext::withSite($site, static function () use ($payload) {
                try {
                    /** @var GeoflowSync $sync */
                    $sync = app(GeoflowSync::class);
                    $response = $sync->upsert($payload['content'] ?? []);

                    return [
                        'ok' => true,
                        'action' => $response['action'] ?? null,
                        'status' => $response['status'] ?? null,
                        'code' => $response['code'] ?? null,
                        'message' => $response['message'] ?? null,
                        'content_id' => isset($response['content']) ? $response['content']->id : null,
                        'content_hash' => isset($response['content']) ? $response['content']->content_hash : null,
                        'changed_fields' => $response['changed_fields'] ?? [],
                    ];
                } catch (\Throwable $e) {
                    return [
                        'ok' => false,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ];
                }
            });
            $result += $outcome;
        }
    } elseif ($scenario === 'template-activate') {
        $site = Site::withoutGlobalScopes()->find($siteId);
        if ($site === null) {
            $result += ['ok' => false, 'error' => 'site not found'];
        } else {
            try {
                $response = TemplateActivationService::activate($site, (string) ($payload['pack'] ?? ''));
                $result += [
                    'ok' => true,
                    'pack' => $payload['pack'] ?? null,
                    'recipes' => array_keys($response['recipes'] ?? []),
                    'cache' => $response['cache'] ?? null,
                    'invalidation' => $response['invalidation'] ?? null,
                ];
            } catch (\Throwable $e) {
                $result += [
                    'ok' => false,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ];
            }
        }
    } elseif ($scenario === 'contents-insert') {
        // Raw insert on purpose: this scenario asserts what the DATABASE does
        // when two connections race on the same business key, independent of
        // any application-level retry logic.
        $now = date('Y-m-d H:i:s');
        try {
            $id = DB::table('contents')->insertGetId([
                'site_id' => $siteId,
                'type' => 'article',
                'title' => (string) ($payload['title'] ?? '并发内容'),
                'slug' => (string) ($payload['slug'] ?? ''),
                'locale' => (string) ($payload['locale'] ?? 'zh-CN'),
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result += ['ok' => true, 'inserted_id' => $id];
        } catch (\Throwable $e) {
            $sqlState = $e instanceof \Illuminate\Database\QueryException ? $e->getCode() : null;
            $result += [
                'ok' => false,
                'exception' => get_class($e),
                'sqlstate' => $sqlState,
                'message' => $e->getMessage(),
            ];
        }
    } else {
        $result += ['ok' => false, 'error' => 'unknown scenario: ' . $scenario];
    }

    $result['finished_at'] = microtime(true);
    $emit($result);
    exit(0);
} catch (\Throwable $e) {
    $emit([
        'slot' => $slot,
        'scenario' => $scenario,
        'ok' => false,
        'fatal' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    exit(4);
}
