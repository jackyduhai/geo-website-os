<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * 统一图片优化（全站唯一一处）：上传即限宽 + 压缩，保持原格式与透明通道。
 * ------------------------------------------------------------------
 * 设计原则：
 *  - 只做「向下兼容」处理：不放大、不改扩展名、不改 URL、不改变业务字段；
 *  - JPEG/WebP 重新编码到统一质量并按场景限宽；PNG 仅在超宽时等比缩小（无损保留 Alpha）；
 *  - GIF（可能含动画）/AVIF/SVG/非图片一律原样保留，避免破坏；
 *  - GD 不可用或解码失败时静默回退原图，绝不让上传流程因压缩失败而中断。
 *
 * 场景限宽建议：Banner/Hero 2048；区块/封面/媒体/正文 1600；Logo/设置 1200。
 */
class ImageOptimizer
{
    public const MAXW_BANNER = 2048;
    public const MAXW_CONTENT = 1600;
    public const MAXW_LOGO = 1200;

    private const JPEG_Q = 82;
    private const WEBP_Q = 80;

    /**
     * 走 Laravel 原有落盘逻辑，再就地优化。返回 public 盘相对路径（与 $file->store 完全一致）。
     */
    public static function store(UploadedFile $file, string $dir, int $maxWidth = self::MAXW_CONTENT): ?string
    {
        $path = $file->store($dir, 'public');
        if (! $path) {
            return $path;
        }
        self::optimize(storage_path('app/public/' . $path), $maxWidth);

        return $path;
    }

    /**
     * 就地优化一张已落盘的图片。
     *
     * @return array{before:int,after:int,width:int,height:int,changed:bool,reason:string}|null
     */
    public static function optimize(string $absolute, int $maxWidth = self::MAXW_CONTENT, bool $force = false): ?array
    {
        if (! function_exists('gd_info') || ! is_file($absolute)) {
            return null;
        }

        $before = filesize($absolute) ?: 0;
        $info = @getimagesize($absolute);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }
        [$width, $height, $type] = $info;

        // 已达标（不超宽、非大图、未强制重压）则原图保留，避免无谓重编码造成二次损失；
        // 但仍为 JPEG/PNG 补一个同尺寸 .webp 兄弟文件（渐进增强，原图作为 <img> 回退）。
        if (! $force && $width <= $maxWidth && ! self::needsRecompress($absolute)) {
            if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                self::ensureWebp($absolute);
            }

