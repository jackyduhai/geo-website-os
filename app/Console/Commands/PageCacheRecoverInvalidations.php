<?php

namespace App\Console\Commands;

use App\Support\PageCacheInvalidation;
use Illuminate\Console\Command;

/**
 * pagecache:recover-invalidations
 * --------------------------------------------------
 * 提交后缓存失效的可重复执行安全网：扫描所有站点的 pending/retryable 失效记录，
 * 重新执行页面/设置缓存失效。可手动运行，也可纳入调度。
 *
 * 最终兜底为 PageCache 的 6 小时 TTL（旧缓存自然过期）。
 */
class PageCacheRecoverInvalidations extends Command
{
    protected $signature = 'pagecache:recover-invalidations';

    protected $description = 'Recover pending/retryable page-cache invalidations (post-commit safety net)';

    public function handle(): int
    {
        $results = PageCacheInvalidation::recover();

        if ($results === []) {
            $this->info('No pending or retryable cache invalidations.');

            return self::SUCCESS;
        }

        $this->table(['Site ID', 'Before', 'After'], array_map(
            static fn (array $r): array => [$r['site_id'], $r['before'], $r['after']],
            $results
        ));

        $retryable = array_filter(
            $results,
            static fn (array $r): bool => $r['after'] === 'retryable'
        );

        if ($retryable !== []) {
            // 契约：退出码 2 = 仍有未完成（retryable）失效，属可自愈的临时状态，
            // 自动化可据此重跑；并非硬错误（1）。
            $this->warn(count($retryable) . ' site(s) still retryable; cache will self-heal within the PageCache TTL.');

            return 2;
        }

        return self::SUCCESS;
    }
}
