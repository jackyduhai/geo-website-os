<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Console\Command;

/**
 * 手动清空前台整页静态化缓存（发布内容时模型事件已自动失效；
 * 该命令用于改了 config/facts 等文件型内容、或部署后需要强制刷新的场景）。
 */
class ClearPageCache extends Command
{
    protected $signature = 'page-cache:clear';

    protected $description = '清空全部站点的前台整页静态化缓存（各站版本号 +1，旧缓存立即作废）';

    public function handle(): int
    {
        $sites = Site::query()->orderBy('id')->get();

        // CLI 无 HTTP 站点上下文，PageCache::flush() 会回退默认站；必须逐站切换
        // SiteContext 后再 flush，否则非默认站（如 en-only Site B）缓存永不失效（TD-108）。
        if ($sites->isEmpty()) {
            PageCache::flush();
            $this->info('前台整页缓存已清空，当前缓存版本：'.PageCache::version());

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            SiteContext::withSite($site, function () use ($site) {
                PageCache::flush();
                $this->line(sprintf(
                    '  · %s（id=%d）缓存版本：%d',
                    $site->name,
                    $site->id,
                    PageCache::version()
                ));
            });
        }

        $this->info('已清空全部 '.$sites->count().' 个站点的前台整页缓存。');

        return self::SUCCESS;
    }
}