            return null;
        }

        $img = self::decode($absolute, $type);
        if ($img === null) {
            return null; // 不支持的类型（GIF/AVIF/SVG 等）或解码失败：保留原图
        }

        // 手机直传 JPEG 的 EXIF 方向自动转正（best-effort，exif 扩展不存在则跳过）
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $img = self::applyExifOrientation($img, $absolute);
            $width = imagesx($img);
            $height = imagesy($img);
        }

        $resized = false;
        if ($width > $maxWidth) {
            $newW = $maxWidth;
            $newH = (int) max(1, round($height * ($maxWidth / $width)));
            $canvas = imagecreatetruecolor($newW, $newH);

            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
            }

            imagecopyresampled($canvas, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
            imagedestroy($img);
            $img = $canvas;
            $width = $newW;
            $height = $newH;
            $resized = true;
        }

        $saved = self::encode($img, $absolute, $type);
        // 重压/缩放后同步产出同尺寸 .webp 兄弟文件（仅 JPEG/PNG；原图保留为回退）
        if ($saved && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) && function_exists('imagewebp')) {
            $webpPath = preg_replace('/\.[^.]+$/', '', $absolute).'.webp';
            self::writeWebp($img, $webpPath);
        }
        imagedestroy($img);
        if (! $saved) {
            return null;
        }

        $after = filesize($absolute) ?: 0;
        // 重新编码后若反而变大（多为已经高度优化的图），保留较小结果由磁盘决定；此处仅如实上报。
        return [
            'before' => $before,
            'after' => $after,
            'width' => $width,
            'height' => $height,
            'changed' => $resized || $force || $after < $before,
            'reason' => $resized ? 'resized+reencoded' : 'reencoded',
        ];
    }

    /**
     * 是否属于「即便宽度未超限、也值得重压一次」的情况（用于批量治理历史大图）。
     */
    public static function needsRecompress(string $absolute, int $byteThreshold = 307200): bool
    {
        if (! is_file($absolute)) {
            return false;
        }
        if (filesize($absolute) <= $byteThreshold) {
            return false;
        }
        $type = self::typeOf($absolute);
        return in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_WEBP], true);
    }

    public static function typeOf(string $absolute): ?int
    {
        $info = @getimagesize($absolute);
        return $info === false ? null : ($info[2] ?? null);
    }

    /**
     * 为已落盘的 JPEG/PNG 生成同目录、同主名的 .webp 兄弟文件（幂等：webp 已新于原图则跳过）。
     * 原图永不删除、不改扩展名；webp 仅作为 <picture> 的首选源，缺失时前端自动回退原图。
     *
     * @return string|null 生成（或已存在且最新）的 webp 绝对路径；不支持/失败返回 null
     */
    public static function ensureWebp(string $absolute): ?string
    {
        if (! function_exists('imagewebp') || ! is_file($absolute)) {
            return null;
        }
        $type = self::typeOf($absolute);
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null; // 源为 webp/gif/avif/svg 等不重复生成
        }
        $webpPath = preg_replace('/\.[^.]+$/', '', $absolute).'.webp';
        if (is_file($webpPath) && filemtime($webpPath) >= filemtime($absolute)) {
            return $webpPath;
        }
        $img = self::decode($absolute, $type);
        if ($img === null) {
            return null;
        }
        $ok = self::writeWebp($img, $webpPath);
        imagedestroy($img);

        return ($ok && is_file($webpPath)) ? $webpPath : null;
    }

    /** 以统一质量把 GD 资源编码为保留 Alpha 的 WebP。 */
    private static function writeWebp($img, string $webpPath): bool
    {
        imagealphablending($img, false);
        imagesavealpha($img, true);

        return @imagewebp($img, $webpPath, self::WEBP_Q);
    }

    /**
     * 把同站 /storage/... 的 JPEG/PNG 图片 URL 映射到其 .webp 兄弟 URL；
     * 兄弟文件不存在（未生成/非图片/外链）时返回 null，调用方据此回退原图，绝不裂图。
     */
    public static function webpUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return null;
        }
        $rel = substr($path, strlen('/storage/'));
        if ($rel === false || ! preg_match('/\.(jpe?g|png)$/i', $rel)) {
            return null;
        }
        $webpRel = preg_replace('/\.[^.]+$/', '.webp', $rel);
        try {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($webpRel)) {
                return '/storage/'.ltrim($webpRel, '/');
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private static function decode(string $absolute, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : null,
            IMAGETYPE_PNG => self::fromPng($absolute),
            default => null, // GIF / AVIF / SVG / 其它：保留
        };
    }

    private static function fromPng(string $absolute)
    {
        $im = @imagecreatefrompng($absolute);
        if ($im === false) {
            return null;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        return $im;
    }

    private static function encode($img, string $absolute, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, $absolute, self::JPEG_Q),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($img, $absolute, self::WEBP_Q) : false,
            IMAGETYPE_PNG => imagepng($img, $absolute, 6),
            default => false,
        };
    }

    private static function applyExifOrientation($img, string $absolute)
    {
        try {
            $exif = @exif_read_data($absolute);
        } catch (\Throwable $e) {
            return $img;
        }
        $o = $exif['Orientation'] ?? 1;
        $rot = match ($o) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($rot !== 0) {
            $rotated = imagerotate($img, $rot, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                return $rotated;
            }
        }
        return $img;
    }
}
