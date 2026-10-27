<?php

namespace Tests\Support\Concurrency;

use RuntimeException;

/**
 * GEO OS V1.4 — launches and collects independent worker processes.
 *
 * Each worker is a separate OS process with its own PHP runtime and its own
 * database connection. That is the whole point: pessimistic row locks
 * (SELECT ... FOR UPDATE), transaction isolation levels and unique-constraint
 * arbitration only exist between *connections*, never inside one.
 *
 * On Windows, proc_open() REPLACES the child environment when an env array is
 * supplied, so the full parent environment is forwarded and the database
 * variables are added on top of it.
 */
class WorkerPool
{
    /** @var array<int, array{process: resource, pipes: array<int, resource>}> */
    private array $running = [];

    /**
     * @param  array<string, string>  $environment  extra environment for the child (e.g. DB_*)
     */
    public function __construct(
        private readonly string $scenario,
        private readonly array $payload,
        private readonly array $environment = [],
        private readonly string $barrierTable = ProcessBarrier::TABLE,
        private readonly ?string $projectRoot = null,
    ) {
    }

    /**
     * Start worker processes.
     *
     * @param  int  $workers    how many processes to launch
     * @param  int  $firstSlot  barrier slot of the first process; a pool may be
     *                          started several times with different slots so
     *                          that each process gets a distinct barrier row
     */
    public function start(int $workers, int $firstSlot = 1): void
    {
        $root = $this->projectRoot ?? dirname(__DIR__, 3);
        $workerScript = $root . '/tests/Support/Concurrency/worker.php';
        $payload = base64_encode((string) json_encode($this->payload));
        $childEnv = array_merge($this->parentEnvironment(), $this->environment);

        for ($offset = 0; $offset < $workers; $offset++) {
            $slot = $firstSlot + $offset;

            $command = implode(' ', [
                escapeshellarg(PHP_BINARY),
                escapeshellarg($workerScript),
                escapeshellarg($this->scenario),
                (string) $slot,
                escapeshellarg($payload),
                escapeshellarg($this->barrierTable),
            ]);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptors, $pipes, $root, $childEnv);
            if (! is_resource($process)) {
                throw new RuntimeException("Unable to start worker process for slot {$slot}");
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $this->running[$slot] = ['process' => $process, 'pipes' => $pipes];
        }
    }

    /**
     * Wait for every worker to exit and collect their JSON results.
     *
     * @return array<int, array<string, mixed>>
     */
    public function wait(float $timeoutSeconds = 120.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = [];
        $stderr = [];
        $open = $this->running;

        while ($open !== []) {
            foreach ($open as $slot => $handle) {
                $stdout[$slot] = ($stdout[$slot] ?? '') . (string) stream_get_contents($handle['pipes'][1]);
                $stderr[$slot] = ($stderr[$slot] ?? '') . (string) stream_get_contents($handle['pipes'][2]);

                $status = proc_get_status($handle['process']);
                if ($status['running'] === false) {
                    // Drain whatever is left in the pipes after exit.
                    $stdout[$slot] .= (string) stream_get_contents($handle['pipes'][1]);
                    $stderr[$slot] .= (string) stream_get_contents($handle['pipes'][2]);
                    fclose($handle['pipes'][1]);
                    fclose($handle['pipes'][2]);
                    proc_close($handle['process']);
                    unset($open[$slot]);
                }
            }

            if ($open !== [] && microtime(true) > $deadline) {
                foreach ($open as $slot => $handle) {
                    $this->kill($handle);
                    unset($open[$slot]);
                }
                throw new RuntimeException('Worker processes did not finish within the timeout');
            }

            if ($open !== []) {
                usleep(2000);
            }
        }

        $results = [];
        foreach ($this->running as $slot => $handle) {
            $results[$slot] = [
                'slot' => $slot,
                'stdout' => $stdout[$slot] ?? '',
                'stderr' => $stderr[$slot] ?? '',
            ];
        }
        $this->running = [];

        return $results;
    }

    /**
     * Parse the JSON payload each worker printed.
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array<int, array<string, mixed>>
     */
    public static function parse(array $raw): array
    {
        $parsed = [];
        foreach ($raw as $slot => $entry) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", (string) $entry['stdout'])),
                static fn ($line) => $line !== ''
            ));
            $decoded = null;
            foreach ($lines as $line) {
                $candidate = json_decode($line, true);
                if (is_array($candidate)) {
                    $decoded = $candidate;
                }
            }
            $parsed[$slot] = [
                'slot' => $slot,
                'parsed' => $decoded,
                'stdout' => $entry['stdout'],
                'stderr' => $entry['stderr'],
            ];
        }

        return $parsed;
    }

    /**
     * The competition window actually achieved.
     *
     * "starts spread"  = how close the workers' start instants were to each
     *                    other (small => they really started together).
     * "overlap_ms"     = the length of the intersection of all workers'
     *                    [started_at, finished_at] intervals. This is the
     *                    hard evidence that the operations genuinely
     *                    overlapped in time, not just that they were started
     *                    close together. It MUST be > 0 for a scenario to be
     *                    reported as real concurrency.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array{starts: array<int, float>, finishes: array<int, float>, spread_ms: float, overlap_ms: float}
     */
    public static function overlap(array $parsed): array
    {
        $starts = [];
        $finishes = [];

        foreach ($parsed as $entry) {
            $data = $entry['parsed'] ?? null;
            if (! is_array($data)) {
                continue;
            }
            if (isset($data['started_at'])) {
                $starts[(int) $entry['slot']] = (float) $data['started_at'];
            }
            if (isset($data['finished_at'])) {
                $finishes[(int) $entry['slot']] = (float) $data['finished_at'];
            }
        }

        if ($starts === []) {
            return ['starts' => [], 'finishes' => [], 'spread_ms' => -1.0, 'overlap_ms' => -1.0];
        }

        $startValues = array_values($starts);
        $spread = (max($startValues) - min($startValues)) * 1000;

        // Intersection of every worker's [start, finish] interval.
        $overlapMs = -1.0;
        $completed = array_intersect_key($starts, $finishes);
        if (count($completed) === count($starts) && $starts !== []) {
            $latestStart = max($startValues);
            $earliestFinish = min(array_map(
                static fn (int $slot) => $finishes[$slot],
                array_keys($completed)
            ));
            $overlapMs = max(0.0, ($earliestFinish - $latestStart) * 1000);
        }

        return [
            'starts' => $starts,
            'finishes' => $finishes,
            'spread_ms' => round($spread, 3),
            'overlap_ms' => round($overlapMs, 3),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parentEnvironment(): array
    {
        $env = getenv();
        $normalised = [];
        foreach (is_array($env) ? $env : [] as $key => $value) {
            $normalised[(string) $key] = (string) $value;
        }

        return $normalised;
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>}  $handle
     */
    private function kill(array $handle): void
    {
        $status = proc_get_status($handle['process']);
        if ($status['running'] === true) {
            proc_terminate($handle['process'], 9);
        }
        foreach ($handle['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($handle['process']);
    }
}
