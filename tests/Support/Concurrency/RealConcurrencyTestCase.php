<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GEO OS V1.4 — base class for REAL multi-process concurrency tests.
 *
 * Why this exists and how it differs from the existing "concurrency" tests
 * (tests/Feature/GeoflowConcurrencyTest.php,
 *  tests/Feature/TemplateActivationConcurrencyW3NVTest.php):
 *
 *   Those files run everything inside ONE PHPUnit process and ONE database
 *   connection. They assert the *contract* (unique constraint exists, duplicate
 *   is rejected, no HTTP 500) but they CANNOT and DO NOT reproduce a race:
 *   the two operations are executed one after the other, never overlapping.
 *   Their own file headers say so explicitly.
 *
 *   This base class spawns real OS processes. Each one opens its OWN database
 *   connection and the barrier guarantees they all touch the business data at
 *   the same instant. Only then can we assert something about the final
 *   business state under genuine contention.
 *
 * Database selection:
 *   - sqlite : the default test config uses ":memory:", which physically
 *              cannot be shared between processes. This base class therefore
 *              switches SQLite to a per-process FILE database (WAL mode) so
 *              the same test still exercises real process-level concurrency
 *              on SQLite. Note that SQLite has no row-level locks, so
 *              pessimistic locking semantics can only be asserted on
 *              MySQL / PostgreSQL.
 *   - mysql / pgsql : the configured connection is used as-is.
 */
abstract class RealConcurrencyTestCase extends TestCase
{
    /**
     * The schema is migrated once per database per PHP process; later tests
     * truncate the business tables instead of re-running all migrations.
     *
     * The key identifies WHICH database the schema belongs to, so that a
     * change of connection (e.g. the SQLite file being recreated) forces a
     * fresh migration instead of reusing a schema that no longer exists.
     */
    protected static ?string $preparedKey = null;

    /** The SQLite file shared by all tests of this class. */
    protected static ?string $sharedSqliteFile = null;

