<?php

namespace Tests\Feature;

use App\Support\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CMD-02 — media:optimize 命令防回归（矩阵误记为 optimize:images）。
 * ------------------------------------------------------------------
 * ImageOptimizer 本身已由 ImageOptimizerTest 覆盖，但命令入口未被调用。本测试在
 * 受控的 storage/app/public/media 下创建真实图片，验证：
 *  - --dry-run 只报告不写盘；
 *  - 实际执行把超宽图片限宽到内容上限；
 *  - 无图片时命令安全退出。
 * 测试图片及生成的 webp 兄弟文件在 tearDown 中清理。
 */
class OptimizeImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaDir;

    /** @var array<int,string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD 不可用');
        }
        $this->mediaDir = storage_path('app/public/media');
        File::ensureDirectoryExists($this->mediaDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $p) {
            $siblings = [$p, preg_replace('/\.(jpe?g|png)$/i', '.webp', $p)];
            foreach (array_unique(array_filter($siblings)) as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        parent::tearDown();
    }

    private function makeJpeg(int $w, int $h, int $quality = 95): string
    {
        $path = $this->mediaDir.DIRECTORY_SEPARATOR.'cmd02-'.uniqid().'.jpg';
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorallocate($im, $x % 255, ($x * 2) % 255, ($x * 3) % 255);
            imageline($im, $x, 0, $x, $h, $c);
        }
        imagejpeg($im, $path, $quality);
        imagedestroy($im);
        $this->cleanup[] = $path;

        return $path;
    }

    public function test_dry_run_reports_oversized_image_without_writing(): void
    {
        $path = $this->makeJpeg(2400, 600);
        $before = filesize($path);

        $this->artisan('media:optimize', ['--dry-run' => true])
            ->expectsOutputToContain('扫描')
            ->assertExitCode(0);

        // dry-run 不写盘：文件字节数与尺寸保持原样。
        $this->assertSame($before, filesize($path));
        $this->assertSame(2400, getimagesize($path)[0]);
    }

    public function test_command_downscales_oversized_image(): void
    {
        $path = $this->makeJpeg(2400, 600);

        $this->artisan('media:optimize')->assertExitCode(0);

        // 实际执行：超宽图片被限宽到内容上限（MAXW_CONTENT）。
        $this->assertLessThanOrEqual(ImageOptimizer::MAXW_CONTENT, getimagesize($path)[0]);
    }

    public function test_command_succeeds_with_no_eligible_images(): void
    {
        $this->artisan('media:optimize')->assertExitCode(0);
    }
}
