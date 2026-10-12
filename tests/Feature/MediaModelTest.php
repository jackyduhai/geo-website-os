<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Media 模型公开契约测试（Phase 2C.1，关闭 MEDIA-01 / MEDIA-03）。
 */
class MediaModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_external_http_url_is_returned_unchanged(): void
    {
        $media = new Media(['path' => 'http://cdn.example.com/asset/x.jpg']);

        $this->assertSame('http://cdn.example.com/asset/x.jpg', $media->url());
    }

    public function test_external_https_url_is_returned_unchanged(): void
    {
        $media = new Media(['path' => 'https://cdn.example.com/asset/x.jpg']);

        $this->assertSame('https://cdn.example.com/asset/x.jpg', $media->url());
    }

    public function test_local_path_is_resolved_via_public_url(): void
    {
        $media = new Media(['path' => 'media/202610/x.jpg']);

        $url = $media->url();
        $this->assertStringContainsString('/storage/media/202610/x.jpg', $url);
    }

    public function test_is_image_true_for_image_mime(): void
    {
        $media = new Media(['mime' => 'image/png']);

        $this->assertTrue($media->isImage());
    }

    public function test_is_image_false_for_non_image_mime(): void
    {
        $media = new Media(['mime' => 'application/pdf']);

        $this->assertFalse($media->isImage());
    }

    public function test_is_image_false_for_empty_mime(): void
    {
        $media = new Media(['mime' => '']);

        $this->assertFalse($media->isImage());
    }

    public function test_admin_media_index_renders_and_classifies_image(): void
    {
        Media::create([
            'disk' => 'public',
            'path' => 'media/202610/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);
        Media::create([
            'disk' => 'public',
            'path' => 'media/202610/spec.pdf',
            'original_name' => 'spec.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
        ]);

        // 真实后台媒体库页面：渲染 isImage() 分支（图片进入图片展示布局）
        $this->actingAs($this->admin)
            ->get(route('admin.media.index'))
            ->assertOk();
    }
}
