<?php

namespace App\Console\Commands;

use App\Support\CliSiteContext;
use App\Support\Search\SearchIndexBuilder;
use App\Support\Search\SearchIndexSync;
use App\Support\SiteContext;
use Illuminate\Console\Command;

/**
 * search:reindex（P-STEP 18H-1）。
 * ------------------------------------------------------------------
 * 搜索索引是「派生只读模型」（非事实源），可随时安全重建：
 *   - 无 --site / --site-id：全量重建所有站点；
 *   - 指定站点：仅重建该站点。
 *
 * 正常运行下增量同步（{@see SearchIndexSync}）已自动维护索引；本命令用于首次安装 /
 * 升级、批量导入后的校正，或索引疑似不一致时人工兜底，不回写任何权威表。
 */
class SearchReindex extends Command
{
    protected $signature = 'search:reindex
                            {--site= : 只重建指定站点（slug）}
                            {--site-id= : 只重建指定站点（ID）}';

    protected $description = 'Rebuild the derived full-text search index (all sites or one site)';

    public function handle(SearchIndexBuilder $builder): int
    {
        $wantsOne = $this->option('site') || $this->option('site-id');

        if (! $wantsOne) {
            $this->info('Rebuilding search index for all sites...');

            // 重建期间屏蔽增量事件，避免 rebuild 与 saved 事件互相触发。
            $n = SearchIndexSync::suppress(fn () => $builder->rebuildAll());

            // 全量重建本身即与权威数据一致，清掉可能残留的 dirty 标记。
            app(SearchIndexSync::class)->clearDirty();

            $this->info("Search index rebuilt: {$n} document(s)");

            return self::SUCCESS;
        }

        $site = CliSiteContext::resolve($this);
        if ($site === null) {
            return self::FAILURE;
        }

        $n = SearchIndexSync::suppress(fn () => SiteContext::withSite(
            $site,
            fn () => $builder->rebuildSite((int) $site->id),
        ));

        app(SearchIndexSync::class)->clearDirty();

        $this->info("Search index rebuilt for site {$site->slug}: {$n} document(s)");

        return self::SUCCESS;
    }
}
