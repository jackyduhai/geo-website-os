<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Media;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P-STEP 20B 残留债务最小修复回归（第二层裁定授权，仅 RD-001 / RD-002）：
 *
 *  - RD-001：MediaReferenceScanner 补路7（Settings 图片字段 geo_org_logo /
 *    seo_og_image / contact_wechat_qr）。被设置引用的媒体删除必须拦截，解除后放行。
 *  - RD-002：ContentController::update 对「已发布内容把 published_at 改到未来」
 *    采用拒绝语义（与 publish() 一致），now/past 放行，草稿计划不拦截。
 *
 * RD-003（brand_display_name 单值不随语言）留 v1.1，本文件不覆盖。
 */
class ResidualDebt20BFixTest extends TestCase
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
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
        $this->actingAs($this->admin);
    }

    // ---------------------------------------------------------------
    // RD-001：Settings 图片字段引用守卫
    // ---------------------------------------------------------------

    private function makeStoredMedia(string $path): Media
    {
        Storage::disk('public')->put($path, 'fake-image-bytes');

        return Media::create([
            'disk'          => 'public',
            'path'          => $path,
            'original_name' => basename($path),
            'mime'          => 'image/png',
            'size'          => 1234,
            'width'         => 200,
            'height'        => 200,
        ]);
    }

    #[DataProvider('settingImageKeyProvider')]
    public function test_destroy_blocked_when_media_used_by_setting(string $key): void
    {
        $media = $this->makeStoredMedia('media/202609/rd-' . $key . '.png');
        Setting::set($key, $media->url()); // Media Picker 存的是完整 URL

        $response = $this->delete(route('admin.media.destroy', $media));

        $response->assertRedirect();
        $this->assertStringContainsString(
            '处引用，已拒绝删除',
            session('error') ?? '',
            '被设置引用的媒体必须拦截删除（RD-001）'
        );
        Storage::disk('public')->assertExists($media->path);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public static function settingImageKeyProvider(): array
    {
        return [
            'geo_org_logo'      => ['geo_org_logo'],
            'seo_og_image'      => ['seo_og_image'],
            'contact_wechat_qr' => ['contact_wechat_qr'],
        ];
    }

    public function test_destroy_succeeds_after_setting_reference_cleared(): void
    {
        $media = $this->makeStoredMedia('media/202609/rd-cleared.png');
        Setting::set('geo_org_logo', $media->url());

        $this->delete(route('admin.media.destroy', $media))->assertRedirect();
        $this->assertDatabaseHas('media', ['id' => $media->id]);

        Setting::set('geo_org_logo', '');

        $response = $this->delete(route('admin.media.destroy', $media));
        $response->assertRedirect();
        $this->assertStringStartsWith('媒体已删除', session('success') ?? '');
        Storage::disk('public')->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_destroy_unreferenced_media_still_succeeds(): void
    {
        $media = $this->makeStoredMedia('media/202609/rd-plain.png');

        $response = $this->delete(route('admin.media.destroy', $media));
        $response->assertRedirect();
        $this->assertStringStartsWith('媒体已删除', session('success') ?? '');
        Storage::disk('public')->assertMissing($media->path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    // ---------------------------------------------------------------
    // RD-002：Content update 未来时间状态约束
    // ---------------------------------------------------------------

    private function makePublishedArticle(string $slug): Content
    {
        return Content::create([
            'site_id'      => $this->site->id,
            'type'         => 'article',
            'slug'         => $slug,
            'title'        => 'RD测试文章',
            'status'       => 'published',
            'locale'       => 'zh-CN',
            'category_id'  => Category::where('slug', 'knowledge')->value('id'),
            'summary'      => '摘要',
            'body'         => '正文',
            'published_at' => now()->subDay(),
        ]);
    }

    private function updatePayload(Content $c, ?string $publishedAt): array
    {
        return [
            'type'         => 'article',
            'title'        => $c->title,
            'slug'         => $c->slug,
            'category_id' => $c->category_id,
            'summary'      => $c->summary,
            'body'         => $c->body,
            'published_at' => $publishedAt,
        ];
    }

    public function test_update_published_with_now_succeeds(): void
    {
        $c = $this->makePublishedArticle('rd-now-' . uniqid());

        $this->put(route('admin.contents.update', $c),
            $this->updatePayload($c, now()->format('Y-m-d H:i:s')))->assertRedirect();

        $this->assertSame('published', $c->fresh()->status);
        $this->get('/knowledge/' . $c->slug)->assertOk();
    }

    public function test_update_published_with_past_succeeds(): void
    {
        $c = $this->makePublishedArticle('rd-past-' . uniqid());

        $this->put(route('admin.contents.update', $c),
            $this->updatePayload($c, now()->subDays(3)->format('Y-m-d H:i:s')))->assertRedirect();

        $this->assertSame('published', $c->fresh()->status);
        $this->get('/knowledge/' . $c->slug)->assertOk();
    }

    public function test_update_published_with_future_is_rejected(): void
    {
        $c = $this->makePublishedArticle('rd-future-' . uniqid());

        $response = $this->put(route('admin.contents.update', $c),
            $this->updatePayload($c, now()->addDays(2)->format('Y-m-d H:i:s')));

        $response->assertRedirect();
        $this->assertStringContainsString(
            '不能把发布时间改到未来',
            session('error') ?? '',
            '已发布内容改未来时间必须拒绝（RD-002）'
        );

        $fresh = $c->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNotNull($fresh->published_at);
        $this->assertFalse($fresh->published_at->isFuture(), 'published_at 必须保持原值，不得改成未来');
        $this->get('/knowledge/' . $c->slug)->assertOk();
    }

    public function test_update_draft_with_future_is_allowed(): void
    {
        $c = Content::create([
            'site_id'     => $this->site->id,
            'type'        => 'article',
            'slug'        => 'rd-draft-future-' . uniqid(),
            'title'       => '草稿未来',
            'status'      => 'draft',
            'locale'      => 'zh-CN',
            'category_id' => Category::where('slug', 'knowledge')->value('id'),
            'body'        => 'x',
        ]);

        $this->put(route('admin.contents.update', $c),
            $this->updatePayload($c, now()->addDays(5)->format('Y-m-d H:i:s')))->assertRedirect();

        $fresh = $c->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertTrue($fresh->published_at->isFuture(), '草稿允许保存未来计划时间');
    }
}
