<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sync\GeoflowSync;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 必修② Unpublish/Expiration 缓存失效回归。
 *
 * 目标：published 内容经四条写路径手动下线后，PageCache（整页静态化，TTL 6h）
 * 不得继续返回旧页面。必须「下线即失效、下一请求按 PublicIndex/当前状态重判」。
 *
 * 四条写路径：
 *  (a) Content Admin unpublish（ContentController::unpublish → status=draft）
 *  (b) Entity  Admin unpublish（EntityController::unpublish → status=draft + published_at=null）
 *  (c) Page    Admin unpublish（PageController::publish(action=unpublish) → forgetPage）
 *  (d) GeoflowSync::unpublish（status=archived，API/CLI 上下文）
 *
 * 每条断言：先建 published 资源并访问前台详情页（200 + 含标记文案），走写路径下线后：
 *  - flush 型路径（a/b/d）：PageCache 全局版本号必须 +1（证明失效钩子真的触发）；
 *  - 前台下一请求必须按当前状态重判 → 404，且不再出现旧标记文案、不命中旧缓存。
 */
class UnpublishCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->site);
        Catalog::flush();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function makeCategory(string $slug): Category
    {
        return Category::create([
            'site_id'   => $this->site->id,
            'type'      => 'list',
            'slug'      => $slug,
            'name'      => '下架栏目 '.$slug,
            'is_active' => true,
        ]);
    }

    private function makePublishedContent(Category $cat, string $slug, string $marker, array $extra = []): Content
    {
        return Content::create(array_merge([
            'site_id'      => $this->site->id,
            'category_id'  => $cat->id,
            'type'         => 'article',
            'slug'         => $slug,
            'title'        => $marker,
            'body'         => '正文：'.$marker,
            'summary'      => '摘要：'.$marker,
            'status'       => 'published',
            'published_at' => now(),
            'locale'       => 'zh-CN',
        ], $extra));
    }

    /**
     * (a) Content Admin unpublish。Content::saved → 全局钩子 PageCache::flush()。
     */
    public function test_content_admin_unpublish_invalidates_page_cache(): void
    {
        $cat = $this->makeCategory('unpub-cat');
        $content = $this->makePublishedContent($cat, 'unpub-article', '下架文章标记 XYZPUB');
        $url = '/unpub-cat/unpub-article';

        // 下线前：前台详情页 200 且含旧标记
        $this->get($url)->assertOk()->assertSee('下架文章标记 XYZPUB');

        $v0 = PageCache::version();
        $this->actingAs($this->admin)
            ->post(route('admin.contents.unpublish', $content))->assertRedirect();

        // 失效钩子触发：全局版本号 +1
        $this->assertGreaterThan($v0, PageCache::version(), 'Content unpublish 后整页缓存版本应 +1');

        // 下线后：草稿不再公开 → 404，且不出现旧标记、不命中旧缓存
        $after = $this->get($url);
        $after->assertNotFound();
        $this->assertNotSame('HIT', $after->headers->get('X-Page-Cache'));
        $this->assertStringNotContainsString('下架文章标记 XYZPUB', $after->getContent());
    }

    /**
     * (b) Entity Admin unpublish。Entity::saved → 模型层 PageCache::flush()。
     */
    public function test_entity_admin_unpublish_invalidates_page_cache(): void
    {
        // 默认种子已含 organization（Catalog::company 非空）；补一个 core 产品实体。
        $entity = Entity::create([
            'site_id'      => $this->site->id,
            'type'         => Entity::TYPE_PRODUCT,
            'slug'         => 'unpub-prod',
            'name'         => '下架产品 XYZPUB',
            'summary'      => '下架产品摘要 XYZPUB',
            'status'       => 'published',
            'published_at' => now(),
            'locale'       => 'zh-CN',
            'metadata'     => ['core' => true],
        ]);
        $url = '/products/unpub-prod';

        $this->get($url)->assertOk()->assertSee('下架产品 XYZPUB');

        $v0 = PageCache::version();
        $this->actingAs($this->admin)
            ->post(route('admin.entities.unpublish', $entity))->assertRedirect();

        $this->assertGreaterThan($v0, PageCache::version(), 'Entity unpublish 后整页缓存版本应 +1');

        $after = $this->get($url);
        $after->assertNotFound();
        $this->assertNotSame('HIT', $after->headers->get('X-Page-Cache'));
    }

    /**
     * (c) Page Admin unpublish。Page 无 saved 钩子，仅靠 PageController 显式 forgetPage。
     */
    public function test_page_admin_unpublish_invalidates_page_cache(): void
    {
        $page = Page::create([
            'site_id'  => $this->site->id,
            'template' => 'landing',
            'slug'     => 'unpub-page',
            'title'    => '下架页面',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ]);
        PageBlock::create([
            'site_id'   => $this->site->id,
            'page'      => 'page',
            'page_id'   => $page->id,
            'slot'      => 'main',
            'type'      => 'hero',
            'sort'      => 0,
            'is_active' => true,
            'content'   => json_encode(['title' => '下架页面标记 XYZPUB'], JSON_UNESCAPED_UNICODE),
        ]);
        $url = '/unpub-page';

        $this->get($url)->assertOk()->assertSee('下架页面标记 XYZPUB');

        $this->actingAs($this->admin)
            ->post(route('admin.pages.publish', [$page, 'unpublish']))->assertRedirect();

        // forgetPage 为 path 级失效（全局版本号不变）；关键是下一请求按当前状态重判 → 404、不命中旧缓存
        $after = $this->get($url);
        $after->assertNotFound();
        $this->assertNotSame('HIT', $after->headers->get('X-Page-Cache'));
        $this->assertStringNotContainsString('下架页面标记 XYZPUB', $after->getContent());
    }

    /**
     * (d) GeoflowSync::unpublish（API/CLI 上下文，status=archived）。
     */
    public function test_geoflow_sync_unpublish_invalidates_page_cache(): void
    {
        // 20G-2·C-3：写开关关闭时 unpublish 也必须被拒绝（否则开关形同虚设，
        // 上游可在官网关闭对接后仍把内容下线）。因此本用例需显式打开开关。
        Setting::set('sync_geoflow_enabled', '1');

        $cat = $this->makeCategory('unpub-gf-cat');
        $content = $this->makePublishedContent($cat, 'unpub-geoflow', 'GeoFlow 下架标记 XYZPUB', [
            'external_id' => 'ext-unpub-123',
        ]);
        $url = '/unpub-gf-cat/unpub-geoflow';

        $this->get($url)->assertOk()->assertSee('GeoFlow 下架标记 XYZPUB');

        // API/CLI 上下文：按当前管理站点裁决缓存键（与前台 ResolveSite 落到同一 default 站）
        SiteContext::setSite($this->site);
        $v0 = PageCache::version();
        $result = app(GeoflowSync::class)->unpublish('ext-unpub-123');
        $this->assertTrue($result['ok']);

        // Content::update → saved → CacheInvalidationMap 登记 → PageCache::flush()
        $this->assertGreaterThan($v0, PageCache::version(), 'Geoflow unpublish 后整页缓存版本应 +1');

        $after = $this->get($url);
        $after->assertNotFound();
        $this->assertNotSame('HIT', $after->headers->get('X-Page-Cache'));
        $this->assertStringNotContainsString('GeoFlow 下架标记 XYZPUB', $after->getContent());
    }

    /**
     * 20G-2·C-3：写开关关闭时 unpublish 必须被拒绝，且**不得改动任何数据**。
     * 反向断言——只测「拒绝」不够，还要确认内容没被下线。
     */
    public function test_geoflow_sync_unpublish_is_blocked_when_switch_off(): void
    {
        Setting::set('sync_geoflow_enabled', '0');

        $cat = $this->makeCategory('unpub-off-cat');
        $content = $this->makePublishedContent($cat, 'unpub-off', '开关关闭标记 XOFF', [
            'external_id' => 'ext-off-123',
        ]);
        SiteContext::setSite($this->site);

        $result = app(GeoflowSync::class)->unpublish('ext-off-123');

        $this->assertFalse($result['ok'] ?? true, '写开关关闭时 unpublish 必须被拒绝');
        $this->assertSame(
            'published',
            Content::find($content->id)?->status,
            '被拒绝的 unpublish 不得改动内容状态'
        );
    }
}
