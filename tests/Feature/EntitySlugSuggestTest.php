<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENT-14 — Entity slug-suggest 端点防回归（20F-HAT P2-2）。
 * ------------------------------------------------------------------
 * 底层 SlugSuggester 已有覆盖，但路由 GET /admin/entities/slug-suggest 本身
 * 未被调用。该静态路径必须注册在 entities/{entity} 之前，否则会被动态段拦截。
 * 本测试验证：未登录被拦截、登录后返回 JSON、中文→拼音、中英混排清洗、空文本→空。
 */
class EntitySlugSuggestTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.entities.slug-suggest', ['text' => '晨光']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_suggests_pinyin_slug_for_chinese_name(): void
    {
        $r = $this->actingAs($this->admin)
            ->get(route('admin.entities.slug-suggest', ['text' => '晨光精密制造']));

        $r->assertOk();
        $r->assertJson(['slug' => 'chen-guang-jing-mi-zhi-zao']);
    }

    public function test_suggests_cleaned_slug_for_mixed_name(): void
    {
        $r = $this->actingAs($this->admin)
            ->get(route('admin.entities.slug-suggest', ['text' => '工业防腐涂料 C100']));

        $r->assertJson(['slug' => 'gong-ye-fang-fu-tu-liao-c100']);
    }

    public function test_latin_name_is_slugified_and_lowercased(): void
    {
        $r = $this->actingAs($this->admin)
            ->get(route('admin.entities.slug-suggest', ['text' => 'Acme Corp!']));

        $r->assertJson(['slug' => 'acme-corp']);
    }

    public function test_empty_or_blank_text_returns_empty_slug(): void
    {
        $r = $this->actingAs($this->admin)
            ->get(route('admin.entities.slug-suggest', ['text' => '   ']));

        $r->assertJson(['slug' => '']);
    }

    public function test_returned_slug_matches_valid_slug_pattern(): void
    {
        $r = $this->actingAs($this->admin)
            ->get(route('admin.entities.slug-suggest', ['text' => '晨光精密制造 Example 99']));

        $slug = $r->json('slug');
        $this->assertIsString($slug);
        // 业务不变量：建议 slug 只含小写字母 / 数字 / 连字符，且不以连字符开头结尾。
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}
