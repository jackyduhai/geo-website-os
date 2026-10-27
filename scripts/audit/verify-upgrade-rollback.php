<?php

declare(strict_types=1);

/**
 * GEO OS V1.4 — upgrade / failure-recovery / rollback drill.
 *
 * Simulates the real upgrade path of the published release (v1.0.0, 56
 * migrations) to the candidate version (62 migrations) against a live
 * database, and records what the system can actually promise.
 *
 * 2026-10-27：候选版本新增 2026_10_27_000001_tighten_contents_slot_unique_to_site_scope，
 * 迁移总数 61 → 62，因此回退到 v1.0.0 发布形态（56 个迁移）所需的步数由 5 改为 6。
 *
 * Drill steps
 *   1. migrate:fresh            -> full candidate schema
 *   2. migrate:rollback --step  -> back to the previous release's shape
 *      (this also exercises the DOWN path of the newest migrations)
 *   3. insert business fixtures -> data that must survive the upgrade
 *   4. migrate                  -> upgrade to the candidate version
 *   5. assert                   -> data integrity, constraint consistency,
 *                                   and the capabilities the new migrations add
 *   6. fault injection          -> a deliberately failing migration must not
 *                                   leave a half-applied state and must be
 *                                   recoverable by removing it and re-running
 *   7. native backup / restore  -> for MySQL / PostgreSQL: dump, damage the
 *                                   data, restore, verify. SQLite has no
 *                                   native dump, so it is verified through the
 *                                   project's own geo:rollback command.
 *
 * Usage:
 *   php scripts/audit/verify-upgrade-rollback.php <driver> <host> <port> <database> <user> [password] [rollback-steps]
 *
 * Output: a JSON report on stdout (also written to the path given by
 * REPORT_PATH). Exit code 0 = every assertion held.
 */

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$driver = $argv[1] ?? '';
$host = $argv[2] ?? '';
$port = $argv[3] ?? '';
$database = $argv[4] ?? '';
$user = $argv[5] ?? '';
$password = $argv[6] ?? '';
// 默认回退 6 步：候选 62 个迁移 → v1.0.0 发布形态的 56 个迁移。
$rollbackSteps = (int) ($argv[7] ?? 6);

/*
 * 把命令行参数真正接到数据库连接上。
 *
 * 2026-10-27 修正：脚本此前只读取 argv 却不配置连接，于是无论传入什么驱动
 * 都落到 .env / config 默认的 sqlite 连接上。后果有两个：
 *   1. 「对 PostgreSQL / MySQL 做演练」这个语义从未真正生效，报告会谎报驱动；
 *   2. 两个演练并行时，两个进程会同时写同一个默认 sqlite 文件，
 *      把它写成 "database disk image is malformed"。
 * 现在显式覆盖 config 并 purge 掉已建立的连接，确保驱动与库名与参数一致。
 */
if ($driver !== '') {
    config([
        'database.default' => $driver,
        'database.connections.' . $driver . '.host' => $host,
        'database.connections.' . $driver . '.port' => $port,
        'database.connections.' . $driver . '.database' => $database,
        'database.connections.' . $driver . '.username' => $user,
    ]);

    if ($password !== '') {
        config(['database.connections.' . $driver . '.password' => $password]);
    }

    DB::purge($driver);
}

$report = [
    'driver' => $driver,
    'database' => $database,
    // 记录「实际连上的」驱动与库，而不是「请求的」——两者不一致就说明
    // 连接配置没有生效，报告必须如实反映，而不是复述命令行参数。
    'actual_driver' => DB::connection()->getDriverName(),
    'actual_database' => DB::connection()->getDatabaseName(),
    'rollback_steps' => $rollbackSteps,
    'steps' => [],
    'assertions' => [],
    'errors' => [],
];

$assert = static function (bool $ok, string $name, string $detail = '') use (&$report): bool {
    $report['assertions'][] = ['name' => $name, 'passed' => $ok, 'detail' => $detail];

    return $ok;
};

$step = static function (string $name) use (&$report): void {
    $report['steps'][] = $name;
};

$tables = static function (): array {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        return array_map(static fn ($r) => (string) $r->name, $rows);
    }
    if ($driver === 'mysql') {
        $rows = DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()');

        return array_map(static fn ($r) => (string) $r->name, $rows);
    }
    $rows = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = 'public'");

    return array_map(static fn ($r) => (string) $r->name, $rows);
};

