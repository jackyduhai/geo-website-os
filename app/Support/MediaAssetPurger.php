<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * WP-7 媒体资产删除器（Media Asset Purger）。
 *
 * 领域职责：在 MediaReferenceScanner 已确认「无受保护引用」之后，处置该媒体
 * 自有的二进制资产——原文件 + 受管理衍生物（WebP sibling 等）。
 *
 * 结果性不变量（不强制 DB 与文件系统绝对原子）：
 *  - 全部目标已处置（删除成功，或本就不存在）→ ['ok' => true]；
 *  - 任一目标仍存在且无法删除           → ['ok' => false, 'failed' => [...]]。
 *
 * 幂等语义：
 *  - 文件已经不存在 → 视为处置成功（继续处理剩余资产），不视为失败；
 *  - 因此首次部分成功、重试时已删文件不会卡住整体收口。
 *
 * 本类只动「该媒体自己的文件」，绝不修改任何引用方（Content/Page/Entity/...）。
 */
class MediaAssetPurger
{
    /**
     * 处置媒体的原文件与受管理衍生物。
     *
     * @return array{ok: bool, targets: array<int,string>, failed: array<int,string>}
     */
    public function purge(Media $media): array
    {
        $disk = Storage::disk($media->disk);

        $original = ltrim((string) $media->path, '/');
        $webp = ImageOptimizer::webpSiblingRelative($original);

        $targets = array_values(array_filter([$original, $webp], fn ($p) => is_string($p) && $p !== ''));

        $failed = [];
        foreach ($targets as $rel) {
            // 文件已不存在 → 幂等成功，跳过。
            if (! $disk->exists($rel)) {
                continue;
            }
            if (! $disk->delete($rel)) {
                $failed[] = $rel;
            }
        }

        return ['ok' => empty($failed), 'targets' => $targets, 'failed' => $failed];
    }
}
