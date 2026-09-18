<?php

namespace Tests\Unit;

use App\Support\ImageOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * v0.9.17 图片压缩/限宽回归：
 *  - 超宽 JPEG 被限宽到目标宽度且仍是合法图片
 *  - 未超限且体积不大的图片不重复重压（返回 null）
 *  - 非图片原样跳过（返回 null），不抛异常阻断上传
 */
class ImageOptimizerTest extends TestCase
{
    private function makeJpeg(int $w, int $h, int $quality = 95): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';
        $im = imagecreatetruecolor($w, $h);
        // 填充渐变噪声，避免纯色块重压后体积异常
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorallocate($im, $x % 255, ($x * 2) % 255, ($x * 3) % 255);
            imageline($im, $x, 0, $x, $h, $c);
        }
        imagejpeg($im, $path, $quality);
        imagedestroy($im);
        return $path;
    }

    public function test_oversized_jpeg_is_downscaled(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD 不可用');
        }
        $path = $this->makeJpeg(2400, 600);
        $res = ImageOptimizer::optimize($path, 1600);
        $this->assertNotNull($res);
        $this->assertSame(1600, $res['width']);
        $this->assertSame(400, $res['height']);
        $info = @getimagesize($path);
        $this->assertSame(1600, $info[0]);
        @unlink($path);
    }

    public function test_small_light_image_is_left_untouched(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD 不可用');
        }
        $path = $this->makeJpeg(800, 400, 80); // 小尺寸、较高压缩，体积小
        if (ImageOptimizer::needsRecompress($path)) {
            @unlink($path);
            $this->markTestSkipped('测试图意外超过重压阈值');
        }
        $this->assertNull(ImageOptimizer::optimize($path, 1600));
        @unlink($path);
    }

    public function test_non_image_is_passed_through(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'doc_') . '.pdf';
        file_put_contents($path, '%PDF-1.4 not an image');
        $this->assertNull(ImageOptimizer::optimize($path, 1600));
        $this->assertSame('%PDF-1.4 not an image', file_get_contents($path));
        @unlink($path);
    }

    public function test_ensure_webp_creates_sibling_for_jpeg_and_is_idempotent(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD 未启用 WebP');
        }
        $path = $this->makeJpeg(640, 360);
        $webp = ImageOptimizer::ensureWebp($path);
        $expected = preg_replace('/\.jpg$/', '.webp', $path);
        $this->assertSame($expected, $webp);
        $this->assertFileExists($webp);
        $this->assertSame(IMAGETYPE_WEBP, (@getimagesize($webp)[2] ?? null));

        // 幂等：webp 已新于原图时直接复用，不报错
        clearstatcache(true, $webp);
        touch($webp, time() + 10);
        $this->assertSame($expected, ImageOptimizer::ensureWebp($path));

        @unlink($path);
        @unlink($webp);
    }

    public function test_ensure_webp_skips_non_raster(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD 未启用 WebP');
        }
        $gif = tempnam(sys_get_temp_dir(), 'img_') . '.gif';
        $im = imagecreatetruecolor(10, 10);
        imagegif($im, $gif);
        imagedestroy($im);
        $this->assertNull(ImageOptimizer::ensureWebp($gif), 'GIF 不生成 webp 兄弟');
        $this->assertFileDoesNotExist(preg_replace('/\.gif$/', '.webp', $gif));
        @unlink($gif);
    }
}
