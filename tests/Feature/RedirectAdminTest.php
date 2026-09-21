<?php

namespace Tests\Feature;

use App\Models\Redirect;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台 301 跳转管理（HTTP 层）回归。
 *
 * UAT Bug#5：同一站点重复提交相同 from_path 时，控制器缺少 per-site 唯一校验，
 * 直接以数据库 unique(site_id, from_path) 约束异常返回 500。
 * 正确行为是在验证层拦截并回到表单给出友好错误（数据库层的跨站唯一约束
 * 已由 SiteIdMenusRedirectsTest 覆盖）。
 */
class RedirectAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    /** 首次提交的合法规则应创建成功并绑定当前站点。 */
    public function test_valid_redirect_is_created_and_bound_to_current_site(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/redirects', [
                'from_path' => '/old-page',
                'to_path'   => '/knowledge/industrial-coating-selection-guide',
                'code'      => '301',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/old-page',
            'site_id'   => Site::defaultId(),
            'code'      => 301,
            'is_active' => 1,
        ]);
    }

    /** 同站点重复 from_path 必须返回表单校验错误，而不是 500，且不产生第二条记录。 */
    public function test_duplicate_from_path_in_same_site_is_rejected_with_validation_error_not_500(): void
    {
        $payload = [
            'from_path' => '/duplicated',
            'to_path'   => '/knowledge/industrial-coating-selection-guide',
            'code'      => '301',
            'is_active' => '1',
        ];

        $this->actingAs($this->admin)->post('/admin/redirects', $payload)->assertRedirect();
        $this->assertEquals(1, Redirect::withoutSiteScope()->where('from_path', '/duplicated')->count());

        // 第二次重复提交：必须是回到表单的校验失败（302 + 字段错误），绝不能是 500。
        $this->actingAs($this->admin)
            ->post('/admin/redirects', $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('from_path');

        $this->assertEquals(1, Redirect::withoutSiteScope()->where('from_path', '/duplicated')->count());
    }

    /** 编辑保存同一条规则（from_path 不变）不应被唯一校验误报。 */
    public function test_updating_a_redirect_keeps_its_own_from_path(): void
    {
        $this->actingAs($this->admin)->post('/admin/redirects', [
            'from_path' => '/keep-me',
            'to_path'   => '/a',
            'code'      => '301',
            'is_active' => '1',
        ])->assertRedirect();

        $redirect = Redirect::withoutSiteScope()->where('from_path', '/keep-me')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/redirects/{$redirect->id}", [
                'from_path' => '/keep-me',
                'to_path'   => '/b',
                'code'      => '302',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('from_path');

        $this->assertDatabaseHas('redirects', [
            'id'        => $redirect->id,
            'from_path' => '/keep-me',
            'to_path'   => '/b',
            'code'      => 302,
        ]);
    }
}
