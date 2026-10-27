<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;

/**
 * GEO OS V1.4 — shared barrier for real multi-process concurrency tests.
 *
 * The barrier lives in the database, so every participant (the PHPUnit
 * controller and each independent worker process) coordinates through the very
 * same storage engine that the business logic uses. No shared memory, no
 * in-process sleeps-and-hope.
 *
 * Release semantics:
 *   1. every worker INSERTs/UPDATEs its slot as "ready"
 *   2. the controller waits until ALL slots are ready
 *   3. the controller publishes a single shared release timestamp
 *      (go_at, seconds since epoch with microsecond resolution)
 *   4. every worker busy-waits until that exact instant before touching
 *      business data
 *
 * Because go_at is a wall-clock timestamp, all workers start within a fraction
 * of a millisecond of each other — that is the measurable competition window.
 */
class ProcessBarrier
{
    public const TABLE = 'concurrency_barrier';

    public function __construct(private readonly string $table = self::TABLE)
    {
    }

    /**
     * (Re)create the barrier table and register the given number of slots.
     */
    public function reset(int $workers): void
    {
        DB::statement("DROP TABLE IF EXISTS {$this->table}");
        // DECIMAL(20,6) is accepted by MySQL, PostgreSQL and SQLite alike
        // (DOUBLE PRECISION / DOUBLE / REAL are not portable).
        DB::statement(
            "CREATE TABLE {$this->table} (
                slot INTEGER PRIMARY KEY,
                ready INTEGER NOT NULL DEFAULT 0,
                ready_at DECIMAL(20,6) NULL,
                go INTEGER NOT NULL DEFAULT 0,
                go_at DECIMAL(20,6) NULL
            )"
        );

        for ($slot = 1; $slot <= $workers; $slot++) {
            DB::insert("INSERT INTO {$this->table} (slot, ready, go) VALUES (?, 0, 0)", [$slot]);
        }
    }

    public function drop(): void
    {
        DB::statement("DROP TABLE IF EXISTS {$this->table}");
    }

    /**
     * Block until every participant reports ready.
     */
    public function waitUntilReady(int $expected, float $timeoutSeconds = 60.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $ready = (int) DB::table($this->table)->where('ready', 1)->count();
            if ($ready >= $expected) {
                return true;
            }
            usleep(1000);
        }

        return false;
    }

    /**
     * Publish the shared release instant.
     *
     * @return float the shared timestamp all workers will start at
     */
    public function release(float $leadSeconds = 0.3): float
    {
        $goAt = microtime(true) + $leadSeconds;

        DB::update("UPDATE {$this->table} SET go = 1, go_at = ?", [$goAt]);

        return $goAt;
    }

    /**
     * @return array<int, array<string, mixed>> per-slot readiness rows
     */
    public function slots(): array
    {
        return DB::table($this->table)->orderBy('slot')->get()->map(static function ($row) {
            return (array) $row;
        })->all();
    }
}
