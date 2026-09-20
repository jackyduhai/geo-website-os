<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P-STEP 14 Bug Hunt：媒体上传安全。
 *
 * SVG 可内嵌 <script>/onload，经 public/storage 以 image/svg+xml 直出时会在浏览器
 * 执行，构成存储型 XSS；因此上传白名单不允许 svg（矢量图请用 PNG/WebP）。
 * 同时校验伪造扩展名的脚本文件无法绕过 mimes 内容校验。
 */
class MediaUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    private function svgUpload(string $name = 'evil.svg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg').'.svg';
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert("x")</script></svg>');

        return new UploadedFile($tmp, $name, 'image/svg+xml', null, true);
    }

    public function test_svg_upload_is_rejected_in_library(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/media', ['file' => $this->svgUpload(), 'alt' => 'x'])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
        Storage::disk('public')->assertDirectoryEmpty('media');
    }

    public function test_svg_upload_is_rejected_in_inline_editor(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/admin/media/inline', ['file' => $this->svgUpload(), 'alt' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Media::count());
    }

    public function test_php_upload_is_rejected_even_with_image_mime(): void
    {
        // 即使伪造客户端 MIME 为 image/png，.php 扩展名不在白名单，必须拒绝
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1; ?>', 'image/png');

        $this->actingAs($this->admin)
            ->post('/admin/media', ['file' => $php])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Media::count());
    }

    public function test_valid_png_still_uploads(): void
    {
        // 安全收紧不得误伤正常图片
        $png = UploadedFile::fake()->image('ok.png', 120, 80);

        $this->actingAs($this->admin)
            ->post('/admin/media', ['file' => $png, 'alt' => '正常图片'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, Media::count());
    }
}
