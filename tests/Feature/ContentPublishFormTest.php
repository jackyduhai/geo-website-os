<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 内容编辑页发布/下架表单结构（UAT Bug#2 防回归）。
 *
 * 历史缺陷：发布/下架按钮曾以内联 <form> 嵌套在主表单 #contentForm 内部。
 * HTML 规范禁止 <form> 嵌套，浏览器会忽略内层 <form>，导致发布按钮永远
 * 无法 POST 到 ContentController::publish()（Feature 测试直接 POST 路由故抓不到）。
 *
 * 正确结构：主表单只负责保存；发布/下架是主 </form> 之外的独立隐藏载体
 * 表单，按钮以 form="publishForm|unpublishForm" 属性关联。
 */
class ContentPublishFormTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    private function draftContent(string $slug, string $status): Content
    {
        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        return Content::create([
            'site_id' => $site->id,
            'type'    => 'article',
            'title'   => '表单结构测试 ' . $slug,
            'slug'    => $slug,
            'status'  => $status,
        ]);
    }

    /** 提取 #contentForm 主表单的内部 HTML（非贪婪到其第一个闭合 </form>） */
    private function mainFormInner(string $html): string
    {
        // 主表单内部不得嵌套 <form>，因此第一个 </form> 即其闭合标签。
        $matched = preg_match(
            '/<form\b[^>]*id="contentForm"[^>]*>(.*?)<\/form>/is',
            $html,
            $m
        );

        $this->assertSame(1, $matched, '编辑页必须存在主表单 #contentForm');

        return $m[1];
    }

    public function test_draft_edit_page_has_no_nested_form_and_standalone_publish_form(): void
    {
        $content = $this->draftContent('form-draft', 'draft');

        $html = $this->actingAs($this->admin)
            ->get("/admin/contents/{$content->id}/edit")
            ->assertOk()
            ->getContent();

        // 主表单内部不得再出现任何 <form>（HTML 禁嵌套）。
        $this->assertStringNotContainsString('<form', $this->mainFormInner($html));

        // 发布动作必须是主表单之外的独立载体表单，按钮以 form= 属性关联。
        $this->assertStringContainsString('id="publishForm"', $html);
        $this->assertStringContainsString('form="publishForm"', $html);
        // 草稿不应渲染下架载体表单。
        $this->assertStringNotContainsString('id="unpublishForm"', $html);
    }

    public function test_published_edit_page_has_no_nested_form_and_standalone_unpublish_form(): void
    {
        $content = $this->draftContent('form-published', 'published');

        $html = $this->actingAs($this->admin)
            ->get("/admin/contents/{$content->id}/edit")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<form', $this->mainFormInner($html));

        $this->assertStringContainsString('id="unpublishForm"', $html);
        $this->assertStringContainsString('form="unpublishForm"', $html);
    }
}
