<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 表单提交 / Inquiry 兼容入口回归（P-STEP 18H-2）。
 *
 * 旧实现：/inquiry 写死 name/phone/demand_type(制造业选项)/message 并直接写 Inquiry。
 * 新实现：/inquiry 为兼容桥接，解析默认 contact 表单，统一走 FormSubmissionService
 *   （动态字段校验 → FormSubmission 完整事实 → Inquiry 投影 → 事务外通知）。
 */
class InquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\DefaultSettingSeeder::class,
            \Database\Seeders\DefaultFormSeeder::class,
            // P-STEP 18G-2b：/contact 固定页身份 + form_reference block。
            \Database\Seeders\SystemPageSeeder::class,
        ]);
        User::create([
            'name' => '管理员', 'email' => 'admin@example.test', 'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
    }

    private function validLead(array $override = []): array
    {
        return array_merge([
            'name'    => '张经理',
            'phone'   => '13800001111',
            'email'   => 'zhang@example.com',
            'message' => '需要一批用于设备外壳防护的工业涂料。',
        ], $override);
    }

    public function test_valid_lead_is_stored(): void
    {
        $this->post('/inquiry', $this->validLead())->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('new', $lead->status);
        $this->assertSame('张经理', $lead->name);
        $this->assertSame('zhang@example.com', $lead->email);
        // 默认中性表单无 demand_type，投影回落「其他」，不再写死制造业选项。
        $this->assertSame('其他', $lead->demand_type);
        $this->assertNotNull($lead->submission_id);
        $this->assertNotNull($lead->form_id);
    }

    public function test_required_phone_is_rejected_when_missing(): void
    {
        $payload = $this->validLead();
        unset($payload['phone']);

        $this->from('/contact')
            ->post('/inquiry', $payload)
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_oversized_phone_is_rejected(): void
    {
        // tel 字段服务端上限 30 字符（默认 contact 表单无手机号正则，仅约束长度）。
        $this->from('/contact')
            ->post('/inquiry', $this->validLead(['phone' => str_repeat('1', 31)]))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_lead_without_optional_message_is_stored_without_error(): void
    {
        // message 为选填字段；浏览器表单未填写时该键可能完全缺失。
        $payload = $this->validLead();
        unset($payload['message']);

        $this->from('/contact')
            ->post('/inquiry', $payload)
            ->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('new', $lead->status);
        // 缺失 message 时投影器以确定性兜底文案填充，保证后台可读、不留空。
        $this->assertNotSame('', trim((string) $lead->message));
    }

    public function test_lead_with_empty_message_is_stored_without_error(): void
    {
        // 显式提交空字符串 message 同样不得 500。
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
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile';

        // 1) 首次从百度点击落地（首页），带 UTM + 外部 referer，CaptureAttribution 记录 first-touch。
        $this->withHeaders([
            'User-Agent' => $iphone,
            'referer'    => 'https://www.baidu.com/s?wd=x',
        ])->get('/?utm_source=baidu&utm_medium=cpc&utm_campaign=autumn')
          ->assertOk();

        // 2) 内部跳转到联系页后提交（first-touch 保留）；设备以提交请求 UA 为准。
        $this->withHeaders(['User-Agent' => $iphone])
            ->from('/contact')
            ->post('/inquiry', $this->validLead())
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

        // 内部跳转不覆盖首个外部来源（products 总览即使 404，中间件仍先写 session）。
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
        $this->post('/inquiry', $this->validLead())->assertRedirect();
        $lead = Inquiry::firstOrFail();
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
