<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\User;
use App\Models\Setting;
use App\Support\Forms\FormResolver;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P-STEP 18H-2 — Form / Submission / Inquiry Productization 防回归。
 *
 * 核心产品契约（旧实现为唯一写死制造业 name/phone/demand_type/message 表单）：
 *   A) 默认 contact 表单中性、zh/en 双语，无制造业字段；
 *   B) 动态表单只渲染已定义字段（含蜜罐 / 归因），不再出现 demand_type；
 *   C) 提交 → FormSubmission（payload 全量、完整事实源）→ Inquiry（确定性投影，
 *      带 submission_id / form_id）；
 *   D) 管理员零代码创建不同字段表单（select / consent / 多选），前台即渲染；
 *   E) locale：中文提交 zh-CN、/en 提交 en；
 *   F) 多站隔离：表单 site-scoped，不串站；
 *   G) 通知在事务外、失败不丢已提交数据；
 *   H) 安全：XSS 转义、未定义字段不入 payload；
 *   I) disabled 表单 404；/inquiry 兼容入口与正式提交一致。
 */
class FormProductization18H2Test extends TestCase
{
    use RefreshDatabase;

    private Site $default;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed([
            DefaultSettingSeeder::class,
            DefaultFormSeeder::class,
            SystemPageSeeder::class,
        ]);
        $this->admin = User::create([
            'name' => '管理员', 'email' => 'admin@example.test',
            'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
        PageCache::flush();

        // view() 单元测试不经 ShareErrorsFromSession，预置空 ErrorBag 供 @error 使用。
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
    }

    // ---------- helpers ----------

    private function contactForm(): Form
    {
        return Form::where('slug', Form::DEFAULT_SLUG)->firstOrFail();
    }

    private function contactPayload(array $override = []): array
    {
        return array_merge([
            'name'    => '王工',
            'phone'   => '13800001111',
            'email'   => 'wang@example.com',
            'message' => '想了解产品规格与定制方案。',
            'website' => '',
        ], $override);
    }

    /**
     * 创建一个 site-scoped 表单，并为 zh-CN / en 各建一个结构相同的字段。
     */
    private function createFormWithField(
        string $slug,
        string $type,
        string $name,
        array $options = [],
        bool $required = false,
    ): Form {
        $form = Form::create([
            'site_id' => $this->default->id,
            'name' => $slug,
            'slug' => $slug,
            'status' => Form::STATUS_ENABLED,
            'honeypot_enabled' => true,
            'consent_required' => false,
            'notification_enabled' => false,
            'notification_channels' => 'email',
        ]);

        foreach (['zh-CN', 'en'] as $loc) {
            FormField::create([
                'form_id' => $form->id,
                'site_id' => $this->default->id,
                'locale' => $loc,
                'type' => $type,
                'name' => $name,
                'label' => ucfirst($name),
                'placeholder' => '',
                'help_text' => '',
                'required' => $required,
                'options' => $options ? json_encode($options) : null,
                'validation' => null,
                'sort_order' => 0,
            ]);
        }

        return $form;
    }

    // ---------- A) 默认中性表单 ----------

    public function test_default_contact_form_is_neutral_and_bilingual(): void
    {
        $form = $this->contactForm();
        $this->assertTrue($form->isEnabled());

        $zh = $form->fieldsForLocale('zh-CN');
        $en = $form->fieldsForLocale('en');
        $this->assertCount(4, $zh);
        $this->assertCount(4, $en);

        $zhNames = $zh->pluck('name')->all();
        $this->assertSame(['name', 'phone', 'email', 'message'], $zhNames);
        // 中性默认表单不得携带任何制造业业务字段。
        $this->assertNotContains('demand_type', $zhNames);
        $this->assertNotContains('monthly_use', $zhNames);
    }

    // ---------- B) 动态表单渲染 ----------

    public function test_dynamic_form_renders_only_defined_fields(): void
    {
        $view = $this->view('site.dynamic_form', [
            'formModel' => $this->contactForm(),
            'formLocale' => 'zh-CN',
        ]);

        $view->assertSee('name="name"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="message"', false)
            // 旧制造业下拉必须彻底消失。
            ->assertDontSee('name="demand_type"', false)
            // action 指向正式 forms.submit 路由。
            ->assertSee('action="'.route('forms.submit', 'contact').'"', false);
    }

    public function test_dynamic_form_contains_honeypot_and_attribution_fields(): void
    {
        $view = $this->view('site.dynamic_form', [
            'formModel' => $this->contactForm(),
            'formLocale' => 'zh-CN',
        ]);

        $view->assertSee('name="website"', false)
            ->assertSee('name="landing_url"', false)
            ->assertSee('name="utm_source"', false);
    }

    public function test_en_dynamic_form_renders_english_labels(): void
    {
        $view = $this->view('site.dynamic_form', [
            'formModel' => $this->contactForm(),
            'formLocale' => 'en',
        ]);

        $view->assertSee('Email', false)
            ->assertSee('How can we help?', false)
            ->assertDontSee('称呼', false);
    }

    // ---------- C) 提交 → Submission + Inquiry ----------

    public function test_contact_submit_creates_submission_with_full_payload(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        $submission = FormSubmission::firstOrFail();
        $this->assertSame('zh-CN', $submission->locale);
        $payload = $submission->payloadArray();
        // payload 保存全部业务字段原始结构，不因 Inquiry 列有限而丢字段。
        $this->assertSame('王工', $payload['name']);
        $this->assertSame('13800001111', $payload['phone']);
        $this->assertSame('wang@example.com', $payload['email']);
        $this->assertSame('想了解产品规格与定制方案。', $payload['message']);
    }

    public function test_submission_projects_inquiry_with_links(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        $inquiry = Inquiry::firstOrFail();
        $this->assertSame('new', $inquiry->status);
        $this->assertSame('王工', $inquiry->name);
        $this->assertSame('wang@example.com', $inquiry->email);
        $this->assertNotNull($inquiry->submission_id);
        $this->assertSame($this->contactForm()->id, $inquiry->form_id);
    }

    public function test_default_projection_demand_type_is_neutral(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        // 无 demand_type 字段时投影回落中性「其他」，不再写死制造业选项。
        $this->assertSame('其他', Inquiry::firstOrFail()->demand_type);
    }

    // ---------- D) 零代码创建不同字段表单 ----------

    public function test_admin_creates_form_and_select_field_without_code(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.forms.store'), [
                'name' => 'Demo Request',
                'slug' => 'demo-request',
                'title' => '申请演示',
                'status' => 'enabled',
                'consent_required' => 0,
                'honeypot_enabled' => 1,
                'notification_enabled' => 0,
                'notification_channels' => 'email',
                'notification_recipients' => '',
                'success_message' => '',
            ])->assertRedirect();

