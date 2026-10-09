<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP-7 Media Delete Consistency：原文件 + WebP 衍生物统一清理；
 * 删除失败的 delete_failed_at 持久状态与幂等重试。
 */
class MediaDeleteConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
        $this->admin = User::firstOrFail();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    /** 落盘原文件 + WebP sibling，并建媒体记录。 */
    private function makeImageMedia(string $path, string $disk = 'public'): Media
    {
        Storage::disk($disk)->put($path, 'original-bytes');
        $webp = preg_replace('/\.[^.]+$/', '.webp', $path);
        Storage::disk($disk)->put($webp, 'webp-bytes');

        return Media::create([
            'disk' => $disk, 'path' => $path,
            'original_name' => basename($path), 'mime' => 'image/jpeg',
            'size' => 12345, 'width' => 800, 'height' => 600,
        ]);
    }

    /** 安装「WebP 删除失败」的磁盘：原文件真实删除，WebP 删除返回 false。 */
    private function installFailingWebpDisk(string $original, string $webp): object
    {
        $real = Storage::disk('public');
        $mock = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $mock->shouldReceive('exists')->with($original)->andReturn(true);
        $mock->shouldReceive('exists')->with($webp)->andReturn(true);
        $mock->shouldReceive('delete')->with($original)->andReturnUsing(fn () => $real->delete($original));
        $mock->shouldReceive('delete')->with($webp)->andReturn(false);
        Storage::set('public', $mock);

        return $real;
    }

    // ---------- 正常删除：原文件 + WebP 同生命周期 ----------

    public function test_normal_delete_removes_original_webp_and_record(): void
    {
        $path = 'media/202610/a.jpg';
        $media = $this->makeImageMedia($path);

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing('media/202610/a.webp');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    // ---------- WebP 删除失败：保留记录 + delete_failed_at + 审计 ----------

    public function test_webp_delete_failure_keeps_record_and_marks_failed(): void
    {
        $path = 'media/202610/b.jpg';
        $webp = 'media/202610/b.webp';
        $media = $this->makeImageMedia($path);
        $real = $this->installFailingWebpDisk($path, $webp);

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        // 恢复真实磁盘后断言文件状态
        Storage::set('public', $real);

        // 原文件已删，WebP 仍在；记录保留
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($webp);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertNotNull(Media::withoutSiteScope()->find($media->id)->delete_failed_at);

        // 审计 media.delete_failed
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'media.delete_failed', 'target_type' => 'media', 'target_id' => $media->id,
        ]);
    }

    // ---------- 重试：缺失文件幂等成功，收口 ----------

    public function test_retry_command_recovers_after_failure(): void
    {
        $path = 'media/202610/c.jpg';
        $webp = 'media/202610/c.webp';
        $media = $this->makeImageMedia($path);
        $real = $this->installFailingWebpDisk($path, $webp);

        // 首次失败
        $this->actingAs($this->admin)->delete(route('admin.media.destroy', $media))->assertRedirect();
        $this->assertNotNull(Media::withoutSiteScope()->find($media->id)->delete_failed_at);

        // 恢复真实磁盘，执行重试命令
        Storage::set('public', $real);
        $this->artisan('media:retry-deletes')->assertSuccessful();

        // 原文件本就缺失（幂等成功），WebP 已删，记录收口
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing($webp);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'media.delete_recovered', 'target_type' => 'media', 'target_id' => $media->id,
        ]);
    }

    // ---------- 文件已不存在：幂等成功 ----------

    public function test_missing_files_treated_as_idempotent_success(): void
    {
        $path = 'media/202610/d.jpg';
        $media = $this->makeImageMedia($path);
        // 预先把原文件与 WebP 都删掉（模拟已处置）
        Storage::disk('public')->delete([$path, 'media/202610/d.webp']);

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertStringStartsWith('媒体已删除', session('success') ?? '');
    }

    // ---------- 非图片：无 WebP 清理 ----------

    public function test_non_image_deletes_only_original(): void
    {
        $path = 'media/202610/e.pdf';
        Storage::disk('public')->put($path, 'pdf-bytes');
        $media = Media::create([
            'disk' => 'public', 'path' => $path,
            'original_name' => 'e.pdf', 'mime' => 'application/pdf', 'size' => 999,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    // ---------- 多磁盘：使用记录所属 disk ----------

    public function test_media_on_other_disk_uses_that_disk(): void
    {
        Storage::fake('s3');
        $path = 'media/202610/f.jpg';
        $media = $this->makeImageMedia($path, 's3');

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        Storage::disk('s3')->assertMissing($path);
        Storage::disk('s3')->assertMissing('media/202610/f.webp');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
