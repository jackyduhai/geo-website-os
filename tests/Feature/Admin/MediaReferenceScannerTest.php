<?php

namespace Tests\Feature\Admin;

use App\Models\Banner;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Support\MediaReferenceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Media 引用反查守卫（Discovery §3.4 六路）。
 *
 * media 表无 DB FK，封面 / OG / Banner / 实体 metadata / 正文内联图 / 页面 SEO OG
 * 均为软引用；删除物理文件前必须靠本扫描器反查。ContentRevision 历史快照只告警。
 */
class MediaReferenceScannerTest extends TestCase
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

    private function makeContent(array $attrs = []): Content
    {
        return Content::create(array_merge([
            'site_id' => $this->site->id,
            'type'    => 'article',
            'title'   => '扫描器测试文章',
            'slug'    => 'scan-' . uniqid(),
            'status'  => 'draft',
            'locale'  => 'zh-CN',
            'body'    => '',
        ], $attrs));
    }

    public function test_cover_id_reference_is_detected(): void
    {
        $media = $this->makeMedia('cover/202609/cover-a.jpg');
        $this->makeContent(['title' => '封面归属文章', 'cover_id' => $media->id]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['field'] === 'cover_id'
        ));

        $this->assertCount(1, $hits);
        $this->assertSame(Content::class, $hits[0]['model']);
        $this->assertStringContainsString('封面归属文章', $hits[0]['label']);
        $this->assertTrue(MediaReferenceScanner::hasReferences($media));
    }

    public function test_og_image_id_reference_is_detected(): void
    {
        $media = $this->makeMedia('og/202609/og-a.jpg');
        $this->makeContent(['title' => 'OG 归属文章', 'og_image_id' => $media->id]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['field'] === 'og_image_id'
        ));

        $this->assertCount(1, $hits);
        $this->assertStringContainsString('OG 归属文章', $hits[0]['label']);
    }

    public function test_banner_image_id_reference_is_detected(): void
    {
        $media = $this->makeMedia('banner/202609/banner-a.jpg');
        Banner::create([
            'site_id'  => $this->site->id,
            'position' => 'home_top',
            'image_id' => $media->id,
        ]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === Banner::class
        ));

        $this->assertCount(1, $hits);
        $this->assertSame('image_id', $hits[0]['field']);
        $this->assertStringContainsString('home_top', $hits[0]['label']);
    }

    public function test_entity_metadata_json_int_reference_is_detected(): void
    {
        $media = $this->makeMedia('entity/202609/entity-a.jpg');
        Entity::create([
            'site_id'           => $this->site->id,
            'type'              => Entity::TYPE_PRODUCT,
            'slug'              => 'scan-prod-' . uniqid(),
            'name'              => '被引用实体',
            'status'            => Entity::STATUS_PUBLISHED,
            'locale'            => 'zh-CN',
            'metadata'          => ['media_id' => $media->id, 'og_image' => $media->id],
            'sort_order'        => 0,
            'translation_group' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $refs = MediaReferenceScanner::for($media);
        $mediaIdHit = collect($refs)->firstWhere('field', 'metadata->media_id');
        $ogHit = collect($refs)->firstWhere('field', 'metadata->og_image');

        $this->assertNotNull($mediaIdHit, 'metadata->media_id 应被检出');
        $this->assertNotNull($ogHit, 'metadata->og_image 应被检出');
        $this->assertStringContainsString('被引用实体', $mediaIdHit['label']);
    }

    public function test_body_inline_image_path_string_is_detected(): void
    {
        $media = $this->makeMedia('content/202609/inline-a.jpg');
        $this->makeContent([
            'title' => '内联图文章',
            'body'   => "正文第一段\n\n![图](/storage/content/202609/inline-a.jpg)\n\n结尾",
        ]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['field'] === 'body'
        ));

        $this->assertCount(1, $hits);
        $this->assertStringContainsString('内联图文章', $hits[0]['label']);
    }

    public function test_seo_meta_og_image_path_is_detected(): void
    {
        $media = $this->makeMedia('seo/202609/seo-a.jpg');
        SeoMeta::create([
            'site_id'       => $this->site->id,
            'locale'        => 'zh-CN',
            'og_image_path' => '/storage/seo/202609/seo-a.jpg',
        ]);

        $hits = array_values(array_filter(
            MediaReferenceScanner::for($media),
            fn ($r) => $r['model'] === SeoMeta::class
        ));

        $this->assertCount(1, $hits);
        $this->assertSame('og_image_path', $hits[0]['field']);
        $this->assertStringContainsString('SEO', $hits[0]['label']);
    }

    public function test_returns_empty_when_no_reference_exists(): void
    {
        $media = $this->makeMedia('orphan/202609/orphan-a.jpg');

        $this->assertSame([], MediaReferenceScanner::for($media));
        $this->assertFalse(MediaReferenceScanner::hasReferences($media));
        $this->assertSame([], MediaReferenceScanner::warnings($media));
    }

    public function test_content_revision_snapshot_only_warns_and_does_not_block(): void
    {
        $media = $this->makeMedia('revision/202609/rev-a.jpg');
        $content = $this->makeContent(['title' => '有历史版本的文章', 'body' => '当前正文已不再引用图片']);
        ContentRevision::create([
            'site_id'    => $this->site->id,
            'content_id' => $content->id,
            'note'       => '编辑前快照',
            'snapshot'   => [
                'title' => '有历史版本的文章',
                'body'  => "旧正文 ![](/storage/revision/202609/rev-a.jpg)",
            ],
        ]);

        $refs = MediaReferenceScanner::for($media);
        $warnings = MediaReferenceScanner::warnings($media);

        $this->assertCount(1, $refs);
        $this->assertTrue($refs[0]['warning'] ?? false, 'Revision 引用必须标记 warning');
        $this->assertSame(ContentRevision::class, $refs[0]['model']);
        $this->assertCount(1, $warnings);
        // warning 级引用不阻断删除
        $this->assertFalse(MediaReferenceScanner::hasReferences($media));
    }
}
