<?php

namespace Tests\Feature\Admin;

use App\Models\Content;
use App\Models\Media;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MediaController::destroy 引用守卫：被引用媒体拒绝物理删除并留审计；
 * 无引用媒体正常删文件 + 删记录。
 */
class MediaControllerTest extends TestCase
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

    private function makeStoredMedia(string $path): Media
    {
        Storage::disk('public')->put($path, 'fake-image-bytes');

        return Media::create([
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => basename($path),
            'mime'          => 'image/jpeg',
            'size'          => 12345,
            'width'         => 800,
            'height'        => 600,
        ]);
    }

    public function test_destroy_blocked_when_media_is_referenced(): void
    {
        $media = $this->makeStoredMedia('cover/202609/protected.jpg');
        Content::create([
            'site_id' => $this->site->id,
            'type'    => 'article',
            'title'   => '引用了该封面的文章',
            'slug'    => 'referenced-' . uniqid(),
            'status'  => 'draft',
            'locale'  => 'zh-CN',
            'body'    => '',
            'cover_id' => $media->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media));

        $response->assertRedirect();
        $this->assertStringContainsString(
            '处引用，已拒绝删除',
            session('error') ?? '',
            '闪存 error 必须说明拒绝原因与引用计数'
        );

        // 文件与 DB 行都必须保留
        Storage::disk('public')->assertExists($media->path);
        $this->assertDatabaseHas('media', ['id' => $media->id]);

        // 审计必须落 media.delete_blocked
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'media.delete_blocked',
            'target_type'  => 'media',
            'target_id'   => $media->id,
        ]);
    }

    public function test_destroy_succeeds_when_media_has_no_references(): void
    {
        $media = $this->makeStoredMedia('orphan/202609/orphan.jpg');

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media));

        $response->assertRedirect();
        $this->assertStringStartsWith('媒体已删除', session('success') ?? '');

        Storage::disk('public')->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_audit_log_records_blocked_references_summary(): void
    {
        $media = $this->makeStoredMedia('audit/202609/audit.jpg');
        Content::create([
            'site_id'  => $this->site->id,
            'type'     => 'article',
            'title'    => '审计封面文章',
            'slug'     => 'audit-' . uniqid(),
            'status'   => 'draft',
            'locale'   => 'zh-CN',
            'body'     => '',
            'cover_id' => $media->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.media.destroy', $media))
            ->assertRedirect();

        $log = \App\Models\AuditLog::where('action', 'media.delete_blocked')
            ->where('target_id', $media->id)
            ->firstOrFail();

        $detail = $log->detail ?? [];
        $this->assertSame(1, $detail['total'] ?? null);
        $this->assertStringContainsString('审计封面文章', $detail['refs'][0] ?? '');
        // 被拒后文件仍在
        Storage::disk('public')->assertExists($media->path);
    }
}
