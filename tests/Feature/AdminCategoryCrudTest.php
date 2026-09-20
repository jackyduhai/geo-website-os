<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
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
}
