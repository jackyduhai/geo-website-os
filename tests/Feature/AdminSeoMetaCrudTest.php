<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Models\User;
use App\Services\Seo\SeoMetaResolver;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 17D：SEO 覆盖（SeoMeta）后台管理 + 显式/解析双显 + 端到端回归。
 *
 * 全部走后台 HTTP / 前台 HTTP，与 SeoMetaResolverTest（纯解析链）、
 * SeoHttpIntegrationTest（直接写模型）互补：
 * - 三作用域（站点级 / 内容级 / 实体级）CRUD，绑定 CHECK 与三枚 partial unique
 *   在表单层给出友好错误（非 SQLite 异常白屏 / 非 500）；
 * - 绑定对象必须本站（Rule::exists + site_id），跨站路由模型绑定 404；
 * - 解析预览直接由 SeoMetaResolver 计算，后台不自造 fallback / 不拼 canonical；
 * - 显式覆盖经后台保存后，前台 title/description/canonical/og:image/robots 生效；
 * - og:image 媒体库选择写 /storage/{path} 站内公开路径（冻结契约：前台原样输出）；
 * - SeoMeta 保存触发整页静态壳失效（AppServiceProvider 模型事件 → PageCache::flush）；
 * - Content 与 Entity 为平行资源：一条覆盖不能同时绑定二者。
 */
class AdminSeoMetaCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected User $plain;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        PageCache::flush();

        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->plain = User::create([
            'name' => '普通站点管理员',
            'email' => 'plain-seo@example.test',
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
        ]);
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        // 清空内置演示可能产生的 SEO 覆盖，使站点级唯一性 / 计数从空集开始
        //（admin 用户与 default 站点、演示实体 / 内容保留；用唯一 slug 避免冲突）。
        SeoMeta::withoutSiteScope()->delete();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function makeSiteB(): Site
    {
        return Site::create([
            'name' => 'Site B SEO', 'slug' => 'site-b-seo', 'domain' => 'bseo.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    private function switchTo(User $user, Site $site): void
    {
        $this->actingAs($user)->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();
    }

    private function makeCategory(Site $site, string $slug = 'news'): Category
    {
        // demo seeder 可能已为该站播种同 slug 栏目（categories(site_id,slug) 唯一），
        // 存在则复用，避免与内置 Example 数据冲突。
        $existing = Category::withoutSiteScope()
            ->where('site_id', $site->id)->where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        return SiteContext::withSite($site, function () use ($site, $slug) {
            return Category::create([
                'site_id' => $site->id,
                'type' => 'list',
                'slug' => $slug,
                'name' => ucfirst($slug),
                'is_active' => true,
            ]);
        });
    }

    private function makeContent(Site $site, string $slug, string $title, array $attrs = []): Content
    {
        $category = $this->makeCategory($site);

        return SiteContext::withSite($site, function () use ($site, $category, $slug, $title, $attrs) {
            return Content::create(array_merge([
                'site_id' => $site->id,
                'category_id' => $category->id,
                'type' => 'article',
                'slug' => $slug,
                'title' => $title,
                'summary' => $title.' 摘要',
                'status' => 'published',
                'published_at' => now(),
            ], $attrs));
        });
    }

    private function makeEntity(Site $site, string $type, string $slug, string $name, string $status = 'published'): Entity
    {
        return SiteContext::withSite($site, function () use ($site, $type, $slug, $name, $status) {
            return Entity::create([
                'site_id' => $site->id,
                'type' => $type,
                'slug' => $slug,
                'name' => $name,
                'summary' => $name,
                'status' => $status,
                'sort_order' => 0,
                'published_at' => $status === Entity::STATUS_PUBLISHED ? now() : null,
                'metadata' => ['core' => true],
            ]);
        });
    }

    private function makeMedia(Site $site, string $path): Media
    {
        return Media::create([
            'site_id' => $site->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => basename($path),
            'mime' => 'image/jpeg',
            'size' => 12345,
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'scope' => 'site',
            'title' => '',
            'description' => '',
            'keywords_text' => '',
            'canonical' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_path' => '',
            'og_media_id' => '',
            'og_type' => 'website',
            'twitter_card' => 'summary_large_image',
            'robots_text' => '',
            'schema_type' => '',
            'metadata_text' => '',
        ], $overrides);
    }

    private function siteLevelCount(Site $site): int
    {
        return SeoMeta::withoutSiteScope()
            ->where('site_id', $site->id)
            ->whereNull('content_id')->whereNull('entity_id')
            ->count();
    }

    // ---------------------------------------------------------------
    // 1. 访问控制
    // ---------------------------------------------------------------

    public function test_guest_is_redirected_from_seo_admin(): void
    {
        $this->get(route('admin.seo-metas.index'))->assertRedirect();
        $this->get(route('admin.seo-metas.create', ['scope' => 'site']))->assertRedirect();
        $this->post(route('admin.seo-metas.store'), $this->payload())->assertRedirect();
    }

    public function test_plain_site_admin_can_open_seo_override_list(): void
    {
        // SEO 覆盖不是超管专属（与站点管理相区别），普通站点管理员可访问。
        $this->actingAs($this->plain)
            ->get(route('admin.seo-metas.index'))
            ->assertOk()
            ->assertSee('SEO 覆盖');
    }

    // ---------------------------------------------------------------
    // 2. 列表与作用域筛选
    // ---------------------------------------------------------------

    public function test_index_renders_all_scope_tabs(): void
    {
        $u = $this->actingAs($this->super);
        foreach (['all', 'site', 'content', 'entity'] as $scope) {
            $u->get(route('admin.seo-metas.index', ['scope' => $scope]))->assertOk();
        }
    }

    public function test_index_lists_existing_override(): void
    {
        SeoMeta::create([
            'site_id' => $this->default->id,
            'title' => 'LIST-UNIQUE-TITLE-731',
            'og_type' => 'website',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.index'))
            ->assertOk()
            ->assertSee('LIST-UNIQUE-TITLE-731');
    }

    // ---------------------------------------------------------------
    // 3. 站点级覆盖
    // ---------------------------------------------------------------

    public function test_create_site_form_shows_live_resolver_preview(): void
    {
        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'site']))
            ->assertOk()
            ->assertSee('当前解析结果')
            ->assertSee('由 SeoMetaResolver');
    }

    public function test_store_site_level_override_persists_all_fields(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'title' => '站点覆盖标题',
                'description' => '站点覆盖描述',
                'keywords_text' => "alpha, beta，alpha\ngamma",
                'canonical' => 'https://example.com/',
                'og_title' => 'OG 站点标题',
                'og_description' => 'OG 站点描述',
                'og_image_path' => '/uploads/site-og.jpg',
                'og_type' => 'website',
                'twitter_card' => 'summary',
                'nofollow' => '1',
                'robots_text' => 'noarchive, noimageindex',
                'schema_type' => 'WebSite',
                'metadata_text' => '{"alternate_name":"别名"}',
            ]))
            ->assertRedirect();

        $this->assertSame(1, $this->siteLevelCount($this->default));
        $seo = SeoMeta::withoutSiteScope()
            ->where('site_id', $this->default->id)->whereNull('content_id')->whereNull('entity_id')
            ->firstOrFail();

        $this->assertNull($seo->content_id);
        $this->assertNull($seo->entity_id);
        $this->assertSame('站点覆盖标题', $seo->title);
        $this->assertSame('站点覆盖描述', $seo->description);
        $this->assertSame(['alpha', 'beta', 'gamma'], $seo->keywords); // 去重 + 中文逗号 / 换行
        $this->assertSame('https://example.com/', $seo->canonical);
        $this->assertSame('/uploads/site-og.jpg', $seo->og_image_path);
        $this->assertSame('website', $seo->og_type);
        $this->assertSame('summary', $seo->twitter_card);
        $this->assertFalse($seo->noindex);   // 未勾选 = 明确允许
        $this->assertTrue($seo->nofollow);
        $this->assertSame(['noarchive', 'noimageindex'], $seo->robots);
        $this->assertSame('WebSite', $seo->schema_type);
        $this->assertSame(['alternate_name' => '别名'], $seo->metadata);
    }

    public function test_site_level_override_is_unique_per_site(): void
    {
        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'title' => '第一条站点覆盖',
        ]))->assertRedirect();

        // 第二条站点级覆盖必须被表单层友好拦截，而不是触发 SQLite 唯一异常 500。
        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'title' => '第二条站点覆盖',
        ]))->assertSessionHasErrors('scope');

        $this->assertSame(1, $this->siteLevelCount($this->default));
    }

    public function test_create_site_redirects_to_edit_when_already_exists(): void
    {
        $seo = SeoMeta::create([
            'site_id' => $this->default->id,
            'title' => '已存在站点覆盖',
            'og_type' => 'website',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'site']))
            ->assertRedirect(route('admin.seo-metas.edit', $seo));
    }

    public function test_update_and_delete_site_level_override(): void
    {
        $seo = SeoMeta::create([
            'site_id' => $this->default->id,
            'title' => '旧标题',
            'og_type' => 'website',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $this->actingAs($this->super)
            ->put(route('admin.seo-metas.update', $seo), $this->payload(['title' => '新标题']))
            ->assertRedirect();
        $this->assertSame('新标题', $seo->fresh()->title);

        $this->actingAs($this->super)
            ->delete(route('admin.seo-metas.destroy', $seo))
            ->assertRedirect();
        $this->assertSame(0, $this->siteLevelCount($this->default));
    }

    // ---------------------------------------------------------------
    // 4. 内容级覆盖
    // ---------------------------------------------------------------

    public function test_create_content_without_target_shows_picker(): void
    {
        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'content']))
            ->assertOk()
            ->assertSee('选择要覆盖 SEO 的内容');
    }

    public function test_create_content_with_target_shows_form_and_live_preview(): void
    {
        $content = $this->makeContent($this->default, 'picker-article', 'Picker 文章');

        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'content', 'content_id' => $content->id]))
            ->assertOk()
            ->assertSee('Picker 文章')
            ->assertSee('当前解析结果');
    }

    public function test_resolver_preview_matches_resolver_for_content(): void
    {
        $content = $this->makeContent($this->default, 'preview-article', 'Preview 文章');
        $expected = app(SeoMetaResolver::class)->resolveContent($content);

        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'content', 'content_id' => $content->id]))
            ->assertOk()
            ->assertSee($expected->canonical);
    }

    public function test_store_content_override_binds_only_content(): void
    {
        $content = $this->makeContent($this->default, 'bind-article', 'Bind 文章');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'content',
                'content_id' => $content->id,
                'title' => '内容覆盖标题',
            ]))
            ->assertRedirect();

        $seo = SeoMeta::withoutSiteScope()->where('content_id', $content->id)->firstOrFail();
        $this->assertSame($this->default->id, $seo->site_id);
        $this->assertNull($seo->entity_id);
        $this->assertTrue($seo->isContentLevel());
    }

    public function test_content_override_is_unique_per_content(): void
    {
        $content = $this->makeContent($this->default, 'uniq-article', 'Uniq 文章');

        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'scope' => 'content', 'content_id' => $content->id, 'title' => '第一条',
        ]))->assertRedirect();

        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'scope' => 'content', 'content_id' => $content->id, 'title' => '第二条',
        ]))->assertSessionHasErrors('scope');

        $this->assertSame(1, SeoMeta::withoutSiteScope()->where('content_id', $content->id)->count());
    }

    public function test_cross_site_content_id_is_rejected(): void
    {
        $siteB = $this->makeSiteB();
        $bContent = $this->makeContent($siteB, 'b-only-article', 'B站内容');

        // 当前管理 default 站，却提交 B 站内容 id：Rule::exists(...where site_id) 必须拒绝。
        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'content',
                'content_id' => $bContent->id,
                'title' => '越权覆盖',
            ]))
            ->assertSessionHasErrors('content_id');

        $this->assertSame(0, SeoMeta::withoutSiteScope()->where('content_id', $bContent->id)->count());
    }

    // ---------------------------------------------------------------
    // 5. 实体级覆盖 + OG 媒体选择
    // ---------------------------------------------------------------

    public function test_create_entity_with_target_shows_form(): void
    {
        $entity = $this->makeEntity($this->default, 'product', 'preview-product', 'Preview 产品');

        $this->actingAs($this->super)
            ->get(route('admin.seo-metas.create', ['scope' => 'entity', 'entity_id' => $entity->id]))
            ->assertOk()
            ->assertSee('Preview 产品')
            ->assertSee('当前解析结果');
    }

    public function test_store_entity_override_and_media_og_path(): void
    {
        $entity = $this->makeEntity($this->default, 'product', 'og-product', 'OG 产品');
        $media = $this->makeMedia($this->default, 'covers/entity-og.jpg');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'entity',
                'entity_id' => $entity->id,
                'title' => '实体覆盖标题',
                'og_media_id' => $media->id,
                'og_image_path' => '',
            ]))
            ->assertRedirect();

        $seo = SeoMeta::withoutSiteScope()->where('entity_id', $entity->id)->firstOrFail();
        $this->assertNull($seo->content_id);
        $this->assertTrue($seo->isEntityLevel());
        // 媒体库选择换算为站内公开相对路径（冻结契约：前台原样输出，host 绝对化归 #86）。
        $this->assertSame('/storage/covers/entity-og.jpg', $seo->og_image_path);
    }

    public function test_entity_override_is_unique_per_entity(): void
    {
        $entity = $this->makeEntity($this->default, 'service', 'uniq-service', 'Uniq 服务');

        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'scope' => 'entity', 'entity_id' => $entity->id, 'title' => '第一条',
        ]))->assertRedirect();

        $this->actingAs($this->super)->post(route('admin.seo-metas.store'), $this->payload([
            'scope' => 'entity', 'entity_id' => $entity->id, 'title' => '第二条',
        ]))->assertSessionHasErrors('scope');

        $this->assertSame(1, SeoMeta::withoutSiteScope()->where('entity_id', $entity->id)->count());
    }

    // ---------------------------------------------------------------
    // 6. 绑定 CHECK（Content / Entity 平行资源，不得同时绑定）
    // ---------------------------------------------------------------

    public function test_cannot_bind_content_and_entity_together(): void
    {
        $content = $this->makeContent($this->default, 'pair-article', 'Pair 文章');
        $entity = $this->makeEntity($this->default, 'product', 'pair-product', 'Pair 产品');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'content',
                'content_id' => $content->id,
                'entity_id' => $entity->id,
                'title' => '非法双绑定',
            ]))
            ->assertSessionHasErrors('content_id');

        $this->assertSame(0, SeoMeta::withoutSiteScope()->count());
    }

    // ---------------------------------------------------------------
    // 7. 字段校验
    // ---------------------------------------------------------------

    public function test_invalid_canonical_url_is_rejected(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'title' => 'X', 'canonical' => 'not-a-url',
            ]))
            ->assertSessionHasErrors('canonical');

        $this->assertSame(0, SeoMeta::withoutSiteScope()->count());
    }

    public function test_invalid_metadata_json_is_rejected(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'title' => 'X', 'metadata_text' => '{broken json',
            ]))
            ->assertSessionHasErrors('metadata_text');

        $this->assertSame(0, SeoMeta::withoutSiteScope()->count());
    }

    // ---------------------------------------------------------------
    // 8. 多站隔离：跨站路由模型绑定 404 + 列表隔离
    // ---------------------------------------------------------------

    public function test_cross_site_override_edit_returns_404_and_list_is_isolated(): void
    {
        $aContent = $this->makeContent($this->default, 'a-only-seo', 'AONLY-TITLE-917');
        $aSeo = SeoMeta::create([
            'site_id' => $this->default->id,
            'content_id' => $aContent->id,
            'title' => 'AONLY-TITLE-917',
            'og_type' => 'article',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $siteB = $this->makeSiteB();
        $this->switchTo($this->super, $siteB);

        // B 站上下文按 id 打开 A 站覆盖：模型绑定经 SiteScope 必须 404。
        $this->get(route('admin.seo-metas.edit', $aSeo))->assertNotFound();

        // B 站内容级列表不得看到 A 站覆盖标题。
        $this->get(route('admin.seo-metas.index', ['scope' => 'content']))
            ->assertOk()
            ->assertDontSee('AONLY-TITLE-917');
    }

    // ---------------------------------------------------------------
    // 9. 端到端：后台保存 → 前台 HTML 生效
    // ---------------------------------------------------------------

    public function test_content_override_takes_effect_on_frontend_html(): void
    {
        $content = $this->makeContent($this->default, 'e2e-article', 'E2E 原标题');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'content',
                'content_id' => $content->id,
                'title' => 'E2E 自定义标题',
                'description' => 'E2E 自定义描述',
                'canonical' => 'https://example.com/custom-e2e',
                'og_title' => 'E2E OG 标题',
                'og_description' => 'E2E OG 描述',
                'og_image_path' => '/uploads/e2e-og.jpg',
                'og_type' => 'article',
            ]))
            ->assertRedirect();

        // 登录态请求对整页缓存 BYPASS，但仍动态走 Resolver，显式值必须呈现。
        $html = $this->get('/news/e2e-article')->assertOk()->getContent();

        $this->assertStringContainsString('<title>E2E 自定义标题', $html);
        $this->assertStringContainsString('<meta name="description" content="E2E 自定义描述">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/custom-e2e">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="E2E OG 标题">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="E2E OG 描述">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="/uploads/e2e-og.jpg">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
    }

    public function test_noindex_override_renders_noindex_robots(): void
    {
        $content = $this->makeContent($this->default, 'noindex-article', 'Noindex 文章');

        $this->actingAs($this->super)
            ->post(route('admin.seo-metas.store'), $this->payload([
                'scope' => 'content',
                'content_id' => $content->id,
                'noindex' => '1',
            ]))
            ->assertRedirect();

        $html = $this->get('/news/noindex-article')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
    }

    public function test_deleting_override_restores_inherited_frontend_values(): void
    {
        $content = $this->makeContent($this->default, 'inherit-article', 'Inherit 原标题', [
            'summary' => 'Inherit 原摘要',
        ]);
        $seo = SeoMeta::create([
            'site_id' => $this->default->id,
            'content_id' => $content->id,
            'title' => '临时覆盖标题',
            'description' => '临时覆盖描述',
            'og_type' => 'article',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $this->actingAs($this->super)->delete(route('admin.seo-metas.destroy', $seo))->assertRedirect();

        // 删除覆盖后，前台回到内容自身字段（继承链），不再显示显式覆盖。
        $html = $this->get('/news/inherit-article')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Inherit 原标题', $html);
        $this->assertStringContainsString('<meta name="description" content="Inherit 原摘要">', $html);
        $this->assertStringNotContainsString('临时覆盖标题', $html);
    }

    // ---------------------------------------------------------------
    // 10. 整页缓存联动：SeoMeta 保存即作废静态壳
    // ---------------------------------------------------------------

    public function test_saving_seo_meta_invalidates_cached_page(): void
    {
        $content = $this->makeContent($this->default, 'cache-article', 'CACHE 旧标题');

        // 匿名首访 MISS 落盘，第二访 HIT 命中旧壳。
        $first = $this->get('/news/cache-article')->assertOk();
        $this->assertSame('MISS', $first->headers->get('X-Page-Cache'));
        $second = $this->get('/news/cache-article')->assertOk();
        $this->assertSame('HIT', $second->headers->get('X-Page-Cache'));
        $this->assertStringContainsString('CACHE 旧标题', $second->getContent());

        // 保存显式 SEO 覆盖（模型 saved 事件 → PageCache::flush）。
        SeoMeta::create([
            'site_id' => $this->default->id,
            'content_id' => $content->id,
            'title' => 'CACHE 新标题',
            'og_type' => 'article',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        // 静态壳已整体作废：再次匿名访问必须 MISS（不得 HIT 旧壳）且 head 呈现新标题。
        $third = $this->get('/news/cache-article')->assertOk();
        $this->assertSame('MISS', $third->headers->get('X-Page-Cache'));
        $this->assertStringContainsString('<title>CACHE 新标题', $third->getContent());
        // SeoMeta.title 只接管 head 的 <title>，不改正文 h1（内容标题保持 Content.title）。
        $this->assertStringNotContainsString('<title>CACHE 旧标题', $third->getContent());
    }

    // ---------------------------------------------------------------
    // 11. 外键生命周期：删除内容级联清除其 SEO 覆盖
    // ---------------------------------------------------------------

    public function test_deleting_content_cascades_its_seo_meta(): void
    {
        $content = $this->makeContent($this->default, 'cascade-article', 'Cascade 文章');
        $seo = SeoMeta::create([
            'site_id' => $this->default->id,
            'content_id' => $content->id,
            'title' => '将随内容级联',
            'og_type' => 'article',
            'twitter_card' => 'summary_large_image',
            'noindex' => false,
            'nofollow' => false,
        ]);

        $this->assertDatabaseHas('seo_metas', ['id' => $seo->id]);

        // 软删（移入回收站）必须保留 SEO 覆盖，以便恢复内容时 SEO 设置不丢失。
        $content->delete();
        $this->assertDatabaseHas('seo_metas', ['id' => $seo->id]);

        // 永久删除物理行后，外键 ON DELETE CASCADE 清除其 SEO 覆盖。
        $content->forceDelete();
        $this->assertDatabaseMissing('seo_metas', ['id' => $seo->id]);
    }
}
