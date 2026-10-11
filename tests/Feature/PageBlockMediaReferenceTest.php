<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\User;
use App\Support\MediaReferenceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Scanner 第 8 路（B3）：PageBlock 组合块媒体引用删除保护。
 *
 * 验证：
 *  - 声明式 media 字段（顶层 / items 嵌套）确定性提取；
 *  - inactive 块（持有态）仍受保护；
 *  - 未注册 / 契约外残留递归兜底；
 *  - 跨站隔离；
 *  - 无引用时不命中。
 */
class PageBlockMediaReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
    }

    private function makeMedia(string $path): Media
    {
        return Media::create([
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => basename($path),
            'mime'          => 'image/jpeg',
            'size'          => 12345,
            'width'         => 800,
            'height'        => 600,
            'alt'           => 'fixture',
        ]);
    }

    private function makeBlock(string $type, array $cfg, bool $active = true, ?int $siteId = null): PageBlock
    {
        return PageBlock::create([
            'site_id'   => $siteId ?? $this->site->id,
            'page'      => 'home',
            'page_id'   => null,
            'slot'      => 'main',
            'type'      => $type,
            'sort'      => 0,
            'is_active' => $active,
            'content'   => json_encode($cfg, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function test_top_level_media_field_is_detected(): void
    {
        $media = $this->makeMedia('media/202610/hero-a.jpg');
        $this->makeBlock('hero', ['variant' => 'split', 'image_id' => $media->id]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class && $r['field'] === 'image_id'
        ));

        $this->assertCount(1, $hits);
        $this->assertStringContainsString('Hero', $hits[0]['label']);
        $this->assertTrue(MediaReferenceScanner::hasReferences($media));
    }

    public function test_nested_items_media_field_is_detected(): void
    {
        $media = $this->makeMedia('media/202610/logo-a.jpg');
        $this->makeBlock('logo_cloud', [
            'title' => '合作伙伴',
            'items' => [
                ['media_id' => $media->id, 'name' => '客户 A'],
                ['media_id' => null, 'name' => '客户 B'],
            ],
        ]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertCount(1, $hits);
        $this->assertSame('items.0.media_id', $hits[0]['field']);
        $this->assertStringContainsString('Logo 墙', $hits[0]['label']);
    }

    public function test_inactive_block_media_is_still_protected(): void
    {
        $media = $this->makeMedia('media/202610/inactive-a.jpg');
        $this->makeBlock('image', ['media_id' => $media->id], active: false);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertCount(1, $hits);
        $this->assertStringContainsString('未启用', $hits[0]['label']);
        $this->assertTrue(MediaReferenceScanner::hasReferences($media));
    }

    public function test_unregistered_block_type_is_caught_by_fallback(): void
    {
        $media = $this->makeMedia('media/202610/legacy-a.jpg');
        $this->makeBlock('legacy_removed_type', ['some_picker' => $media->id]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertCount(1, $hits);
        $this->assertSame('content', $hits[0]['field']);
        $this->assertStringContainsString('未注册', $hits[0]['label']);
        $this->assertTrue(MediaReferenceScanner::hasReferences($media));
    }

    public function test_residual_id_outside_schema_is_caught(): void
    {
        $media = $this->makeMedia('media/202610/residual-a.jpg');
        // hero schema 中无 'old_picker' 字段——属契约外残留。
        $this->makeBlock('hero', ['variant' => 'split', 'old_picker' => $media->id]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertCount(1, $hits);
        $this->assertSame('content', $hits[0]['field']);
        $this->assertStringContainsString('契约外残留', $hits[0]['label']);
    }

    public function test_no_pageblock_hit_when_unreferenced(): void
    {
        $media = $this->makeMedia('media/202610/unused-a.jpg');
        $this->makeBlock('hero', ['variant' => 'split', 'image_id' => null]);
        $this->makeBlock('logo_cloud', ['items' => [['media_id' => 999999, 'name' => 'X']]]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertSame([], $hits);
        $this->assertFalse(MediaReferenceScanner::hasReferences($media));
    }

    public function test_pageblock_media_is_site_isolated(): void
    {
        $media = $this->makeMedia('media/202610/cross-a.jpg');

        $otherSite = Site::create([
            'slug'   => 'other-site',
            'name'   => 'Other Site',
            'status' => Site::STATUS_ACTIVE,
        ]);
        $this->makeBlock('hero', ['image_id' => $media->id], siteId: $otherSite->id);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === PageBlock::class
        ));

        $this->assertSame([], $hits);
        $this->assertFalse(MediaReferenceScanner::hasReferences($media));
    }

    public function test_actual_delete_route_is_blocked_by_pageblock_reference(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('hero/202610/hero-e2e.jpg');
        $this->makeBlock('hero', ['image_id' => $media->id]);

        $admin = User::firstOrFail();

        $response = $this->actingAs($admin)
            ->delete(route('admin.media.destroy', $media));

        // 门禁：阻止删除并回退，不删文件 / 不删 DB 行。
        $response->assertRedirect();
        $this->assertStringContainsString('已拒绝删除', session('error') ?? '');
        $this->assertDatabaseHas('media', ['id' => $media->id]);

        // 审计落 media.delete_blocked。
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'media.delete_blocked',
            'target_type' => 'media',
            'target_id'   => $media->id,
        ]);
    }
}
