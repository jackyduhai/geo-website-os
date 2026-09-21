<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v0.9.11 后台/CMS 对齐回归：
 *  - 知识子栏目数据驱动（启用几个显示几个，停用即从前台/导航/sitemap 消失）
 *  - 分组简介（description）能存能回显（修复 intro 错列名）
 *  - 正文 Markdown 预览（GFM 表格）与正文内联图片上传
 *  - 首页主体事实区块真正渲染；旧 problems/differentiators 区块已从注册表移除
 */
class V0911CmsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_seeded_knowledge_channels_render_and_nav_matches(): void
    {
        foreach (['selection', 'process', 'business'] as $slug) {
            $this->get("/knowledge/{$slug}/")->assertOk();
        }

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('选型指南', $home);
        $this->assertStringContainsString('工艺与配方', $home);
        $this->assertStringContainsString('选型与应用', $home);
    }

    public function test_legacy_empty_channels_are_not_seeded(): void
    {
        // 锁定 IA 只有三栏目；旧 craft/application/industry 不应在全新库出现
        $this->assertDatabaseMissing('groups', ['slug' => 'craft']);
        $this->assertDatabaseMissing('groups', ['slug' => 'application']);
        $this->assertDatabaseMissing('groups', ['slug' => 'industry']);
    }

    public function test_disabling_a_group_hides_channel_and_nav_via_admin(): void
    {
        $group = Group::whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->where('slug', 'selection')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", [
            'category_id' => $group->category_id,
            'name'        => $group->name,
            'slug'        => $group->slug,
            'description' => $group->description,
            'sort'        => $group->sort,
            'is_active'   => 0,
        ])->assertRedirect();

        $this->assertFalse((bool) $group->fresh()->is_active);

        // 停用后该频道不再渲染（无同 slug 内容，统一分发器回 404）
        $this->get('/knowledge/selection/')->assertNotFound();

        // 主导航同步消失
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('/knowledge/selection/', $home);
        $this->assertStringContainsString('/knowledge/process/', $home);
    }

    public function test_group_description_persists_and_echoes(): void
    {
        $group = Group::where('slug', 'process')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", [
            'category_id' => $group->category_id,
            'name'        => $group->name,
            'slug'        => $group->slug,
            'description' => '更新后的栏目简介：涂装工艺与参数逻辑',
            'sort'        => $group->sort,
            'is_active'   => 1,
        ])->assertRedirect();

        $this->assertSame('更新后的栏目简介：涂装工艺与参数逻辑', $group->fresh()->description);

        $this->actingAs($this->admin)->get('/admin/groups')
            ->assertOk()
            ->assertSee('更新后的栏目简介：涂装工艺与参数逻辑', false);
    }

    public function test_category_description_persists_and_echoes_on_edit(): void
    {
        // 回归：栏目表单曾误用不存在的 intro 列（正确列为 description），导致新建栏目必现 500。
        // 与分组 description 的历史修复同源，补齐栏目这一侧。
        $payload = [
            'name'        => 'UAT 栏目简介',
            'slug'        => 'uat-cat-intro',
            'type'        => 'list',
            'description' => '栏目简介用于列表页页头说明。',
            'sort'        => 0,
            'is_active'   => '1',
            'is_nav'      => '1',
            'is_index'    => '0',
        ];

        $this->actingAs($this->admin)->post('/admin/categories', $payload)->assertRedirect();

        $category = Category::where('slug', 'uat-cat-intro')->firstOrFail();
        $this->assertSame('栏目简介用于列表页页头说明。', $category->description);

        // 编辑页必须回显 description（而不是读取不存在的 intro）
        $this->actingAs($this->admin)->get("/admin/categories/{$category->id}/edit")
            ->assertOk()
            ->assertSee('栏目简介用于列表页页头说明。', false);
    }

    public function test_external_category_persists_external_url(): void
    {
        // 回归：表单/控制器早已暴露 type=external + external_url，但建表迁移漏列，
        // 导致保存任何栏目都 500。补列后外链栏目必须能持久化。
        $payload = [
            'name'         => '外链栏目',
            'slug'         => 'uat-external-link',
            'type'         => 'external',
            'external_url' => 'https://example.com/partner',
            'sort'         => 0,
            'is_active'    => '1',
            'is_nav'       => '1',
            'is_index'     => '0',
        ];

        $this->actingAs($this->admin)->post('/admin/categories', $payload)->assertRedirect();

        $category = Category::where('slug', 'uat-external-link')->firstOrFail();
        $this->assertSame('external', $category->type);
        $this->assertSame('https://example.com/partner', $category->external_url);
    }

    public function test_md_preview_renders_gfm_table(): void
    {
        $md = "| 列1 | 列2 |\n| --- | --- |\n| 甲 | 乙 |\n";

        $res = $this->actingAs($this->admin)
            ->postJson('/admin/contents/md-preview', ['text' => $md])
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('<table>', $res);
        $this->assertStringContainsString('<td>甲</td>', $res);
    }

    public function test_md_preview_requires_auth(): void
    {
        // 与全站后台一致：未登录一律重定向到登录页，不泄露接口内容
        $this->postJson('/admin/contents/md-preview', ['text' => 'x'])->assertRedirect('/admin/login');
    }

    public function test_inline_image_upload_returns_url_and_creates_media(): void
    {
        Storage::fake('public');

        $res = $this->actingAs($this->admin)
            ->postJson('/admin/media/inline', [
                'file' => UploadedFile::fake()->image('shot.png', 800, 400),
            ])
            ->assertOk()
            ->assertJsonStructure(['ok', 'url', 'id', 'width', 'height']);

        $this->assertTrue((bool) $res->json('ok'));
        $this->assertSame(800, $res->json('width'));
        $this->assertSame(400, $res->json('height'));
        $this->assertDatabaseHas('media', ['id' => $res->json('id')]);
    }

    public function test_inline_upload_rejects_non_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->postJson('/admin/media/inline', [
                'file' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422);
    }

    public function test_active_categories_and_their_articles_appear_in_sitemap(): void
    {
        // 新闻栏目是后台可运营的启用栏目（首页新闻块链接 /news/），sitemap 必须收录栏目页与其文章
        $news = Category::where('slug', 'news')->firstOrFail();

        $article = new Content();
        $article->category_id = $news->id;
        $article->title = '一条测试新闻';
        $article->slug = 'test-news-item';
        $article->status = 'published';
        $article->published_at = now();
        $article->body = '新闻正文。';
        $article->save();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/news/', $sitemap);
        $this->assertStringContainsString('/news/test-news-item', $sitemap);
    }

    public function test_inactive_categories_single_types_and_noindex_articles_absent_from_sitemap(): void
    {
        // 旧 about/products/contact 栏目已停用（被 config 固定页取代），不得泄漏进 sitemap
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/about/company/', $sitemap);
        // 非核心产品不建独立详情页，故不得泄漏进 sitemap
        $this->assertStringNotContainsString('/products/heat-resistant-coating-300/', $sitemap);

        // noindex 文章不进 sitemap（P0-B 后 noindex 唯一来源：SeoMeta）
        $knowledge = Category::where('slug', 'knowledge')->firstOrFail();
        $hidden = new Content();
        $hidden->category_id = $knowledge->id;
        $hidden->title = '不收录文章';
        $hidden->slug = 'noindex-article';
        $hidden->status = 'published';
        $hidden->published_at = now();
        $hidden->body = '正文。';
        $hidden->save();
        \App\Models\SeoMeta::create([
            'site_id' => $hidden->site_id, 'content_id' => $hidden->id, 'noindex' => true,
        ]);

        $sitemap2 = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/knowledge/noindex-article', $sitemap2);
    }

    public function test_home_renders_public_facts_and_no_legacy_sections(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // 主体事实区块由事实库驱动，应出现已公开的关键事实标签
        $this->assertStringContainsString('成立时间', $html);

        // 旧 problems/differentiators 区块已从注册表移除
        $types = array_keys((array) config('home_blocks.types'));
        $this->assertNotContains('problems', $types);
        $this->assertNotContains('differentiators', $types);
    }
}
