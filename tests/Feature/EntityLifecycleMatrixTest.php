<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Models\Setting;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18R-2b Gate：生命周期状态矩阵（draft/published/archived/deleted）。
 * 防止后台删除/下线后 Google/AI 仍见旧实体：页面 / Schema / Search / Sitemap / GEO 同步撤回。
 */
class EntityLifecycleMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $this->site->update(['domain' => 'example.com']);
        SiteContext::setSite($this->site);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
    }

    private function makeCase(string $status): Entity
    {
        return Entity::create([
            'site_id' => $this->site->id, 'type' => Entity::TYPE_CASE_STUDY,
            'slug' => 'life-case', 'name' => 'Life Case', 'status' => $status,
            'locale' => 'zh-CN', 'metadata' => ['industry' => '机械'],
        ]);
    }

    private function inSitemap(): bool
    {
        return str_contains($this->get('/sitemap.xml')->getContent(), '/cases/life-case');
    }

    private function inSearch(): bool
    {
        return \DB::table('search_documents')->where('path', '/cases/life-case')->exists();
    }

    public function test_draft_is_not_public(): void
    {
        $this->makeCase(Entity::STATUS_DRAFT);
        $this->get('/cases/life-case')->assertNotFound();
        $this->get('/cases/')->assertNotFound();
        $this->assertFalse($this->inSitemap());
        $this->assertFalse($this->inSearch());
    }

    public function test_published_then_archived_is_removed(): void
    {
        $case = $this->makeCase(Entity::STATUS_PUBLISHED);
        $this->get('/cases/life-case')->assertOk();
        $this->assertTrue($this->inSitemap());

        // 下线 → 撤回
        $case->update(['status' => Entity::STATUS_ARCHIVED]);
        $this->get('/cases/life-case')->assertNotFound();
        $this->assertFalse($this->inSitemap());
    }

    public function test_deleted_returns_404_everywhere(): void
    {
        $case = $this->makeCase(Entity::STATUS_PUBLISHED);
        $this->get('/cases/life-case')->assertOk();

        $case->delete();
        $this->get('/cases/life-case')->assertNotFound();
        $this->assertFalse($this->inSitemap());

        // geo.json 不再含该节点
        $graph = $this->get('/geo.json')->json();
        $slugs = array_column($graph['entities'], 'slug');
        $this->assertNotContains('life-case', $slugs);
    }
}
