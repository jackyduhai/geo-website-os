<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Fact;
use App\Models\Form;
use App\Models\Group;
use App\Models\Inquiry;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 第 2 批：回收站用词与危险操作按钮的一致性测试。
 *
 * 目的：证明界面文案与真实删除生命周期一致——
 *  - 软删（可恢复）= 内容 / 实体：按钮「移入回收站」，确认文案说明可恢复；
 *  - 永久删除（不可恢复）= 页面 / 区块 / 表单 / 字段 / 站点 / 关系 / 事实 / 栏目 / 分组 / 留言 / 跳转规则：
 *    按钮使用明确动作名称，不再用单字「删」；
 *  - 回收站页保留「恢复 / 永久删除」区分。
 *
 * 本测试只验证文案与既有行为，不改变任何业务逻辑。
 */
class AdminDangerousActionsCopyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    /** 一份满足门禁的可发布草稿。 */
    private function passableContent(array $override = []): Content
    {
        return Content::create(array_merge([
            'type' => 'article', 'title' => '待删内容', 'slug' => 'to-trash-content',
            'summary' => '摘要。', 'body' => '正文。',
            'geo_conclusion' => '结论。', 'geo_explanation' => '解释。', 'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '证据一', 'value' => '值一', 'source' => '来源一'],
                ['label' => '证据二', 'value' => '值二', 'source' => '来源二'],
            ],
            'owner' => 'Administrator', 'reviewed_at' => '2026-10-09', 'fact_refs' => [],
        ], $override));
    }

    // ---------- 内容：软删 → 回收站（可恢复） ----------

    public function test_content_list_uses_trash_wording_and_recoverable_confirm(): void
    {
        $this->passableContent();

        $this->actingAs($this->admin)
            ->get(route('admin.contents.index', 'all'))
            ->assertOk()
            ->assertSee('移入回收站', false)
            ->assertSee('确认将此内容移入回收站', false)
            ->assertDontSee('删除后进入归档（软删除）', false);
    }

    public function test_destroy_soft_deletes_and_trash_page_shows_restore_and_permanent(): void
    {
        $c = $this->passableContent();

        $this->actingAs($this->admin)
            ->delete(route('admin.contents.destroy', $c))
            ->assertRedirect();

        // 内容确实进入回收站（软删、可恢复）
        $this->assertSoftDeleted('contents', ['id' => $c->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.contents.trash'))
            ->assertOk()
            ->assertSee('恢复', false)
            ->assertSee('永久删除', false)
            ->assertSee('永久删除后不可恢复', false);
    }

    public function test_restore_recovers_content_unchanged(): void
    {
        $c = $this->passableContent();
        $this->actingAs($this->admin)->delete(route('admin.contents.destroy', $c))->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.contents.restore', $c->id))
            ->assertRedirect();

        $this->assertDatabaseHas('contents', ['id' => $c->id, 'deleted_at' => null]);
    }

    public function test_force_delete_removes_content_permanently(): void
    {
        $c = $this->passableContent();
        $this->actingAs($this->admin)->delete(route('admin.contents.destroy', $c))->assertRedirect();

        $this->actingAs($this->admin)
            ->delete(route('admin.contents.force-delete', $c->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('contents', ['id' => $c->id]);
    }

    // ---------- 实体：软删可恢复（纠正原先错误的「不可恢复」文案） ----------

    public function test_entity_list_uses_recoverable_trash_wording(): void
    {
        Entity::create([
            'type' => Entity::TYPE_PRODUCT, 'slug' => 'ux-audit-product',
            'name' => 'UX 审计产品', 'status' => 'draft', 'locale' => 'zh-CN',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.entities.index'))
            ->assertOk()
            ->assertSee('移入回收站', false)
            ->assertSee('移入回收站后可恢复', false)
            ->assertDontSee('此操作不可恢复。')
            ->assertDontSee('删除实体「', false);
    }

    // ---------- 永久删除类：按钮为明确动作、不再单字「删」 ----------

    public function test_relations_page_uses_delete_relation_label(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.relations.index'))
            ->assertOk()
            ->assertSee('删除关系', false);
    }

    public function test_forms_page_uses_delete_form_label(): void
    {
        Form::create(['slug' => 'ux-audit-form', 'name' => 'UX 审计表单']);

        $this->actingAs($this->admin)
            ->get(route('admin.forms.index'))
            ->assertOk()
            ->assertSee('删除表单', false);
    }

    public function test_sites_page_uses_delete_site_label(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('删除站点', false);
    }

    public function test_pages_page_uses_delete_page_label(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.pages.index'))
            ->assertOk()
            ->assertSee('删除页面', false);
    }

    public function test_facts_page_uses_delete_fact_label(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.facts.index'))
            ->assertOk()
            ->assertSee('删除事实', false)
            ->assertSee('连带删除所有语言行', false);
    }

    public function test_categories_and_groups_use_explicit_labels(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('删除栏目', false);

        $this->actingAs($this->admin)
            ->get(route('admin.groups.index'))
            ->assertOk()
            ->assertSee('删除分组', false);
    }

    public function test_redirects_page_uses_delete_rule_label(): void
    {
        Redirect::create(['from_path' => '/old', 'to_path' => '/new', 'code' => 301]);

        $this->actingAs($this->admin)
            ->get(route('admin.redirects.index'))
            ->assertOk()
            ->assertSee('删除规则', false);
    }

    public function test_menus_page_uses_delete_label(): void
    {
        Menu::create([
            'position' => 'main', 'label' => '自定义菜单', 'url' => '/custom',
            'is_active' => true, 'sort' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.menus.index'))
            ->assertOk()
            ->assertSee('删除', false)
            ->assertSee('删除该自定义菜单项？此操作不可恢复。', false);
    }

    public function test_inquiries_page_uses_delete_message_label(): void
    {
        Inquiry::create([
            'name' => '客户', 'phone' => '13800000000',
            'message' => '咨询', 'status' => 'new',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.inquiries.index'))
            ->assertOk()
            ->assertSee('删除留言', false);
    }
}
