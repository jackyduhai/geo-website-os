<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OPEN-2 内容关联持久化缺陷回归（2026-10-15）。
 *
 * 真实表单提交契约：
 * - tag_ids 多选、entity_links 动态行均为可选输入；
 * - 用户清空所有标签/移除全部关联行后，表单提交时不会携带这两个字段（缺省 = 清空）；
 * - 关系仅挂在默认语言 anchor 上，翻译行不拥有独立关系；
 * - 移除关联仅删除透视表行，不删除 Tag / Entity 主记录。
 */
class Open2ContentRelationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Tag $tag1;
    protected Tag $tag2;
    protected Entity $product;
    protected Entity $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();

        $siteId = Content::withoutGlobalScopes()->value('site_id') ?? 1;

        $this->tag1 = Tag::create(['site_id' => $siteId, 'name' => '工业储能', 'slug' => 'open2-tag1']);
        $this->tag2 = Tag::create(['site_id' => $siteId, 'name' => '光伏并网', 'slug' => 'open2-tag2']);

        $this->product = Entity::create([
            'site_id' => $siteId, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'open2-ess', 'name' => 'OPEN2 储能系统',
            'status' => 'published', 'locale' => 'zh-CN',
        ]);
        $this->topic = Entity::create([
            'site_id' => $siteId, 'type' => Entity::TYPE_TOPIC,
            'slug' => 'open2-new-energy', 'name' => 'OPEN2 新能源主题',
            'status' => 'published', 'locale' => 'zh-CN',
        ]);
    }

    /** 最小合法草稿 payload（草稿允许不完整） */
    private function draftPayload(string $slug, array $override = []): array
    {
        return array_merge([
            'type' => 'article',
            'title' => 'OPEN2 测试内容 ' . $slug,
            'slug' => $slug,
        ], $override);
    }

    private function existingDraft(string $slug = 'open2-existing'): Content
    {
        return Content::create([
            'site_id' => 1, 'type' => 'article',
            'slug' => $slug, 'title' => 'OPEN2 已存内容',
            'status' => 'draft', 'locale' => 'zh-CN',
        ]);
    }

    // ---------------------------------------------------------------
    // A. Update 路径
    // ---------------------------------------------------------------

    public function test_update_with_relations_persists_without_500(): void
    {
        $content = $this->existingDraft();

        $response = $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug, [
            'tag_ids' => [$this->tag1->id],
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
            ],
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $content->refresh();
        $this->assertSame([$this->tag1->id], $content->tags()->pluck('tags.id')->all());
        $links = $content->entityLinks;
        $this->assertCount(1, $links);
        $this->assertSame($this->product->id, $links->first()->entity_id);
        $this->assertSame('about', $links->first()->relation_type);
    }

    public function test_update_replaces_relation_set_without_stale_rows(): void
    {
        $content = $this->existingDraft();
        $content->tags()->sync([$this->tag1->id]);
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);

        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug, [
            'tag_ids' => [$this->tag2->id],
            'entity_links' => [
                ['entity_id' => $this->topic->id, 'relation_type' => 'mention'],
            ],
        ]))->assertRedirect();

        $content->refresh();
        $this->assertSame([$this->tag2->id], $content->tags()->pluck('tags.id')->all());
        $this->assertDatabaseMissing('content_tag', [
            'content_id' => $content->id, 'tag_id' => $this->tag1->id,
        ]);
        $this->assertDatabaseMissing('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->product->id,
        ]);
        $this->assertDatabaseHas('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->topic->id, 'relation_type' => 'mention',
        ]);
    }

    public function test_update_omitted_relation_fields_clear_pivots_but_keep_master_records(): void
    {
        // 表单真实行为：清空所有标签/移除全部关联行后，字段不会出现在提交中（缺省）
        $content = $this->existingDraft();
        $content->tags()->sync([$this->tag1->id, $this->tag2->id]);
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->topic->id, 'relation_type' => 'mention',
        ]);

        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug))
            ->assertRedirect();

        $this->assertDatabaseMissing('content_tag', ['content_id' => $content->id]);
        $this->assertDatabaseMissing('content_entity', ['content_id' => $content->id]);
        // 主记录必须保留
        $this->assertNotNull(Tag::find($this->tag1->id));
        $this->assertNotNull(Tag::find($this->tag2->id));
        $this->assertNotNull(Entity::find($this->product->id));
        $this->assertNotNull(Entity::find($this->topic->id));
    }

    public function test_update_explicit_empty_arrays_clear_pivots(): void
    {
        $content = $this->existingDraft();
        $content->tags()->sync([$this->tag1->id]);
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);

        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug, [
            'tag_ids' => [],
            'entity_links' => [],
        ]))->assertRedirect();

        $this->assertDatabaseMissing('content_tag', ['content_id' => $content->id]);
        $this->assertDatabaseMissing('content_entity', ['content_id' => $content->id]);
        $this->assertNotNull(Tag::find($this->tag1->id));
        $this->assertNotNull(Entity::find($this->product->id));
    }

    // ---------------------------------------------------------------
    // B. Store 路径
    // ---------------------------------------------------------------

    public function test_store_default_anchor_with_relations_persists_both_pivots(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/contents', $this->draftPayload('open2-store-relations', [
            'tag_ids' => [$this->tag1->id, $this->tag2->id],
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
                ['entity_id' => $this->topic->id, 'relation_type' => 'mention'],
            ],
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $content = Content::where('slug', 'open2-store-relations')->firstOrFail();
        $this->assertSameCanonicalSorted([$this->tag1->id, $this->tag2->id], $content->tags()->pluck('tags.id')->all());
        $this->assertSame(2, $content->entityLinks()->count());
        $this->assertDatabaseHas('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);
        $this->assertDatabaseHas('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->topic->id, 'relation_type' => 'mention',
        ]);
    }

    public function test_store_without_relations_creates_draft_without_500(): void
    {
        $this->actingAs($this->admin)->post('/admin/contents', $this->draftPayload('open2-store-empty'))
            ->assertRedirect();

        $content = Content::where('slug', 'open2-store-empty')->firstOrFail();
        $this->assertSame(0, $content->tags()->count());
        $this->assertSame(0, $content->entityLinks()->count());
    }

    public function test_store_multiple_relations_has_no_duplicate_rows(): void
    {
        $this->actingAs($this->admin)->post('/admin/contents', $this->draftPayload('open2-store-multi', [
            'tag_ids' => [$this->tag1->id, $this->tag2->id, $this->tag1->id],
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
                ['entity_id' => $this->product->id, 'relation_type' => 'mention'],
                ['entity_id' => $this->topic->id, 'relation_type' => 'mention'],
            ],
        ]))->assertRedirect();

        $content = Content::where('slug', 'open2-store-multi')->firstOrFail();
        // BelongsToMany sync 对重复 id 自动去重
        $this->assertSameCanonicalSorted([$this->tag1->id, $this->tag2->id], $content->tags()->pluck('tags.id')->all());
        // 同一实体不同 relation_type 允许两行；同实体同类型重复不允许（前端动态行唯一）
        $rows = $content->entityLinks;
        $this->assertSame(3, $rows->count());
        $keys = $rows->map(fn ($r) => $r->entity_id . ':' . $r->relation_type)->sort()->values()->all();
        $this->assertSameCanonicalSorted(
            [$this->product->id . ':about', $this->product->id . ':mention', $this->topic->id . ':mention'],
            $keys
        );
    }

    // ---------------------------------------------------------------
    // C. 翻译与生命周期
    // ---------------------------------------------------------------

    public function test_translation_creation_does_not_write_relations_or_corrupt_anchor(): void
    {
        // 先创建默认语言 anchor 并带关系
        $this->actingAs($this->admin)->post('/admin/contents', $this->draftPayload('open2-translate-anchor', [
            'tag_ids' => [$this->tag1->id],
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
            ],
        ]))->assertRedirect();

        $anchor = Content::where('slug', 'open2-translate-anchor')->where('locale', 'zh-CN')->firstOrFail();
        $this->assertSame(1, $anchor->tags()->count());
        $this->assertSame(1, $anchor->entityLinks()->count());

        // 提交翻译行（即使请求里带了关系字段，也必须被忽略，不得污染翻译、不得改写 anchor）
        $response = $this->actingAs($this->admin)->post('/admin/contents', $this->draftPayload('open2-translate-en', [
            'trans' => 'en',
            'translation_group' => $anchor->translation_group,
            'tag_ids' => [$this->tag2->id],
            'entity_links' => [
                ['entity_id' => $this->topic->id, 'relation_type' => 'mention'],
            ],
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $translation = Content::where('translation_group', $anchor->translation_group)
            ->where('locale', 'en')->firstOrFail();
        $this->assertSame('OPEN2 测试内容 open2-translate-en', $translation->title);

        // 关系总数保持不变：翻译请求未新增/删除任何关系
        $this->assertSame(1, ContentEntity::where('content_id', $anchor->id)->count());
        $this->assertSame(1, \DB::table('content_tag')->where('content_id', $anchor->id)->count());
        // 翻译行本身不得在透视表中出现
        $this->assertSame(0, ContentEntity::where('content_id', $translation->id)->count());
        $this->assertSame(0, \DB::table('content_tag')->where('content_id', $translation->id)->count());
        // anchor 关系仍指向原数据
        $this->assertTrue($anchor->tags()->pluck('tags.id')->contains($this->tag1->id));
        $this->assertDatabaseHas('content_entity', [
            'content_id' => $anchor->id, 'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);
    }

    public function test_removing_entity_link_deletes_only_pivot_row(): void
    {
        $content = $this->existingDraft();
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $this->topic->id, 'relation_type' => 'mention',
        ]);

        // 只保留 product 关联，移除 topic
        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug, [
            'tag_ids' => [],
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
            ],
        ]))->assertRedirect();

        $this->assertDatabaseMissing('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->topic->id,
        ]);
        $this->assertDatabaseHas('content_entity', [
            'content_id' => $content->id, 'entity_id' => $this->product->id, 'relation_type' => 'about',
        ]);
        // 被移除关联的实体主记录仍存在
        $this->assertNotNull(Entity::find($this->topic->id));
    }

    public function test_regression_no_such_column_entity_links(): void
    {
        // 原始缺陷回归：提交 entity_links 不再触发 SQLSTATE no such column: entity_links
        $content = $this->existingDraft();

        $response = $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", $this->draftPayload($content->slug, [
            'entity_links' => [
                ['entity_id' => $this->product->id, 'relation_type' => 'about'],
            ],
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, $content->entityLinks()->count());
    }

    private function assertSameCanonicalSorted(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
