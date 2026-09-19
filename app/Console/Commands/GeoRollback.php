<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * geo:rollback（P-STEP 09：Rollback Contract）
 *
 * 回滚三边界（冻结，不假装自动逆向）：
 *
 *   DB 回滚     = 本命令：用 geo:backup 产生的备份文件整库还原
 *                 （不做 migration:rollback 式的"智能逆向"——
 *                  数据迁移后的列结构逆向不保证数据无损）
 *   Code 回滚   = 部署层：切换回上一版 Release Artifact（build 产物按 commit 命名）
 *   Config 回滚 = .env 属环境资产，不进备份；变更需人工对照 .env.example
 *
 * 安全策略：
 *   - 必须 --backup 指定 geo:backup 产物，且校验其 .json 清单 sha256
 *   - 必须 --force（防误触）
 *   - 还原前把当前库再备份一次（回滚的回滚）
 */
class GeoRollback extends Command
{
    protected $signature = 'geo:rollback
                            {--backup= : geo:backup 产生的 .sqlite 备份文件}
                            {--force : 确认执行（防误触）}';

    protected $description = 'Restore the database from a geo:backup file (DB rollback boundary)';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->error('Automatic rollback only supports sqlite. Other drivers restore via native dump tools.');

            return self::FAILURE;
        }
        $backup = (string) $this->option('backup');
        if ($backup === '' || ! is_file($backup)) {
            $this->error('Backup file not found. Usage: geo:rollback --backup=<file> --force');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->error('Refusing to restore without --force (destructive operation).');

            return self::FAILURE;
        }

        $manifest = $backup . '.json';
        if (! is_file($manifest)) {
            $this->error("Backup manifest missing: {$manifest}");

            return self::FAILURE;
        }
        $state = json_decode((string) file_get_contents($manifest), true);
        $sha = hash_file('sha256', $backup);
        if (($state['sha256'] ?? '') !== $sha) {
            $this->error('Backup checksum mismatch — file corrupted or tampered.');

            return self::FAILURE;
        }

        $dbPath = config('database.connections.sqlite.database');

        // 回滚的回滚：还原前先备份当前状态
        $preRollback = storage_path('app/backups/pre-rollback-' . now()->format('Ymd-His') . '.sqlite');
        if (is_file($dbPath)) {
            copy($dbPath, $preRollback);
            $this->line("  [ok] current state preserved: {$preRollback}");
        }

        copy($backup, $dbPath);

        $this->line('  [ok] database restored from ' . basename($backup));
        $this->line('  restored version=' . ($state['version'] ?? '?') .
            ' migrations_last=' . ($state['migrations_last'] ?? '?'));
        $this->warn('Code rollback is a deployment concern: redeploy the matching release artifact.');

        return self::SUCCESS;
    }
}
