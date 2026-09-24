<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
            \Database\Seeders\ContentSeeder::class,
            // P-STEP 14 / D.2：/contact 等固定页改读站点隔离 Catalog，需投影 Example 目录。
            \Database\Seeders\CatalogSeeder::class,
            // P-STEP 18G-2b：/contact 固定页身份由 SystemPageSeeder 提供。
            \Database\Seeders\SystemPageSeeder::class,
        ]);
        User::create([
            'name' => '管理员', 'email' => 'admin@example.test', 'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
    }

    private function validLead(array $override = []): array
    {
        return array_merge([
            'name'        => '张经理',
            'phone'       => '13800001111',
            'company'     => '某装备制造厂',
            'demand_type' => '代工合作',
            'monthly_use' => '每月 1 吨',
            'message'     => '需要一批用于设备外壳防护的工业涂料，寻求代工合作。',
        ], $override);
    }

    public function test_valid_lead_is_stored(): void
    {
        $this->get('/contact')->assertOk();

        $this->post('/inquiry', $this->validLead())->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('new', $lead->status);
        $this->assertSame('张经理', $lead->name);
        $this->assertSame('代工合作', $lead->demand_type);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->from('/contact')
            ->post('/inquiry', $this->validLead(['phone' => 'abc']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_lead_without_optional_message_is_stored_without_error(): void
    {
        // message 为选填字段；浏览器表单未填写时该键可能完全缺失。
        // 回归 P-STEP 14：Undefined array key "message" 曾导致 HTTP 500。
        $payload = $this->validLead();
        unset($payload['message']);

        $this->from('/contact')
            ->post('/inquiry', $payload)
            ->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('new', $lead->status);
        // 缺失 message 时以客户类型兜底，保证后台有可读内容
        $this->assertStringContainsString('代工合作', $lead->message);
    }

    public function test_lead_with_empty_message_is_stored_without_error(): void
    {
        // 显式提交空字符串 message 同样不得 500
        $this->from('/contact')
            ->post('/inquiry', $this->validLead(['message' => '   ']))
            ->assertRedirect();

        $this->assertSame(1, Inquiry::count());
    }

    public function test_honeypot_silently_discards_bot_submission(): void
    {
        $this->post('/inquiry', $this->validLead(['website' => 'spam-bot']))->assertRedirect();
        $this->assertSame(0, Inquiry::count());
    }

    public function test_attribution_and_device_are_stored(): void
    {
        $payload = $this->validLead() + [
            'landing_url'  => 'http://test.local/products/?utm_source=baidu',
            'referer'      => 'https://www.baidu.com/s?wd=x',
            'utm_source'   => 'baidu',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'autumn',
        ];

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile'])
            ->from('/contact')
            ->post('/inquiry', $payload)
            ->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('baidu', $lead->utm_source);
        $this->assertSame('cpc', $lead->utm_medium);
        $this->assertSame('autumn', $lead->utm_campaign);
        $this->assertSame('https://www.baidu.com/s?wd=x', $lead->referer);
        $this->assertStringContainsString('/contact', $lead->source_page);
        $this->assertSame('mobile', $lead->device_type);
    }

    public function test_capture_middleware_records_first_touch_and_utm(): void
    {
        $this->withHeaders(['referer' => 'https://www.google.com/'])
            ->get('/?utm_source=google&utm_medium=organic')
            ->assertOk()
            ->assertSessionHas('attr.utm_source', 'google')
            ->assertSessionHas('attr.utm_medium', 'organic');

        // 内部跳转不覆盖首个外部来源
        $this->get('/products/')->assertSessionHas('attr.referer', 'https://www.google.com/');
    }

    public function test_device_parser_classifies_user_agents(): void
    {
        $this->assertSame('mobile', Inquiry::deviceFromUserAgent('Mozilla (iPhone; CPU OS) Mobile'));
        $this->assertSame('tablet', Inquiry::deviceFromUserAgent('Mozilla (iPad; CPU OS)'));
        $this->assertSame('desktop', Inquiry::deviceFromUserAgent('Mozilla (Windows NT 10.0; Win64; x64)'));
        $this->assertSame('bot', Inquiry::deviceFromUserAgent('Googlebot/2.1'));
    }

    public function test_admin_inquiry_list_requires_login(): void
    {
        $this->get('/admin/inquiries')->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_mark_lead_handled(): void
    {
        $lead = Inquiry::create($this->validLead());
        $admin = User::firstOrFail();

        $this->actingAs($admin)
            ->put("/admin/inquiries/{$lead->id}", ['status' => 'handled', 'handle_note' => '已电话沟通'])
            ->assertRedirect();

        $lead->refresh();
        $this->assertSame('handled', $lead->status);
        $this->assertNotNull($lead->handled_at);
        $this->assertSame('已电话沟通', $lead->handle_note);
    }
}
