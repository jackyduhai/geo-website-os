<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;

/**
 * 批量治理历史上传图片：按场景限宽 + 重压，原地覆盖。
 * 幂等：已达标（宽度未超限且体积不大）的图片会跳过，可重复执行。
 * 用法：php artisan media:optimize            实际执行
 *      php artisan media:optimize --dry-run  仅预览将处理哪些文件
 */
class OptimizeImages extends Command
{
    protected $signature = 'media:optimize {--dry-run : 只统计不写盘}';

    protected $description = '压缩并限宽 storage/app/public 下的历史图片（banners/blocks/covers/media/content）';

    /** 目录 => 最大宽度 */
    private array $targets = [
        'banners' => ImageOptimizer::MAXW_BANNER,
        'blocks'  => ImageOptimizer::MAXW_CONTENT,
        'covers'  => ImageOptimizer::MAXW_CONTENT,
        'media'   => ImageOptimizer::MAXW_CONTENT,
        'content' => ImageOptimizer::MAXW_CONTENT,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = storage_path('app/public');

        $scanned = $changed = $skipped = $webp = 0;
        $beforeBytes = $afterBytes = 0;
        $rows = [];

        foreach ($this->targets as $dir => $maxWidth) {
            $base = $disk . DIRECTORY_SEPARATOR . $dir;
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    continue; // gif/avif/svg/其它保留
                }
                $scanned++;
                $path = $file->getPathname();
                $info = @getimagesize($path);
                if ($info === false) {
                    $skipped++;
                    continue;
                }
                $wide = ($info[0] ?? 0) > $maxWidth;
                $heavy = ImageOptimizer::needsRecompress($path);
                if (! $wide && ! $heavy) {
                    // 原图已达标无需重压，但仍为 JPEG/PNG 幂等补一个 .webp 兄弟文件（渐进增强）
                    if (! $dry && ImageOptimizer::ensureWebp($path) !== null) {
                        $webp++;
                    }
                    $skipped++;
                    continue;
                }

                $before = filesize($path);
                $beforeBytes += $before;
                if ($dry) {
                    $afterBytes += $before;
                    $rows[] = ['dry', $dir.'/'.basename($path), $info[0].'x'.$info[1], round($before / 1024), '?', $wide ? '限宽' : '重压'];
                    $changed++;
                    continue;
                }

                $res = ImageOptimizer::optimize($path, $maxWidth, force: $heavy && ! $wide);
                if ($res === null) {
                    $skipped++;
                    $beforeBytes -= $before;
                    continue;
                }
                $after = filesize($path);
                $afterBytes += $after;
                $rows[] = ['ok', $dir.'/'.basename($path), $info[0].'x'.$info[1].'→'.$res['width'].'x'.$res['height'],
                    round($before / 1024), round($after / 1024), $res['reason']];
                $changed++;
            }
        }

        $this->table(['状态', '文件', '尺寸', '前KB', '后KB', '动作'], $rows);
        $this->line(sprintf('扫描 %d 张；重压/限宽 %d 张；补 WebP %d 张；跳过(已达标) %d 张。', $scanned, $changed, $webp, $skipped));
        if (!$dry && ($changed > 0 || $webp > 0)) {
            if ($changed > 0) {
                $saved = max(0, $beforeBytes - $afterBytes);
                $pct = $beforeBytes > 0 ? round($saved / $beforeBytes * 100, 1) : 0;
                $this->line(sprintf('合计 %s → %s（节省 %s，%s%%）',
                    $this->kb($beforeBytes), $this->kb($afterBytes), $this->kb($saved), $pct));
            }
            $this->call('page-cache:clear'); // 新增/更新 webp 后前台 HTML 的 <picture> 需重新渲染
        } elseif ($dry) {
            $this->line('dry-run：未写盘。预计处理后体积约 '.$this->kb($beforeBytes).'（以实际重压为准）。');
        }

        return self::SUCCESS;
    }

    private function kb(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 2).' MB';
    }
}