        $form = Form::where('slug', 'demo-request')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.forms.fields.store', $form), [
                'type' => 'select',
                'name' => 'company_size',
                'required' => 1,
                'validation' => '',
                'translations' => [
                    'zh-CN' => ['label' => '公司规模', 'placeholder' => '请选择', 'help_text' => '', 'options' => "1-50\n50-200\n200+"],
                    'en' => ['label' => 'Company size', 'placeholder' => 'Select', 'help_text' => '', 'options' => "1-50\n50-200\n200+"],
                ],
            ])->assertRedirect();

        // 每 locale 一行、结构列一致。
        $this->assertSame(2, FormField::where('form_id', $form->id)->count());
        $zh = FormField::where('form_id', $form->id)->where('locale', 'zh-CN')->firstOrFail();
        $this->assertSame('select', $zh->type);
        $this->assertTrue($zh->required);
        $this->assertSame(['1-50', '50-200', '200+'], $zh->optionsArray());

        // 前台无需改代码即渲染新字段。
        $this->view('site.dynamic_form', ['formModel' => $form, 'formLocale' => 'zh-CN'])
            ->assertSee('name="company_size"', false)
            ->assertSee('公司规模', false);
    }

    public function test_select_option_whitelist_is_enforced(): void
    {
        $form = $this->createFormWithField('lead-demo', 'select', 'company_size', ['1-50', '200+'], true);

        // 非选项内的值被拒。
        $this->post(route('forms.submit', $form->slug), ['company_size' => '9999-99999'])
            ->assertSessionHasErrors('company_size');
        $this->assertSame(0, FormSubmission::count());

        // 合法选项通过。
        $this->post(route('forms.submit', $form->slug), ['company_size' => '200+', 'website' => ''])
            ->assertRedirect();
        $this->assertSame('200+', FormSubmission::firstOrFail()->payloadArray()['company_size']);
    }

    public function test_single_consent_checkbox_requires_acceptance(): void
    {
        $form = $this->createFormWithField('consent-demo', 'checkbox', 'agree', [], true);

        // 未勾选 → accepted 规则拒绝。
        $this->post(route('forms.submit', $form->slug), [])
            ->assertSessionHasErrors('agree');

        // 勾选 → 通过。
        $this->post(route('forms.submit', $form->slug), ['agree' => '1', 'website' => ''])
            ->assertRedirect();
    }

    public function test_multi_checkbox_validates_each_selected_option(): void
    {
        $form = $this->createFormWithField('multi-demo', 'checkbox', 'interests', ['A', 'B', 'C']);

        // 含非选项值 → 拒绝。
        $this->post(route('forms.submit', $form->slug), ['interests' => ['A', 'Z']])
            ->assertSessionHasErrors('interests.1');

        // 合法数组 → 完整保存。
        $this->post(route('forms.submit', $form->slug), ['interests' => ['A', 'C'], 'website' => ''])
            ->assertRedirect();
        $this->assertSame(['A', 'C'], FormSubmission::firstOrFail()->payloadArray()['interests']);
    }

    // ---------- E) Locale ----------

    public function test_zh_submission_records_zh_locale(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        $this->assertSame('zh-CN', FormSubmission::firstOrFail()->locale);
    }

    public function test_en_submission_records_en_locale(): void
    {
        Setting::set('site_supported_locales', ['zh-CN', 'en']);

        $this->post('/en/forms/contact/submit', [
            'name' => 'John',
            'phone' => '13800001111',
            'email' => 'john@example.com',
            'message' => 'Looking for specs.',
            'website' => '',
        ])->assertRedirect();

        $submission = FormSubmission::firstOrFail();
        $this->assertSame('en', $submission->locale);
        $this->assertSame('John', $submission->payloadArray()['name']);
    }

    // ---------- F) 多站隔离 ----------

    public function test_forms_are_isolated_across_sites(): void
    {
        $b = Site::create([
            'slug' => 'acme',
            'name' => 'Acme Global',
            'domain' => 'acme.test',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'description' => 'Acme.',
            'metadata' => [],
        ]);

        SiteContext::setSite($b);
        $this->seed(DefaultSettingSeeder::class);

        // B 出厂无 contact 表单：解析为 null，提交 404（用 B 域名，避免被按 Host 重置回 default）。
        $this->assertNull(app(FormResolver::class)->defaultContact());
        $this->post('http://acme.test/forms/contact/submit', ['name' => 'x', 'phone' => '1'])
            ->assertNotFound();

        // B 创建自己的表单 + 字段。
        $bForm = Form::create([
            'site_id' => $b->id, 'name' => 'B Contact', 'slug' => 'contact',
            'status' => 'enabled', 'honeypot_enabled' => true, 'consent_required' => false,
            'notification_enabled' => false, 'notification_channels' => 'email',
        ]);
        foreach (['name', 'phone'] as $i => $fieldName) {
            FormField::create([
                'form_id' => $bForm->id, 'site_id' => $b->id, 'locale' => 'zh-CN',
                'type' => 'text', 'name' => $fieldName, 'label' => ucfirst($fieldName),
                'placeholder' => '', 'help_text' => '', 'required' => true,
                'options' => null, 'validation' => null, 'sort_order' => $i,
            ]);
        }

        $this->post('http://acme.test/forms/contact/submit', ['name' => 'B user', 'phone' => '2', 'website' => ''])
            ->assertRedirect();

        // B 提交落 B，default site 0 条。
        $this->assertSame(1, FormSubmission::withoutGlobalScopes()->where('site_id', $b->id)->count());
        $this->assertSame(0, FormSubmission::withoutGlobalScopes()->where('site_id', $this->default->id)->count());

        SiteContext::setSite($this->default);
        // default site 表单仍可正常提交、不被 B 影响。
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();
        $this->assertSame(1, FormSubmission::withoutGlobalScopes()->where('site_id', $this->default->id)->count());
    }

    // ---------- G) 通知失败不丢数据 ----------

    public function test_notification_failure_keeps_submission_and_inquiry(): void
    {
        $form = $this->contactForm();
        $form->update([
            'notification_enabled' => true,
            'notification_recipients' => 'sales@example.com',
        ]);

        // 模拟邮件通道在发送时故障。
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP down'));

        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        // 数据已在通知前 commit：通知失败不得回滚 / 丢失提交。
        $this->assertSame(1, FormSubmission::count());
        $this->assertSame(1, Inquiry::count());
    }

    // ---------- H) 安全 ----------

    public function test_xss_payload_is_escaped_in_admin_submissions(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->post(route('forms.submit', 'contact'), $this->contactPayload(['name' => $xss]))
            ->assertRedirect();

        $r = $this->actingAs($this->admin)->get(route('admin.forms.submissions'));
        $r->assertOk();
        // Blade {{ }} 必须转义，原始脚本不得出现在后台页面。
        $r->assertDontSee($xss, false);
        $r->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_undefined_extra_fields_are_discarded_from_payload(): void
    {
        $this->post(route('forms.submit', 'contact'), $this->contactPayload([
            'hack_field' => 'evil',
            'is_admin' => '1',
        ]))->assertRedirect();

        $payload = FormSubmission::firstOrFail()->payloadArray();
        $this->assertArrayNotHasKey('hack_field', $payload);
        $this->assertArrayNotHasKey('is_admin', $payload);
    }

    // ---------- I) 校验 / disabled / 兼容 / 追踪 ----------

    public function test_required_and_email_validation(): void
    {
        // 缺必填 name。
        $this->post(route('forms.submit', 'contact'), ['phone' => '13800001111'])
            ->assertSessionHasErrors('name');

        // 非法 email 格式。
        $this->post(route('forms.submit', 'contact'), $this->contactPayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_disabled_form_returns_404(): void
    {
        $form = $this->contactForm();
        $form->update(['status' => Form::STATUS_DISABLED]);

        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertNotFound();
        $this->assertNull(app(FormResolver::class)->find('contact'));
    }

    public function test_legacy_inquiry_route_uses_default_contact_form(): void
    {
        $this->post('/inquiry', $this->contactPayload())->assertRedirect();

        $inquiry = Inquiry::firstOrFail();
        $this->assertSame($this->contactForm()->id, $inquiry->form_id);
        $this->assertNotNull($inquiry->submission_id);
    }

    public function test_inquiry_tracks_back_to_submission_and_form(): void
    {
        $this->post('http://localhost/forms/contact/submit', $this->contactPayload())
            ->assertRedirect();

        $inquiry = Inquiry::firstOrFail();
        $this->assertSame('contact', $inquiry->submission->form->slug);
    }
}
