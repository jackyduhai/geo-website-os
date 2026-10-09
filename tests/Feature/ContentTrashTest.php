<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Fact;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-5 Content Trash：回收站三操作 + 三面一致性 + 无副作用。
 *
 * 覆盖：
 *  - 软删隔离：已删内容不出现在正常列表 / 公开人类面 / 机器面；
 *  - 回收站列表：仅展示已软删内容；
 *  - 恢复：内容与整组翻译无损恢复；公共资格按 CURRENT 重算（不回放快照）；
 *  - 永久删除：内容与自有关联清理完整，外部 Fact / Entity / Media 不变。
 */
class ContentTrashTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::create([
            'name' => 'Trash Site', 'slug' => 'trash-site',
            'domain' => 'trash.test', 'status' => 'active', 'is_default' => false,
        ]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.sites.switch'), ['site_id' => $this->site->id])
            ->assertRedirect();
        SiteContext::setSite($this->site);
        Catalog::flush();
    }

    /** 建一份满足门禁的内容并发布（返回已发布锚点）。 */
    private function publishContent(string $slug, array $extra = []): Content
    {
        $content = Content::create(array_merge([
            'site_id' => $this->site->id,
            'type' => 'article',
            'title' => '文章 ' . $slug,
            'slug' => $slug,
            'summary' => '摘要。',
            'body' => "## 正文\n\n内容。",
            'geo_conclusion' => '结论。',
            'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二', 'source' => '来源二'],
            ],
            'owner' => 'Administrator',
            'reviewed_at' => '2026-10-09',
        ], $extra));

        $this->actingAs($this->admin)
            ->post(route('admin.contents.publish', $content))
            ->assertRedirect();

        // HTTP 请求后上下文可能复位，显式绕过站点作用域取回。
        return Content::withoutSiteScope()->where('id', $content->id)->firstOrFail();
    }

    private function softDelete(Content $c): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.contents.destroy', $c))->assertRedirect();
    }

    // ---------- 软删隔离 ----------

    public function test_soft_deleted_content_absent_from_normal_list_public_and_machine(): void
    {
        $c = $this->publishContent('gone-article');
        $this->softDelete($c);

        // 正常后台列表不显示
        $this->actingAs($this->admin)->get(route('admin.contents.index', 'all'))
            ->assertDontSee('gone-article');
        // 公开人类面 404
        $this->get('https://trash.test/gone-article')->assertNotFound();
        // 机器面：sitemap / llms 不含该 slug
        $this->get('https://trash.test/sitemap.xml')->assertDontSee('gone-article');
        $this->get('https://trash.test/llms.txt')->assertDontSee('gone-article');
    }

    // ---------- 回收站列表 ----------

    public function test_trash_list_only_shows_trashed_content(): void
    {
        $keep = $this->publishContent('keep-article');
        $gone = $this->publishContent('trashed-article');
        $this->softDelete($gone);

        $resp = $this->actingAs($this->admin)->get(route('admin.contents.trash'));
        $resp->assertOk();
        $resp->assertSee('trashed-article');
        $resp->assertDontSee('keep-article');
    }

    // ---------- 恢复（含翻译） ----------

    public function test_restore_recovers_content_and_translations(): void
    {
        $c = $this->publishContent('restore-article');
        $c->createTranslation('en', ['title' => 'Restore EN', 'slug' => 'restore-article-en']);
        $group = $c->translation_group;
        $this->softDelete($c);

        // 整组均软删
        $this->assertSame(2, Content::withTrashed()->withoutSiteScope()
            ->where('translation_group', $group)->whereNotNull('deleted_at')->count());

        $this->actingAs($this->admin)
            ->post(route('admin.contents.restore', $c->id))->assertRedirect();

        // 整组 deleted_at 均清空
        SiteContext::setSite($this->site);
        $this->assertSame(2, Content::withoutSiteScope()
            ->where('translation_group', $group)->whereNull('deleted_at')->count());
        // 公开人类面恢复
        $this->get('https://trash.test/restore-article')->assertOk();
    }

    public function test_restore_published_still_passing_remains_published(): void
    {
        $c = $this->publishContent('still-ok');
        $this->softDelete($c);

        $this->actingAs($this->admin)
            ->post(route('admin.contents.restore', $c->id))->assertRedirect();

        SiteContext::setSite($this->site);
        $this->assertSame('published', Content::find($c->id)->status);
    }

    public function test_restore_published_but_now_broken_downgrades_to_draft(): void
    {
        // 发布时内容有效；软删后人为写入一条 UNKNOWN-ID 语义引用，恢复时应被重算降级。
        $c = $this->publishContent('now-broken');
        $this->softDelete($c);

        Content::withTrashed()->withoutSiteScope()->where('id', $c->id)
            ->update(['entity_refs' => json_encode([999999], JSON_UNESCAPED_UNICODE)]);

        $this->actingAs($this->admin)
            ->post(route('admin.contents.restore', $c->id))->assertRedirect();

        SiteContext::setSite($this->site);
        $fresh = Content::find($c->id);
        $this->assertSame('draft', $fresh->status);
        // 公开人类面不再可访问
        $this->get('https://trash.test/now-broken')->assertNotFound();
    }

    // ---------- 永久删除 ----------

    public function test_force_delete_clears_own_associations_and_preserves_external(): void
    {
        // 外部知识对象
        $fact = Fact::create([
            'site_id' => $this->site->id, 'key' => 'external-fact', 'locale' => 'zh-CN',
            'label' => '外部事实标签', 'value' => '外部事实', 'is_public' => true,
        ]);
        $entity = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'external-entity', 'name' => '外部实体',
            'status' => Entity::STATUS_PUBLISHED, 'locale' => 'zh-CN',
        ]);
        $media = Media::create([
            'site_id' => $this->site->id, 'disk' => 'public', 'path' => 'covers/x.png',
            'mime' => 'image/png', 'size' => 100,
        ]);

        // 内容引用这些外部对象 + 自有关联
        $c = $this->publishContent('doomed-article', [
            'fact_refs' => ['external-fact'],
            'entity_refs' => [$entity->id],
            'cover_id' => $media->id,
        ]);
        // 结构边 / 标签 / 内容级 SEO
        ContentEntity::create([
            'site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $entity->id, 'relation_type' => 'about',
        ]);
        $tag = Tag::create(['site_id' => $this->site->id, 'name' => '标签', 'slug' => 'tag-one']);
        $c->tags()->sync([$tag->id]);
        SeoMeta::create([
            'site_id' => $this->site->id, 'content_id' => $c->id,
            'locale' => 'zh-CN', 'title' => 'SEO 覆盖',
        ]);

        $group = $c->translation_group;
        $this->softDelete($c);

        $this->actingAs($this->admin)
            ->delete(route('admin.contents.force-delete', $c->id))->assertRedirect();

        // 内容行（含翻译）物理消失
        $this->assertSame(0, Content::withTrashed()->withoutSiteScope()
            ->where('translation_group', $group)->count());
        // 自有关联清理
        $this->assertSame(0, ContentEntity::withoutSiteScope()->where('content_id', $c->id)->count());
        $this->assertSame(0, DB::table('content_tag')->where('content_id', $c->id)->count());
        $this->assertSame(0, SeoMeta::withoutSiteScope()->where('content_id', $c->id)->count());
        $this->assertSame(0, \App\Models\ContentRevision::withoutSiteScope()->where('content_id', $c->id)->count());

        // 外部知识保持不变
        $this->assertDatabaseHas('facts', ['id' => $fact->id]);
        $this->assertDatabaseHas('entities', ['id' => $entity->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    // ---------- 三面一致性 ----------

    public function test_three_surfaces_are_asserted_separately(): void
    {
        $c = $this->publishContent('surface-article');
        $this->softDelete($c);

        // 人类面：不可访问
        $this->get('https://trash.test/surface-article')->assertNotFound();
        // 机器面：不在 PKS / 发现输出
        $this->get('https://trash.test/geo.json')->assertDontSee('surface-article');
        // 后台回收站面：仍可列出并操作
        $this->actingAs($this->admin)->get(route('admin.contents.trash'))
            ->assertOk()->assertSee('surface-article');
    }
}
