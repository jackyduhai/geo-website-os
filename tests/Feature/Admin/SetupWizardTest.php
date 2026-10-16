<?php

namespace Tests\Feature\Admin;

use App\Models\Entity;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18S Capability 1：首次运行 Setup Wizard。
 *
 * Wizard 是纯编排层：复用 Setting / Entity(Organization,Product) / EntityRelation，
 * 状态走 session，完成标记写 Setting::set('wizard_completed', true)。
 *
 * 覆盖（在一个全新空站点上模拟「首次运行」，与 AdminEntityCrudTest 的 Site B 约定一致）：
 * - 访客 / 未登录访问 wizard 一律跳登录；
 * - GET wizard 渲染引导页，含「跳过」链接；
 * - 逐 step 提交：
 *   1 写 site_name / contact_* 并创建站点 Organization（published）；
 *   2 写 brand_color / brand_slogan；
 *   3 创建 draft Product；
 *   4 建立 Organization —produces→ Product 的 EntityRelation；
 *   5 记录 wizard_template；
 *   6 把 draft Product 转 published、wizard_completed=true、重定向 dashboard；
 * - 全部断言落到 DB 真值（Setting / entities / entity_relations），并验证前台产品页可访问。
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected Site $default;

    /** 全新空站点：无预置 Organization / Product，模拟首次运行。 */
    protected Site $fresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();

        $this->fresh = Site::create([
            'name' => 'Fresh Site', 'slug' => 'fresh-site', 'domain' => 'fresh.test',
            'status' => 'active', 'is_default' => false,
        ]);
    }

    /** 以超管身份把当前管理站点切到 fresh 站（session 内保持）。 */
    private function useFreshSite(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.sites.switch'), ['site_id' => $this->fresh->id])
            ->assertRedirect();
    }

    /** 在目标站点上下文中读取 Setting（站点作用域，避免读到 default 站缓存）。 */
    private function setting(string $key): mixed
    {
        return SiteContext::withSite($this->fresh, fn () => Setting::get($key));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.wizard'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.wizard.save', 1), [])->assertRedirect(route('admin.login'));
    }

    public function test_wizard_page_renders_with_skip_link(): void
    {
        $this->useFreshSite();
        $page = $this->actingAs($this->super)->get(route('admin.wizard'))->assertOk();
        $page->assertSee('初始化引导');
        // 「跳过」链接可达且指向 dashboard
        $page->assertSee('跳过');
        $page->assertSee(route('admin.dashboard'), false);
    }

    public function test_full_wizard_flow_builds_a_published_basic_site(): void
    {
        $this->useFreshSite();
        // ---- Step 1：基础信息 + 创建站点 Organization -----------------------
        $this->actingAs($this->super)->post(route('admin.wizard.save', 1), [
            'company_name' => 'Demo Tenant A Living',
            'email' => 'hi@demo-tenant-a.test',
            'phone' => '024-000000',
            'address' => 'Example Street 1',
        ])->assertRedirect(route('admin.wizard', ['step' => 2]));

        $this->assertSame('Demo Tenant A Living', $this->setting('site_name'));
        $this->assertSame('hi@demo-tenant-a.test', $this->setting('contact_email'));
        $this->assertSame('024-000000', $this->setting('contact_phone'));
        $this->assertSame('Example Street 1', $this->setting('contact_address'));

        $org = Entity::withoutSiteScope()
            ->where('site_id', $this->fresh->id)->where('type', Entity::TYPE_ORGANIZATION)
            ->firstOrFail();
        $this->assertSame('site-organization', $org->slug);
        $this->assertSame(Entity::STATUS_PUBLISHED, $org->status);

        // ---- Step 2：品牌信息 ----------------------------------------------
        $this->actingAs($this->super)->post(route('admin.wizard.save', 2), [
            'brand_color' => '#1a56db',
            'slogan' => 'Better living at home',
        ])->assertRedirect(route('admin.wizard', ['step' => 3]));

        $this->assertSame('#1a56db', $this->setting('brand_color'));
        $this->assertSame('Better living at home', $this->setting('brand_slogan'));

        // ---- Step 3：创建 draft Product ------------------------------------
        // 注：纯中文名经 Str::slug 会得到空串（TD-161），这里用 ASCII 名规避。
        $this->actingAs($this->super)->post(route('admin.wizard.save', 3), [
            'products' => [
                ['name' => 'Solid Wood Dining Table', 'summary' => 'Natural oak dining table'],
            ],
        ])->assertRedirect(route('admin.wizard', ['step' => 4]));

        $product = Entity::withoutSiteScope()
            ->where('site_id', $this->fresh->id)->where('type', Entity::TYPE_PRODUCT)
            ->firstOrFail();
        $this->assertSame('solid-wood-dining-table', $product->slug);
        $this->assertSame('Natural oak dining table', $product->summary);
        $this->assertSame(Entity::STATUS_DRAFT, $product->status);

        // ---- Step 4：Organization —produces→ Product 关系 -------------------
        $this->actingAs($this->super)->post(route('admin.wizard.save', 4), [])
            ->assertRedirect(route('admin.wizard', ['step' => 5]));

        $this->assertDatabaseHas('entity_relations', [
            'from_entity_id' => $org->id,
            'to_entity_id' => $product->id,
            'relation_type' => 'produces',
            'site_id' => $this->fresh->id,
        ]);

        // ---- Step 5：模板选择 ----------------------------------------------
        $this->actingAs($this->super)->post(route('admin.wizard.save', 5), [
            'template' => 'saas',
        ])->assertRedirect(route('admin.wizard', ['step' => 6]));

        $this->assertSame('saas', $this->setting('wizard_template'));

        // ---- Step 6：发布 + 完成标记 ---------------------------------------
        $this->actingAs($this->super)->post(route('admin.wizard.save', 6), [])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(Entity::STATUS_PUBLISHED, $product->fresh()->status);
        $this->assertSame('true', $this->setting('wizard_completed'));

        // ---- 向导产出的基础官网前台可访问 -----------------------------------
        $this->get('https://fresh.test/products/')->assertOk()
            ->assertSee('Solid Wood Dining Table');
        $this->get('https://fresh.test/products/solid-wood-dining-table')->assertOk()
            ->assertSee('Solid Wood Dining Table');
    }

    public function test_cjk_product_name_gets_stable_slug_without_500(): void
    {
        // TD-161：纯中文产品名经 Str::slug 得空串，应被归一为合法唯一 slug，无 500。
        $this->useFreshSite();

        $this->actingAs($this->super)->post(route('admin.wizard.save', 1), [
            'company_name' => '木居家居', 'email' => 'hi@muju.test',
        ])->assertRedirect();

        $this->actingAs($this->super)->post(route('admin.wizard.save', 3), [
            'products' => [['name' => '实木餐桌', 'summary' => '橡木']],
        ])->assertRedirect();

        $product = Entity::withoutSiteScope()
            ->where('site_id', $this->fresh->id)->where('type', Entity::TYPE_PRODUCT)
            ->firstOrFail();

        $this->assertNotSame('', $product->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $product->slug);
        $this->assertSame('实木餐桌', $product->name);

        // 发布后详情页可访问（URL 安全 slug，非空）
        $this->actingAs($this->super)->post(route('admin.wizard.save', 6), [])
            ->assertRedirect(route('admin.dashboard'));
        $this->get("https://fresh.test/products/{$product->slug}")->assertOk()
            ->assertSee('实木餐桌');
    }

    public function test_wizard_completed_flag_is_recorded_only_after_step6(): void
    {
        $this->useFreshSite();
        // 走完 1~5 步后仍未完成，第 6 步才落完成标记。
        foreach ([1, 2, 3, 4, 5] as $step) {
            $this->actingAs($this->super)->post(route('admin.wizard.save', $step), [
                'company_name' => 'Co', 'products' => [['name' => 'Widget']],
            ])->assertRedirect();
        }
        $this->assertNull($this->setting('wizard_completed'));

        $this->actingAs($this->super)->post(route('admin.wizard.save', 6), [])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSame('true', $this->setting('wizard_completed'));
    }

    /**
     * B5-WIZ · W1/W2：标题与 Slogan 字段中文化（提交字段名 slogan 保持不变）。
     */
    public function test_wizard_title_and_slogan_label_are_localized(): void
    {
        $this->useFreshSite();

        // W1：页面标题中文化，不再出现中英混杂的 “Setup 引导”。
        $step1 = $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 1]))->assertOk();
        $step1->assertSee('初始化引导');
        $step1->assertDontSee('Setup 引导');

        // W2：Slogan 字段显示中文标签，但输入控件的 name 仍为 slogan（提交契约不变）。
        $step2 = $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 2]))->assertOk();
        $step2->assertSee('品牌口号（Slogan）');
        $step2->assertSee('name="slogan"', false);
    }

    /**
     * B5-WIZ · W3（α′）：行业模板偏好下拉显示中文，但 option value / 提交值 / 存储值保持英文 key，
     * 且向导不应用模板（仅写偏好）。
     */
    public function test_step5_template_options_show_chinese_but_keep_english_values(): void
    {
        $this->useFreshSite();

        $page = $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 5]))->assertOk();
        $page->assertSee('行业模板偏好');
        $page->assertSee('本项仅记录模板偏好，不会在初始化向导中应用模板');
        // 中文显示名（业务定稿）
        $page->assertSee('通用');
        $page->assertSee('制造业');
        $page->assertSee('软件服务（SaaS）');
        $page->assertSee('品牌电商');
        // 四个 option 的 value 仍是受控英文 key
        foreach (['default', 'manufacturing', 'saas', 'commerce'] as $key) {
            $page->assertSee('value="' . $key . '"', false);
        }

        // 提交 saas 后，存储值仍是英文 key（契约不变）；向导不激活/不应用模板。
        $this->actingAs($this->super)->post(route('admin.wizard.save', 5), [
            'template' => 'saas',
        ])->assertRedirect(route('admin.wizard', ['step' => 6]));
        $this->assertSame('saas', $this->setting('wizard_template'));
        $this->assertSame('', \App\Support\Templates\TemplatePackageManager::active());
    }

    /**
     * B5-WIZ · W4：直接落在某一步时，进度条呈现已完成/当前/未到三态；
     * 已完成步骤可通过现有 GET 链接回看，未到步骤不可点击。
     */
    public function test_progress_three_states_when_landing_directly_on_a_step(): void
    {
        $this->useFreshSite();

        $page = $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 3]))->assertOk();

        // 三态 class 与当前步标记
        $page->assertSee('wizard-step-done', false);
        $page->assertSee('wizard-step-current', false);
        $page->assertSee('wizard-step-todo', false);
        $page->assertSee('aria-current="step"', false);

        // 已完成步骤 1、2 是回看链接
        $page->assertSee(route('admin.wizard', ['step' => 1]), false);
        $page->assertSee(route('admin.wizard', ['step' => 2]), false);
        // 未到步骤 4、5、6 不提供链接（不可点击）
        $page->assertDontSee(route('admin.wizard', ['step' => 4]), false);
        $page->assertDontSee(route('admin.wizard', ['step' => 5]), false);
        $page->assertDontSee(route('admin.wizard', ['step' => 6]), false);

        // 直接访问说明型步骤（step4）与发布步（step6）也能正常渲染
        $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 4]))->assertOk()
            ->assertSee('自动把已创建的产品挂到 Organization');
        $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 6]))->assertOk()
            ->assertSee('完成并发布');
    }

    /**
     * B5-WIZ · W4：回看较早步骤时，已越过的步骤仍显示“已完成”，当前进度不回退；
     * 页面给出回看提示并可一键返回当前进度步骤。
     */
    public function test_reviewing_earlier_step_keeps_later_steps_completed(): void
    {
        $this->useFreshSite();

        // 先到达第 5 步
        $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 5]))->assertOk();
        // 回看第 2 步
        $page = $this->actingAs($this->super)->get(route('admin.wizard', ['step' => 2]))->assertOk();

        // 当前在第 2 步
        $page->assertSee('aria-current="step"', false);
        // 已越过的 3/4/5 步仍显示已完成（可回看），不被误判为“未到”
        $page->assertSee(route('admin.wizard', ['step' => 3]), false);
        $page->assertSee(route('admin.wizard', ['step' => 4]), false);
        $page->assertSee(route('admin.wizard', ['step' => 5]), false);
        // 仅第 6 步尚未到达
        $page->assertDontSee(route('admin.wizard', ['step' => 6]), false);
        // 回看提示与“返回当前进度”链接（回到第 5 步）
        $page->assertSee('你正在回看第 2 步');
        $page->assertSee('返回当前进度：第 5 步');
        $page->assertSee(route('admin.wizard', ['step' => 5]), false);
    }
}
