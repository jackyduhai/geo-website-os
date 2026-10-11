<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FORM-10 — Form 启用/停用 toggle 路由防回归。
 * ------------------------------------------------------------------
 * disabled 表单的 404 效果已由 FORM-09 / FormProductization18H2Test 覆盖，
 * 但路由 POST /admin/forms/{form}/toggle 本身从未被调用。本测试验证：
 * 未登录被拦截、enabled→disabled、disabled→enabled、连续切换的状态往返。
 */
class FormToggleRouteTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->site);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
    }

    private function makeForm(string $status): Form
    {
        return Form::create([
            'site_id' => $this->site->id,
            'name' => 'Toggle Form',
            'slug' => 'toggle-form',
            'status' => $status,
            'honeypot_enabled' => true,
            'consent_required' => false,
            'notification_enabled' => false,
            'notification_channels' => 'email',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $form = $this->makeForm(Form::STATUS_ENABLED);

        $this->post(route('admin.forms.toggle', $form))
            ->assertRedirect(route('admin.login'));
    }

    public function test_toggle_disables_enabled_form(): void
    {
        $form = $this->makeForm(Form::STATUS_ENABLED);

        $this->actingAs($this->admin)
            ->post(route('admin.forms.toggle', $form))
            ->assertRedirect();

        $fresh = $form->fresh();
        $this->assertSame(Form::STATUS_DISABLED, $fresh->status);
        $this->assertFalse($fresh->isEnabled());
    }

    public function test_toggle_enables_disabled_form(): void
    {
        $form = $this->makeForm(Form::STATUS_DISABLED);

        $this->actingAs($this->admin)
            ->post(route('admin.forms.toggle', $form))
            ->assertRedirect();

        $fresh = $form->fresh();
        $this->assertSame(Form::STATUS_ENABLED, $fresh->status);
        $this->assertTrue($fresh->isEnabled());
    }

    public function test_toggle_round_trips_between_states(): void
    {
        $form = $this->makeForm(Form::STATUS_ENABLED);

        $this->actingAs($this->admin)->post(route('admin.forms.toggle', $form));
        $this->assertSame(Form::STATUS_DISABLED, $form->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.forms.toggle', $form));
        $this->assertSame(Form::STATUS_ENABLED, $form->fresh()->status);
    }
}
