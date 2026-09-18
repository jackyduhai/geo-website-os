<?php

namespace App\Console\Commands;

use App\Support\PageCache;
use Illuminate\Console\Command;

/**
 * 手动清空前台整页静态化缓存（发布内容时模型事件已自动失效；
 * 该命令用于改了 config/facts 等文件型内容、或部署后需要强制刷新的场景）。
 */
class ClearPageCache extends Command
{
    protected $signature = 'page-cache:clear';

    protected $description = '清空前台整页静态化缓存（版本号 +1，旧缓存立即作废）';

    public function handle(): int
    {
        PageCache::flush();
        $this->info('前台整页缓存已清空，当前缓存版本：'.PageCache::version());

        return self::SUCCESS;
    }
}
