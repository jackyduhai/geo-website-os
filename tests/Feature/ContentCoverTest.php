<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 内容（知识/新闻）封面图：后台直接上传 → 入媒体库并写 cover_id；勾选移除可清空。
 */
class ContentCoverTest extends TestCase
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

    private function payload(array $override = []): array
    {
        return array_merge([
            'type' => 'article',
            'title' => '封面测试文章',
            'slug' => 'cover-test-article',
            'summary' => '用于验证封面上传链路。',
            'body' => '正文。',
        ], $override);
    }

    public function test_uploading_cover_creates_media_and_links_content(): void
    {
        $this->actingAs($this->admin)->post('/admin/contents', $this->payload([
            'cover_file' => UploadedFile::fake()->image('cover.png', 640, 360),
        ]))->assertRedirect();

        $content = Content::with('cover')->where('slug', 'cover-test-article')->firstOrFail();
        $this->assertNotNull($content->cover_id);
        $this->assertSame('image/png', $content->cover->mime);
        $this->assertStringContainsString('storage/covers', $content->cover->url());
    }

    public function test_cover_remove_clears_existing_cover(): void
    {
        $this->actingAs($this->admin)->post('/admin/contents', $this->payload([
            'cover_file' => UploadedFile::fake()->image('cover.png'),
        ]));
        $content = Content::where('slug', 'cover-test-article')->firstOrFail();
        $this->assertNotNull($content->cover_id);

        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->payload([
            'cover_remove' => 1,
        ]))->assertRedirect();
        $this->assertNull($content->fresh()->cover_id);
    }

    /**
     * 安全（UAT Bug#4）：内容封面禁止 SVG，与媒体库/内联上传策略一致。
     * SVG 可内嵌 <script>，经 /storage 以 image/svg+xml 直出构成存储型 XSS。
     */
    public function test_cover_svg_is_rejected_to_prevent_stored_xss(): void
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<script>alert("xss")</script></svg>';

        $this->actingAs($this->admin)
            ->post('/admin/contents', $this->payload([
                'slug'       => 'cover-svg-rejected',
                'cover_file' => UploadedFile::fake()->createWithContent('evil.svg', $svg),
            ]))
            ->assertSessionHasErrors('cover_file');

        // 校验失败：内容不得创建，磁盘不得写入封面。
        $this->assertSame(0, Content::where('slug', 'cover-svg-rejected')->count());
        Storage::disk('public')->assertDirectoryEmpty('covers');
    }
}
