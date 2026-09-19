<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * geo:backup（P-STEP 09：Rollback / Deployment Engineering）
 *
 * 升级前备份契约（rollback 三边界之一：DB 回滚的数据基础）：
 *   - sqlite：整库文件复制到 storage/app/backups/（含版本与迁移状态清单）
 *   - mysql/pgsql：本命令不做实现，输出对应 dump 工具约定（mysqldump/pg_dump），
 *     禁止假装"可回滚"而实际没有备份。
 *
 * 备份清单（JSON）记录：version / migrations.last / 数据计数——用于回滚前后的对拍。
 */
class GeoBackup extends Command
{
    protected $signature = 'geo:backup';
    protected $description = 'Create a pre-upgrade database backup with a state manifest';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->error('Automatic backup only supports sqlite. For ' . config('database.default') .
                ' use native dump tools (mysqldump / pg_dump) and record the file path manually.');

            return self::FAILURE;
        }

        $dbPath = config('database.connections.sqlite.database');
        if (! is_file($dbPath)) {
            $this->error("Database file not found: {$dbPath}");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = "{$dir}/geo-backup-{$stamp}.sqlite";
        copy($dbPath, $target);

        $migrations = DB::table('migrations')->orderBy('batch')->orderBy('id')->pluck('migration')->all();
        $manifest = [
            'created_at'      => now()->toIso8601String(),
            'version'         => config('geo.version', '0.0.0'),
            'migrations_last' => end($migrations) ?: null,
            'migrations_count' => count($migrations),
            'counts'          => [
                'sites'     => DB::table('sites')->count(),
                'contents'  => DB::table('contents')->count(),
                'seo_metas' => DB::table('seo_metas')->count(),
                'users'     => DB::table('users')->count(),
            ],
            'sha256' => hash_file('sha256', $target),
        ];
        file_put_contents($target . '.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line("  [ok] backup: {$target}");
        $this->line('  [ok] version=' . $manifest['version'] . ' migrations=' . $manifest['migrations_count']);

        return self::SUCCESS;
    }
}
