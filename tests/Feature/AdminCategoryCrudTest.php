<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台栏目（Category）管理的端到端测试。
 *
 * 回归 P-STEP 14 / Sanitization 探索式验收发现的真实缺陷：
 * CategoryController 与栏目表单一直在读写 is_index（首页推荐），
 * 但建表迁移遗漏了该列，导致全新部署下提交「新建栏目」必然 HTTP 500
 * （SQLSTATE: table categories has no column named is_index）。
 */
class AdminCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_admin_can_create_category_with_is_index_flag(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/categories', [
                'name' => '首页推荐栏目',
                'slug' => 'featured-cat',
                'type' => 'list',
                'is_index' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'slug' => 'featured-cat',
            'type' => 'list',
            'is_index' => 1,
        ]);
    }

    public function test_new_category_defaults_is_index_to_false(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/categories', [
                'name' => '普通栏目',
                'slug' => 'plain-cat',
                'type' => 'list',
                // 故意不传 is_index
            ])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'slug' => 'plain-cat',
            'is_index' => 0,
        ]);
    }

    public function test_invalid_category_payload_is_rejected_and_not_persisted(): void
    {
        $before = Category::count();

        $this->actingAs($this->admin)
            ->from('/admin/categories/create')
            ->post('/admin/categories', [
                'name' => '',
                'slug' => 'Bad Slug!',
                'type' => 'weird',
            ])
            ->assertSessionHasErrors(['name', 'slug', 'type']);

        $this->assertSame($before, Category::count());
    }

    public function test_toggle_flips_is_index(): void
    {
        $category = Category::create([
            'name' => '可切换栏目',
            'slug' => 'toggle-cat',
            'type' => 'list',
            'is_index' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.categories.toggle', $category), ['field' => 'is_index'])
            ->assertRedirect();

        $this->assertTrue((bool) $category->fresh()->is_index);

        $this->actingAs($this->admin)
            ->post(route('admin.categories.toggle', $category->fresh()), ['field' => 'is_index'])
            ->assertRedirect();

        $this->assertFalse((bool) $category->fresh()->is_index);
    }

    public function test_toggle_rejects_field_outside_whitelist(): void
    {
        $category = Category::create([
            'name' => '受保护栏目',
            'slug' => 'protected-cat',
            'type' => 'list',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->from('/admin/categories')
            ->post(route('admin.categories.toggle', $category), ['field' => 'deleted_at'])
            ->assertRedirect('/admin/categories');

        // 白名单外字段不得触发任何更新，栏目仍处于启用状态
        $this->assertTrue((bool) $category->fresh()->is_active);
    }

    public function test_category_with_contents_cannot_be_deleted(): void
    {
        $knowledge = Category::where('slug', 'knowledge')->firstOrFail();
        $this->assertTrue($knowledge->contents()->exists(), '前置条件：知识栏目下应有演示内容');

        $this->actingAs($this->admin)
            ->from('/admin/categories')
            ->delete(route('admin.categories.destroy', $knowledge))
            ->assertRedirect('/admin/categories');

        $this->assertDatabaseHas('categories', ['slug' => 'knowledge']);
    }

    /**
     * TD-16①：栏目 slug 唯一约束按站点作用域——同站重复拒绝，跨站允许复用。
     */
    public function test_slug_unique_is_scoped_per_site(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);

        // Site B 先占用 slug（在 B 上下文写入）。
        SiteContext::withSite($siteB, function () {
            Category::create([
                'name' => 'Shared', 'slug' => 'shared-cat', 'type' => 'list',
                'is_active' => true, 'is_nav' => false,
            ]);
        });

        // 默认站使用同一 slug 必须成功（跨站不冲突）。
        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => 'Shared On Default', 'slug' => 'shared-cat', 'type' => 'list',
        ])->assertRedirect(route('admin.categories.index'));

        // 默认站再次使用同一 slug 必须被拒（同站唯一）。
        $this->actingAs($this->admin)->from('/admin/categories/create')
            ->post('/admin/categories', [
                'name' => 'Dup', 'slug' => 'shared-cat', 'type' => 'list',
            ])->assertSessionHasErrors('slug');
    }

    /**
     * TD-16①：父栏目必须属于当前站点，禁止把跨站栏目设为父级。
     */
    public function test_parent_category_must_belong_to_same_site(): void
    {
        $siteB = Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => 'active', 'is_default' => false,
        ]);
        $bParent = SiteContext::withSite($siteB, fn () => Category::create([
            'name' => 'B Parent', 'slug' => 'b-parent', 'type' => 'list',
            'is_active' => true, 'is_nav' => false,
        ]));

        $this->actingAs($this->admin)->from('/admin/categories/create')
            ->post('/admin/categories', [
                'name' => 'Cross Parent', 'slug' => 'cross-parent', 'type' => 'list',
                'parent_id' => $bParent->id,
            ])->assertSessionHasErrors('parent_id');
    }

    /**
     * TD-16①：外链型栏目必须提供合法 external_url，其余类型不要求。
     */
    public function test_external_type_requires_valid_url(): void
    {
        // 缺 URL 被拒。
        $this->actingAs($this->admin)->from('/admin/categories/create')
            ->post('/admin/categories', [
                'name' => 'Partner', 'slug' => 'partner', 'type' => 'external',
            ])->assertSessionHasErrors('external_url');

        // 非法 URL 被拒。
        $this->actingAs($this->admin)->from('/admin/categories/create')
            ->post('/admin/categories', [
                'name' => 'Partner', 'slug' => 'partner', 'type' => 'external',
                'external_url' => 'not-a-url',
            ])->assertSessionHasErrors('external_url');

        // 合法 URL 通过。
        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => 'Partner', 'slug' => 'partner', 'type' => 'external',
            'external_url' => 'https://partner.example.com/',
        ])->assertRedirect(route('admin.categories.index'));

        // 普通列表型不要求 external_url。
        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => 'Plain', 'slug' => 'plain-list', 'type' => 'list',
        ])->assertRedirect(route('admin.categories.index'));
    }

    /**
     * TD-16①：单页（page）与外链（external）栏目不进 sitemap，列表型收录。
     */
    public function test_page_and_external_categories_are_excluded_from_sitemap(): void
    {
        PageCache::flush();

        Category::create([
            'name' => 'List Cat', 'slug' => 'list-cat-td16', 'type' => Category::TYPE_LIST,
            'is_active' => true, 'is_nav' => false,
        ]);
        Category::create([
            'name' => 'Page Cat', 'slug' => 'page-cat-td16', 'type' => Category::TYPE_PAGE,
            'is_active' => true, 'is_nav' => false,
        ]);
        Category::create([
            'name' => 'Ext Cat', 'slug' => 'ext-cat-td16', 'type' => Category::TYPE_EXTERNAL,
            'is_active' => true, 'is_nav' => false, 'external_url' => 'https://x.example.com/',
        ]);

        $xml = $this->get('/sitemap.xml')->assertOk();
        $xml->assertSee('list-cat-td16');
        $xml->assertDontSee('page-cat-td16');
        $xml->assertDontSee('ext-cat-td16');
    }
}