/**
 * 判断某表上是否存在「列集恰为 $columns」的唯一约束。
 *
 * 三库口径统一为「唯一索引 / 唯一约束」都算，逐个按索引名分组比对列集：
 *   - SQLite：PRAGMA index_list(unique=1) + index_info
 *   - MySQL ：information_schema.STATISTICS(NON_UNIQUE=0)，按 INDEX_NAME 分组
 *   - PG    ：pg_index.indisunique，按索引名分组
 *
 * 2026-10-27 修正：PG 分支原用 pg_constraint.contype='u'，会漏掉
 * partial unique index（带 WHERE 谓词的索引在 PG 里不是约束，只是索引），
 * 造成「断言存在却报缺失」的假阴性；MySQL 分支原查 table_constraints，
 * 与 SQLite/PG 的「索引」口径不一致。两者现统一到索引口径。
 */
$hasUnique = static function (string $table, array $columns) use ($driver): bool {
    $driverName = DB::connection()->getDriverName();

    if ($driverName === 'sqlite') {
        $indexes = DB::select('PRAGMA index_list(' . DB::connection()->getPdo()->quote($table) . ')');
        foreach ($indexes as $index) {
            if ((int) $index->unique !== 1) {
                continue;
            }
            $info = DB::select('PRAGMA index_info(' . DB::connection()->getPdo()->quote($index->name) . ')');
            $cols = array_map(static fn ($c) => (string) $c->name, $info);
            sort($cols);
            $want = $columns;
            sort($want);
            if ($cols === $want) {
                return true;
            }
        }

        return false;
    }

    // The rows must be grouped per INDEX: a table usually carries several
    // unique constraints, and mixing their columns would report a false
    // negative.
    if ($driverName === 'mysql') {
        $rows = DB::select(
            'SELECT INDEX_NAME AS iname, COLUMN_NAME AS col
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );
    } else {
        $rows = DB::select(
            "SELECT c.relname AS iname, att.attname AS col
             FROM pg_index idx
             JOIN pg_class rel ON rel.oid = idx.indrelid
             JOIN pg_class c ON c.oid = idx.indexrelid
             JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY(idx.indkey)
             WHERE idx.indisunique AND nsp.nspname = 'public' AND rel.relname = ?
             ORDER BY c.relname, att.attnum",
            [$table]
        );
    }

    $groups = [];
    foreach ($rows as $row) {
        $groups[(string) $row->iname][] = (string) $row->col;
    }

    $want = $columns;
    sort($want);

    foreach ($groups as $cols) {
        sort($cols);
        if ($cols === $want) {
            return true;
        }
    }

    return false;
};

/**
 * 解析备份 / 恢复工具的可执行文件路径。
 *
 * 优先级：
 *   1) 环境变量（PG_DUMP / PSQL / MYSQLDUMP / MYSQL）—— 显式指定绝对路径；
 *   2) PATH 中的同名命令；
 *   3) Windows 常见安装目录下的固定位置。
 *
 * 不把某一台开发机的绝对路径写死：脚本要能落到别的机器上跑。
 * 找不到时抛异常并说明如何指定，而不是静默跳过备份恢复步骤。
 */
$resolveTool = static function (string $envName, string $command, array $windowsCandidates): string {
    $fromEnv = getenv($envName);
    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    // PATH 查找（Windows 下同时试 .exe）
    $which = @shell_exec('where ' . escapeshellarg($command) . ' 2>NUL');
    if (! is_string($which) || trim($which) === '') {
        $which = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    }
    if (is_string($which) && trim($which) !== '') {
        $first = strtok(trim($which), "\r\n");

        if ($first !== false && trim($first) !== '') {
            return trim($first);
        }
    }

    // Windows 常见安装位置
    $roots = [
        'D:/GEO-OS-rewrite/_v14-tools/pgsql_x/pgsql/bin',
        'C:/Program Files/PostgreSQL/17/bin',
        'C:/Program Files/PostgreSQL/16/bin',
        'C:/Program Files/PostgreSQL/15/bin',
        'C:/Program Files/MySQL/MySQL Server 8.4/bin',
        'C:/Program Files/MySQL/MySQL Server 8.0/bin',
        'C:/xampp/mysql/bin',
    ];
    foreach ($roots as $root) {
        foreach ($windowsCandidates as $candidate) {
            $path = $root . '/' . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }
    }

    throw new RuntimeException(
        "找不到备份工具「{$command}」。请用环境变量 {$envName} 指定其绝对路径，"
        .'例如：'.$envName.'=C:/path/to/'.$command
    );
};

