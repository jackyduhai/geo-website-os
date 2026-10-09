<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Media;
use App\Support\MediaAssetPurger;
use Illuminate\Console\Command;

/**
 * WP-7 幂等重试删除失败的媒体。
 *
 * 扫描 delete_failed_at 非空的记录，重新执行资产处置：
 *  - 已不存在的文件 → 幂等成功；
 *  - 仍存在的文件 → 再尝试删除；
 *  - 全部成功 → 删除 DB 记录（收口）；
 *  - 仍失败 → 刷新 delete_failed_at，记录保留。
 *
 * 用法：php artisan media:retry-deletes
 */
class RetryMediaDeletes extends Command
{
    protected $signature = 'media:retry-deletes';

    protected $description = '幂等重试删除失败的媒体（delete_failed_at 非空）';

    public function handle(MediaAssetPurger $purger): int
    {
        // CLI 无站点上下文，显式绕过 SiteScope 处理全部待重试记录。
        $pending = Media::withoutSiteScope()->whereNotNull('delete_failed_at')->get();

        if ($pending->isEmpty()) {
            $this->info('没有待重试的删除记录。');

            return self::SUCCESS;
        }

        $recovered = 0;
        $stillFailing = 0;

        foreach ($pending as $media) {
            $result = $purger->purge($media);

            if ($result['ok']) {
                $name = $media->original_name;
                $media->delete();
                AuditLog::record('media.delete_recovered', '媒体重试删除成功：' . $name, [], 'media', $media->id);
                $recovered++;
            } else {
                $media->forceFill(['delete_failed_at' => now()])->save();
                $stillFailing++;
            }
        }

        $this->line(sprintf('待重试 %d 条；重试成功 %d 条；仍失败 %d 条。', $pending->count(), $recovered, $stillFailing));

        return self::SUCCESS;
    }
}
