<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * geo:upgrade（P-STEP 07：Upgrade Contract）
 *
 * 既有站点升级到当前版本的标准流程（顺序即契约）：
 *
 *   1. Pre-migrate 数据迁移：legacy contents SEO 字段 → seo_metas
 *      （必须在 drop 列的 migration 之前运行）
 *   2. migrate --force（应用全部待执行迁移，含 legacy 列删除）
 *   3. Post 验证：核心表 / 版本报告
 *
 * 数据安全：本命令不做破坏性回滚；回滚策略见 rollback 契约（backup 优先）。
 */
class GeoUpgrade extends Command
{
    protected $signature = 'geo:upgrade';
    protected $description = 'Upgrade an existing GEO Website OS site: pre-migrate backfill, run migrations, verify';

    public function handle(): int
    {
        $this->info('GEO Website OS upgrade — start');

        // ---------- 1. Pre-migrate 数据迁移（顺序即契约） ----------
        $this->call('geo:backfill-seo');

        // ---------- 2. 迁移 ----------
        $this->call('migrate', ['--force' => true]);

        // ---------- 3. Post 验证 ----------
        foreach (['sites', 'contents', 'seo_metas', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Verification failed: table {$table} missing");

                return self::FAILURE;
            }
        }
        $this->line('  [ok] core tables');

        if (Schema::hasColumn('contents', 'seo_title')) {
            $this->warn('  [note] contents legacy SEO columns still present — run migrate to apply column drop');
        } else {
            $this->line('  [ok] legacy SEO columns removed');
        }

        $counts = [
            'sites'    => DB::table('sites')->count(),
            'contents' => DB::table('contents')->count(),
            'seo_metas' => DB::table('seo_metas')->count(),
        ];
        foreach ($counts as $table => $n) {
            $this->line(sprintf('  [data] %-10s = %d', $table, $n));
        }

        $this->info('GEO Website OS upgrade — complete (version ' . config('geo.version', '0.0.0') . ')');

        return self::SUCCESS;
    }
}