try {
    // ── 1. clean candidate schema ────────────────────────────────────────
    Artisan::call('migrate:fresh', ['--force' => true]);
    $step('migrate:fresh (candidate schema)');
    $applied = DB::table('migrations')->count();
    $assert($applied > 0, 'candidate.migrations_applied', "count={$applied}");

    // ── 2. roll back to the previous release's shape ─────────────────────
    Artisan::call('migrate:rollback', ['--step' => $rollbackSteps, '--force' => true]);
    $step("migrate:rollback --step={$rollbackSteps} (previous release shape)");
    $rolledBack = DB::table('migrations')->count();
    $report['migrations_after_rollback'] = $rolledBack;
    $assert(
        $rolledBack === $applied - $rollbackSteps,
        'rollback.applied_count',
        "expected=" . ($applied - $rollbackSteps) . " actual={$rolledBack}"
    );

    $tablesBefore = $tables();
    $report['tables_before_upgrade'] = count($tablesBefore);

    // ── 3. business fixtures that must survive the upgrade ───────────────
    $now = date('Y-m-d H:i:s');
    $siteId = (int) DB::table('sites')->where('slug', 'default')->value('id');
    if ($siteId === 0) {
        DB::table('sites')->insert([
            'slug' => 'default', 'name' => '默认站点', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $siteId = (int) DB::table('sites')->where('slug', 'default')->value('id');
    }

    DB::table('contents')->insert([
        'site_id' => $siteId, 'type' => 'article', 'title' => '升级演练内容',
        'slug' => 'upgrade-drill-content', 'status' => 'published',
        'locale' => 'zh-CN', 'content_hash' => 'drill-hash-001',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $contentId = (int) DB::table('contents')->where('slug', 'upgrade-drill-content')->value('id');

    DB::table('facts')->insert([
        'site_id' => $siteId, 'key' => 'DRILL-FACT-001', 'locale' => 'zh-CN',
        'label' => '演练事实', 'value' => '演练值', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $fixtureCount = (int) DB::table('contents')->where('slug', 'upgrade-drill-content')->count();
    $assert($fixtureCount === 1, 'fixture.inserted', "contents={$fixtureCount}");
    $step('fixtures inserted (contents / facts)');

    // ── 4. upgrade ───────────────────────────────────────────────────────
    Artisan::call('migrate', ['--force' => true]);
    $step('migrate (upgrade to candidate)');
    $upgraded = DB::table('migrations')->count();
    $report['migrations_after_upgrade'] = $upgraded;
    $assert($upgraded === $applied, 'upgrade.migrations_applied', "expected={$applied} actual={$upgraded}");

    // ── 5. assertions on the upgraded state ──────────────────────────────
    $stillThere = (int) DB::table('contents')->where('id', $contentId)->count();
    $assert($stillThere === 1, 'upgrade.data_survived', "contents id={$contentId} count={$stillThere}");

    $factStill = (int) DB::table('facts')->where('key', 'DRILL-FACT-001')->count();
    $assert($factStill === 1, 'upgrade.fact_survived', "count={$factStill}");

    $hashIntact = (string) DB::table('contents')->where('id', $contentId)->value('content_hash');
    $assert($hashIntact === 'drill-hash-001', 'upgrade.hash_intact', "hash={$hashIntact}");

    $tablesAfter = $tables();
    $report['tables_after_upgrade'] = count($tablesAfter);

    // capabilities the new migrations add
    $assert(
        Schema::hasTable('entity_tombstones'),
        'upgrade.entity_tombstones_table',
        'WP-4 tombstone registry table must exist'
    );
    $assert(
        Schema::hasTable('fact_review_markers'),
        'upgrade.fact_review_markers_table',
        'WP-8 fact re-review marker table must exist'
    );
    $assert(
        Schema::hasColumn('entities', 'role'),
        'upgrade.entities_role',
        'WP-1 entity lifecycle role column must exist'
    );
    $assert(
        Schema::hasColumn('contents', 'entity_refs'),
        'upgrade.contents_entity_refs',
        'WP-3 semantic references column must exist'
    );
    $assert(
        Schema::hasColumn('media', 'delete_failed_at'),
        'upgrade.media_delete_failed_at',
        'WP-7 media delete failure column must exist'
    );

    // constraint consistency
    $assert(
        $hasUnique('contents', ['site_id', 'external_id']),
        'constraint.contents_site_external_unique',
        'UNIQUE(site_id, external_id) must survive the upgrade'
    );

    // V1.4 · B：contents.slot 的站点级唯一约束必须在升级后成立。
    // 升级前 MySQL / PostgreSQL 是 UNIQUE(site_id, slot, locale)（比 SQLite 宽），
    // 2026_10_27_000001 把它收紧为站点级 UNIQUE(site_id, slot)。
    $assert(
        $hasUnique('contents', ['site_id', 'slot']),
        'constraint.contents_site_id_slot_unique',
        'UNIQUE(site_id, slot) must exist after the upgrade (site-scoped slot)'
    );
    $assert(
        ! $hasUnique('contents', ['site_id', 'slot', 'locale']),
        'constraint.contents_slot_locale_unique_removed',
        'the locale-scoped slot unique constraint must no longer exist'
    );

    // ── 6. fault injection: a failing migration must not half-apply ──────
    $faultFile = $root . '/database/migrations/2999_12_31_000000_v14_fault_injection_probe.php';
    $faultCode = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

/**
 * TEMPORARY fault-injection probe created by verify-upgrade-rollback.php.
 * It deliberately fails to prove that a failed migration leaves no
 * half-applied state and is recoverable. The drill removes this file again.
 */

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('V1.4 fault injection: deliberate failure');
    }

    public function down(): void
    {
        //
    }
};
PHP;
    file_put_contents($faultFile, $faultCode);

    $faultExit = null;
    $faultOutput = '';
    try {
        Artisan::call('migrate', ['--force' => true]);
    } catch (\Throwable $e) {
        $faultExit = $e->getMessage();
    }
    $faultOutput = (string) Artisan::output();

    $recordedAfterFault = DB::table('migrations')->where('migration', '2999_12_31_000000_v14_fault_injection_probe')->count();
    $assert(
        $recordedAfterFault === 0,
        'fault.not_recorded_as_applied',
        "a failed migration must not appear in the migrations ledger (count={$recordedAfterFault})"
    );

    // remove the probe and re-run: the system must recover on its own
    @unlink($faultFile);
    Artisan::call('migrate', ['--force' => true]);
    $afterRecovery = DB::table('migrations')->count();
    $assert(
        $afterRecovery === $applied,
        'fault.recovered',
        "after removing the probe the migration count must return to {$applied} (actual={$afterRecovery})"
    );
    $step('fault injection + recovery');

    // data must still be intact after the fault/recovery cycle
    $afterFaultData = (int) DB::table('contents')->where('id', $contentId)->count();
    $assert($afterFaultData === 1, 'fault.data_intact', "count={$afterFaultData}");

    // ── 7. native backup / restore (MySQL / PostgreSQL) ──────────────────
    $report['backup_restore'] = ['supported' => false, 'note' => 'not executed'];

    if ($driver === 'pgsql' || $driver === 'mysql') {
        // 备份 / 恢复工具与工作目录必须可配置 —— 脚本要能落到别的机器上跑，
        // 不能把某一台开发机的绝对路径写死。
        $workDir = rtrim(getenv('GEO_OS_DRILL_WORKDIR') ?: sys_get_temp_dir(), '/\\');
        if (! is_dir($workDir)) {
            @mkdir($workDir, 0777, true);
        }

        $dumpFile = $workDir . '/upgrade-drill-' . $driver . '-' . getmypid() . '.sql';

        $dumpCommand = $driver === 'pgsql'
            ? sprintf(
                '"%s" --host=%s --port=%s --username=%s --dbname=%s --no-owner --file="%s"',
                $resolveTool('PG_DUMP', 'pg_dump', ['pg_dump.exe', 'pg_dump']),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($database),
                $dumpFile
            )
            : sprintf(
                '"%s" --host=%s --port=%s --user=%s --password=%s --single-transaction --result-file="%s" %s',
                $resolveTool('MYSQLDUMP', 'mysqldump', ['mysqldump.exe', 'mysqldump']),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                escapeshellarg($password),
                $dumpFile,
                escapeshellarg($database)
            );

        exec($dumpCommand, $dumpOut, $dumpRc);
        $dumpOk = $dumpRc === 0 && is_file($dumpFile) && filesize($dumpFile) > 0;
        $assert($dumpOk, 'backup.dump_created', 'rc=' . $dumpRc . ' file=' . $dumpFile);

        if ($dumpOk) {
            // damage the data
            DB::table('contents')->where('id', $contentId)->delete();
            $damaged = (int) DB::table('contents')->where('id', $contentId)->count();
            $assert($damaged === 0, 'backup.damage_applied', "count={$damaged}");

            $restoreCommand = $driver === 'pgsql'
                ? sprintf(
                    '"%s" --host=%s --port=%s --username=%s --dbname=%s --quiet --file="%s"',
                    $resolveTool('PSQL', 'psql', ['psql.exe', 'psql']),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    escapeshellarg($database),
                    $dumpFile
                )
                : sprintf(
                    '"%s" --host=%s --port=%s --user=%s --password=%s %s < "%s"',
                    $resolveTool('MYSQL', 'mysql', ['mysql.exe', 'mysql']),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    $dumpFile
                );

            exec($restoreCommand, $restoreOut, $restoreRc);
            $restored = (int) DB::table('contents')->where('id', $contentId)->count();
            $assert(
                $restored === 1,
                'backup.restore_recovered_data',
                "restored={$restored} rc={$restoreRc}"
            );

            $report['backup_restore'] = [
                'supported' => true,
                'tool' => $driver === 'pgsql' ? 'pg_dump / psql' : 'mysqldump / mysql',
                'dump_file' => $dumpFile,
                'dump_bytes' => filesize($dumpFile),
                'restore_rc' => $restoreRc,
            ];
            $step('native backup / restore');
        }
    } else {
        // SQLite has no native dump tool. The supported rollback boundary is
        // the project's own backup/restore pair:
        //     geo:backup  -> writes <file>.sqlite plus a .json manifest (sha256)
        //     geo:rollback --backup=<file> --force -> restores the whole file
        // This is a BACKUP RESTORE, not a schema migration inverse, and it is
        // the only automatic path the project offers on SQLite.
        $backupOutput = '';
        $backupFile = null;
        try {
            Artisan::call('geo:backup');
            $backupOutput = (string) Artisan::output();
        } catch (\Throwable $e) {
            $backupOutput = 'EXCEPTION: ' . $e->getMessage();
        }
        $report['sqlite_geo_backup_output'] = $backupOutput;

        // The command writes into storage/app/backups; pick the newest file.
        $backupDir = storage_path('app/backups');
        $candidates = is_dir($backupDir) ? glob($backupDir . '/*.sqlite') ?: [] : [];
        if ($candidates !== []) {
            usort($candidates, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $backupFile = $candidates[0];
        }

        $assert($backupFile !== null, 'sqlite.backup_created', mb_substr($backupOutput, 0, 300));

        if ($backupFile !== null) {
            // damage the data, then restore from the backup
            DB::table('contents')->where('id', $contentId)->delete();
            $damaged = (int) DB::table('contents')->where('id', $contentId)->count();
            $assert($damaged === 0, 'sqlite.damage_applied', "count={$damaged}");

            $rollbackOutput = '';
            try {
                Artisan::call('geo:rollback', ['--backup' => $backupFile, '--force' => true]);
                $rollbackOutput = (string) Artisan::output();
            } catch (\Throwable $e) {
                $rollbackOutput = 'EXCEPTION: ' . $e->getMessage();
            }
            $report['sqlite_geo_rollback_output'] = $rollbackOutput;

            $restored = (int) DB::table('contents')->where('id', $contentId)->count();
            $assert(
                $restored === 1,
                'sqlite.geo_rollback_restored_data',
                "restored={$restored} output=" . mb_substr($rollbackOutput, 0, 200)
            );

            // the restored database must still be the candidate schema
            $restoredMigrations = DB::table('migrations')->count();
            $assert(
                $restoredMigrations === $applied,
                'sqlite.geo_rollback_schema_intact',
                "migrations={$restoredMigrations}"
            );
            $step('geo:backup + geo:rollback (sqlite backup-restore path)');
        }
    }
} catch (\Throwable $e) {
    $report['errors'][] = get_class($e) . ': ' . $e->getMessage();
}

$passed = count(array_filter($report['assertions'], static fn (array $a) => $a['passed']));
$failed = count($report['assertions']) - $passed;
$report['summary'] = [
    'assertions_total' => count($report['assertions']),
    'assertions_passed' => $passed,
    'assertions_failed' => $failed,
    'errors' => count($report['errors']),
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo $json, PHP_EOL;

$reportPath = getenv('REPORT_PATH');
if ($reportPath) {
    file_put_contents($reportPath, $json . PHP_EOL);
}

exit(($failed === 0 && $report['errors'] === []) ? 0 : 1);
