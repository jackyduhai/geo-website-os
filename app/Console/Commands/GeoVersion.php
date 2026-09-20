<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * geo:version（P-STEP 07：Versioning Contract）
 *
 * 输出应用版本、数据库迁移状态与环境信息，作为升级前后对拍的基线凭证。
 * 版本号单一来源：config/geo.php 的 'version'。
 */
class GeoVersion extends Command
{
    protected $signature = 'geo:version';
    protected $description = 'Report GEO Website OS application version, schema state and environment';

    public function handle(): int
    {
        $migrations = Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('batch')->orderBy('id')->pluck('migration')->all()
            : [];

        $this->info('GEO Website OS');
        $this->line('  app.version        = ' . config('geo.version', '0.0.0'));
        $this->line('  app.env            = ' . app()->environment());
        $this->line('  php                = ' . PHP_VERSION);
        $this->line('  migrations.count   = ' . count($migrations));
        $this->line('  migrations.last    = ' . (end($migrations) ?: 'none'));

        return self::SUCCESS;
    }
}