    protected ?string $sqliteFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSharedDatabase();
        $this->prepareSchema();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        // The SQLite file is shared by every test of this class and must
        // survive until the class is finished -- otherwise the next test would
        // find a schema that no longer exists.
        if ($this->sqliteFile !== null) {
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            $this->sqliteFile = null;
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$sharedSqliteFile !== null) {
            foreach ([
                static::$sharedSqliteFile,
                static::$sharedSqliteFile . '-wal',
                static::$sharedSqliteFile . '-shm',
            ] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            static::$sharedSqliteFile = null;
            static::$preparedKey = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Make sure the current connection can be shared with child processes.
     */
    protected function configureSharedDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $path = storage_path('framework/testing/concurrency-' . getmypid() . '.sqlite');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        // The file is shared by every test of this class: it must survive
        // between test methods, otherwise the schema (and its tables) would
        // disappear while the prepared-key still says "already migrated".
        if (! is_file($path)) {
            touch($path);
        }

        // SQLite needs three adjustments before several processes can write
        // concurrently:
        //   IMMEDIATE   - a DEFERRED transaction that reads first and writes
        //                 later does NOT wait on busy_timeout when it upgrades
        //                 to a write lock; it fails instantly with
        //                 SQLITE_BUSY ("database is locked").
        //   WAL         - readers do not block the writer.
        //   busy_timeout- the loser of a write race waits instead of failing.
        putenv('DB_TRANSACTION_MODE=IMMEDIATE');
        putenv('DB_BUSY_TIMEOUT=15000');
        putenv('DB_JOURNAL_MODE=WAL');
        putenv('DB_SYNCHRONOUS=NORMAL');

        config([
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.busy_timeout' => 15000,
            'database.connections.sqlite.synchronous' => 'NORMAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);

        DB::purge('sqlite');
        $this->sqliteFile = $path;
        static::$sharedSqliteFile = $path;
    }

    /**
     * Identifies the database the current schema belongs to.
     */
    protected function schemaKey(): string
    {
        $driver = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$driver}");

        return $driver . '|' . (string) ($connection['database'] ?? '');
    }

    protected function prepareSchema(): void
    {
        $key = $this->schemaKey();

        if (static::$preparedKey !== $key) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            static::$preparedKey = $key;

            return;
        }

        $this->truncateBusinessTables();
    }

    /**
     * Empty every table except the migration ledger, keeping auto-increment
     * counters aligned with a freshly migrated database.
     */
    protected function truncateBusinessTables(): void
    {
        $driver = DB::connection()->getDriverName();
        $tables = $this->businessTables();

        if ($tables === []) {
            return;
        }

        if ($driver === 'pgsql') {
            $quoted = array_map(
                static fn (string $t) => '"' . str_replace('"', '""', $t) . '"',
                $tables
            );
            DB::statement('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                DB::statement('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            return;
        }

        // SQLite has no TRUNCATE: delete rows and reset the sequence table.
        //
        // FTS5 virtual tables need special care: a plain DELETE empties the
        // internal index and later inserts fail with
        // "invalid fts5 file format (found 0, expected 4 or 5)". The rows are
        // deleted and the index is rebuilt with the FTS5 'rebuild' command
        // afterwards; the shadow tables are never touched directly.
        $virtualTables = [];
        $skipTables = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND sql LIKE '%VIRTUAL TABLE%'") as $row) {
            $name = (string) $row->name;
            $virtualTables[] = $name;
            foreach (['data', 'content', 'idx', 'docsize', 'config'] as $suffix) {
                $skipTables[] = $name . '_' . $suffix;
            }
        }

        DB::statement('PRAGMA foreign_keys = OFF');
        foreach ($tables as $table) {
            if (in_array($table, $skipTables, true)) {
                continue;
            }
            DB::statement('DELETE FROM "' . str_replace('"', '""', $table) . '"');
        }
        DB::statement('DELETE FROM sqlite_sequence');
        DB::statement('PRAGMA foreign_keys = ON');

        foreach ($virtualTables as $virtual) {
            $quoted = '"' . str_replace('"', '""', $virtual) . '"';
            DB::statement("INSERT INTO {$quoted}({$quoted}) VALUES('rebuild')");
        }
    }

    /**
     * @return array<int, string>
     */
    protected function businessTables(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            $names = array_map(static fn ($row) => (string) $row->name, $rows);
        } elseif ($driver === 'mysql') {
            $rows = DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()');
            $names = array_map(static fn ($row) => (string) $row->name, $rows);
        } else {
            $rows = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = 'public'");
            $names = array_map(static fn ($row) => (string) $row->name, $rows);
        }

        return array_values(array_filter(
            $names,
            // 'migrations' is the ledger, not data.
            // 'sites' holds the single default site that the migrations create
            // (id=1). Clearing it would break every site-scoped fixture
            // (categories/facts/settings all reference site_id=1) and the
            // SiteScope fallback, so it is kept.
            static fn (string $name) => $name !== 'migrations'
                && $name !== 'sites'
                && ! str_starts_with($name, 'sqlite_')
        ));
    }

    protected function seedFixtures(): void
    {
        // Order matters: StructureSeeder creates the default site and the
        // category tree, FactSeeder then inserts facts that reference the
        // site (UNIQUE/FK), SettingSeeder fills the settings table.
        $this->seed([
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
    }

    /**
     * Environment for the child processes: the very same database the test
     * process is using.
     *
     * @return array<string, string>
     */
    protected function workerEnvironment(): array
    {
        $driver = (string) config('database.default');
        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$driver}");

        $environment = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => $driver,
            'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => (string) ($connection['database'] ?? ''),
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ];

        // Laravel caches the configuration; the child must not read a stale
        // .env value for the database.
        $environment['CACHE_STORE'] = 'array';
        $environment['SESSION_DRIVER'] = 'array';
        $environment['QUEUE_CONNECTION'] = 'sync';
        $environment['BROADCAST_CONNECTION'] = 'null';

        if ($driver === 'sqlite') {
            // The child must use the very same concurrency-friendly SQLite
            // settings, otherwise it would use DEFERRED transactions and fail
            // with "database is locked" where the parent succeeded.
            $environment['DB_TRANSACTION_MODE'] = 'IMMEDIATE';
            $environment['DB_BUSY_TIMEOUT'] = '15000';
            $environment['DB_JOURNAL_MODE'] = 'WAL';
            $environment['DB_SYNCHRONOUS'] = 'NORMAL';
        }

        return $environment;
    }

    /**
     * The driver name actually in use, for driver-specific assertions.
     */
    protected function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }
}
