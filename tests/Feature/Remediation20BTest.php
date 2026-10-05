<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P-STEP 20B：三技能审查（代码库理解 / 故障诊断 / 性能分析）发现的 Major 修复回归。
 *  - BUG-20B-002：删除双语 anchor 必须级联处理翻译行，避免「中文删了、英文仍可访问」
 *  - M-2：content_entity / content_tag 无 DB 外键，删除时需在应用层清理，
 *    软删保留 pivot 供回收站恢复，物理删才清理。
 */
class Remediation20BTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
    }

    private function makeTranslatedArticle(): Content
    {
        $c = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'slug' => 'bilingual-art',
            'title' => '双语文章', 'status' => 'published', 'locale' => 'zh-CN',
            'body' => '正文', 'published_at' => now(),
        ]);
        $c->createTranslation('en', [
            'slug' => 'bilingual-art', 'title' => 'Bilingual Article',
            'body' => 'english body', 'status' => 'published', 'published_at' => now(),
        ]);
        return $c;
    }

    public function test_deleting_content_anchor_soft_deletes_translations(): void
    {
        $c = $this->makeTranslatedArticle();
        $this->assertEquals(2, Content::where('translation_group', $c->translation_group)->count());

        $c->delete();

        $this->assertEquals(
            0,
            Content::where('translation_group', $c->translation_group)->count(),
            '删除 anchor 后所有语言行都应被软删（BUG-20B-002）'
        );
        $this->assertEquals(
            2,
            Content::withTrashed()->where('translation_group', $c->translation_group)
                ->whereNotNull('deleted_at')->count(),
            '两行都应软删保留在回收站'
        );
    }

    public function test_deleting_entity_anchor_hard_deletes_translations(): void
    {
        $prod = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'bilingual-prod', 'name' => '双语产品', 'status' => 'published',
            'locale' => 'zh-CN',
        ]);
        $prod->createTranslation('en', [
            'slug' => 'bilingual-prod', 'name' => 'Bilingual Product', 'status' => 'published',
        ]);
        $group = $prod->translation_group;
        $this->assertEquals(2, Entity::where('translation_group', $group)->count());

        $prod->delete();

        $this->assertEquals(
            0,
            Entity::where('translation_group', $group)->count(),
            '硬删 Entity anchor 时翻译行也应被删除（BUG-20B-002）'
        );
    }

    public function test_deleting_entity_clears_content_entity(): void
    {
        $c = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'slug' => 'linked-art',
            'title' => 'Linked', 'status' => 'published', 'locale' => 'zh-CN',
            'body' => 'x', 'published_at' => now(),
        ]);
        $prod = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'linked-prod', 'name' => 'Linked Prod', 'status' => 'published',
            'locale' => 'zh-CN',
        ]);
        ContentEntity::create([
            'site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT,
        ]);
        $this->assertEquals(1, ContentEntity::count());

        $prod->delete();

        $this->assertEquals(0, ContentEntity::count(), '硬删 Entity 应清理 content_entity（M-2）');
    }

    public function test_force_delete_content_clears_both_pivots(): void
    {
        $c = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'slug' => 'purged-art',
            'title' => 'Purged', 'status' => 'published', 'locale' => 'zh-CN',
            'body' => 'x', 'published_at' => now(),
        ]);
        $prod = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'purged-prod', 'name' => 'Purged Prod', 'status' => 'published',
            'locale' => 'zh-CN',
        ]);
        ContentEntity::create([
            'site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_MENTION,
        ]);
        DB::table('content_tag')->insert([
            'content_id' => $c->id, 'tag_id' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $c->forceDelete();

        $this->assertEquals(0, ContentEntity::count(), '物理删 Content 应清理 content_entity（M-2）');
        $this->assertEquals(0, DB::table('content_tag')->where('content_id', $c->id)->count(),
            '物理删 Content 应清理 content_tag（M-2）');
    }

    public function test_soft_delete_content_keeps_pivot_for_restore(): void
    {
        $c = Content::create([
            'site_id' => $this->site->id, 'type' => 'article', 'slug' => 'kept-art',
            'title' => 'Kept', 'status' => 'published', 'locale' => 'zh-CN',
            'body' => 'x', 'published_at' => now(),
        ]);
        $prod = Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_PRODUCT,
            'slug' => 'kept-prod', 'name' => 'Kept Prod', 'status' => 'published',
            'locale' => 'zh-CN',
        ]);
        ContentEntity::create([
            'site_id' => $this->site->id, 'content_id' => $c->id,
            'entity_id' => $prod->id, 'relation_type' => ContentEntity::RELATION_ABOUT,
        ]);

        $c->delete();

        $this->assertEquals(1, ContentEntity::count(), '软删应保留 pivot 以便回收站恢复');
    }
}
