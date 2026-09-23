<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Setting;
use App\Models\User;
use App\Support\Copy;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 边角文案可运营回归：
 *  - 底部 CTA（含工厂页变体）、全局咨询表单（字段标签/提示/客户类型选项/提交话术）、
 *    404、页脚 slogan 全部接入「系统 → 站点设置 → 文案话术」
 *  - 留空回退默认，零配置零差异
 *  - 运营修改客户类型选项后，前台下拉与后端校验同步生效（前后端能力对齐）
 */
class CopySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        PageCache::flush();
        Copy::flush();
    }

    public function test_defaults_match_config_when_unconfigured(): void
    {
        // 模拟零配置：清空后台 copy_* 覆盖后，Copy 必须逐键回退出厂默认（config / 内置默认）
        Setting::where('key', 'like', 'copy_%')->delete();
        Copy::flush();
        PageCache::flush();

        $bcta = Copy::bcta();
        $this->assertSame(config('copy.bottomCta.title'), $bcta['title']);
        $this->assertSame(config('copy.bottomCta.primaryCta'), $bcta['primaryCta']);

        $factory = Copy::bcta('factory');
        $this->assertSame(config('copy.bottomCta.overrides.factory.primaryCta'), $factory['primaryCta']);
        $this->assertSame(config('copy.bottomCta.overrides.factory.secondaryCta'), $factory['secondaryCta']);

        $form = Copy::form();
        $this->assertSame(config('copy.form.fields.customerType.options'), $form['fields']['customerType']['options']);
        $this->assertSame(config('copy.form.submit'), $form['submit']);

        // 404 标题的出厂默认内置于 Copy（config 同名段落为历史死配置）
        $this->assertSame('这个页面找不到了', Copy::error404()['title']);
        $this->assertSame(config('copy.footer.brandColumn.slogan'), Copy::footerSlogan());
    }

    public function test_bottom_cta_and_footer_slogan_overrides_render_on_home(): void
    {
        Setting::set('copy_bcta_title', '先提供技术资料');
        Setting::set('copy_bcta_primary', '立即获取方案');
        Setting::set('copy_footer_slogan', '稳定品质，源头工厂造');
        PageCache::flush();
        Copy::flush();

        // 底部 CTA 出现在内页收口（首页为自带表单的区块，不含该组件）
        $inner = $this->get('/products/')->assertOk()->getContent();
        $this->assertStringContainsString('先提供技术资料', $inner);
        $this->assertStringContainsString('立即获取方案', $inner);
        $this->assertStringNotContainsString('先拿一份产品资料', $inner);

        // 页脚 slogan 全站可见
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('稳定品质，源头工厂造', $home);
        $this->assertStringNotContainsString('以稳定品质，服务每一次制造', $home);
    }

    public function test_factory_variant_override_renders(): void
    {
        Setting::set('copy_bcta_factory_primary', '预约来厂考察');
        PageCache::flush();
        Copy::flush();

        $html = $this->get('/factory/')->assertOk()->getContent();
        $this->assertStringContainsString('预约来厂考察', $html);
        $this->assertStringNotContainsString('预约工厂参观', $html);
    }

    public function test_lead_form_labels_and_custom_options_render(): void
    {
        Setting::set('copy_form_name_label', '您的称呼');
        Setting::set('copy_form_type_options', "装备制造\n工业品牌方\n其他渠道");
        PageCache::flush();
        Copy::flush();

        $html = $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('您的称呼', $html);

        // 只校验客户类型下拉本身（页面其它板块可能出现客户类型名称，不能全局断言）
        preg_match('#<select[^>]*name="demand_type".*?</select>#s', $html, $m);
        $this->assertNotEmpty($m, '前台应渲染客户类型下拉');
        $select = $m[0];
        $this->assertStringContainsString('装备制造', $select);
        $this->assertStringContainsString('工业品牌方', $select);
        $this->assertStringNotContainsString('建筑工程', $select);
    }

    public function test_custom_customer_type_is_accepted_by_backend_validation(): void
    {
        // 运营新增的客户类型，后端白名单必须同步，否则前台能选、提交被拒
        Setting::set('copy_form_type_options', "装备制造\n工业品牌方\n其他渠道");
        Copy::flush();

        $this->from('/contact')->post('/inquiry', [
            'name'        => '李工',
            'phone'       => '13900002222',
            'demand_type' => '工业品牌方',
            'message'     => '需要一批结构胶，想了解定制规格与供货。',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lead = Inquiry::firstOrFail();
        $this->assertSame('工业品牌方', $lead->demand_type);
    }

    public function test_blank_options_fall_back_to_default_six(): void
    {
        Setting::set('copy_form_type_options', '');
        Copy::flush();

        $options = Copy::form()['fields']['customerType']['options'];
        $this->assertSame(config('copy.form.fields.customerType.options'), $options);
        $this->assertCount(6, $options);
    }

    public function test_404_copy_override_renders(): void
    {
        Setting::set('copy_404_title', '页面走丢了');
        Setting::set('copy_404_primary', '回首页看看');
        PageCache::flush();
        Copy::flush();

        $html = $this->get('/this-page-does-not-exist')->assertNotFound()->getContent();
        $this->assertStringContainsString('页面走丢了', $html);
        $this->assertStringContainsString('回首页看看', $html);
    }

    public function test_admin_copy_settings_page_and_persistence(): void
    {
        $this->get('/admin/settings/copy')->assertRedirect(route('admin.login'));

        $this->actingAs($this->admin)->get('/admin/settings/copy')->assertOk()
            ->assertSee('文案话术');

        $this->actingAs($this->admin)->put('/admin/settings/copy', [
            'copy_bcta_title'      => '测试 CTA 标题',
            'copy_form_type_options' => "类型A\n类型B",
            'copy_form_submit'     => '', // 显式留空应回退默认
        ])->assertRedirect();

        $settings = Setting::allCached();
        $this->assertSame('测试 CTA 标题', $settings['copy_bcta_title']);
        $this->assertSame("类型A\n类型B", $settings['copy_form_type_options']);
        $this->assertSame('', $settings['copy_form_submit']);
        $this->assertSame(config('copy.form.submit'), Copy::form()['submit']);
    }

    public function test_saving_other_group_does_not_wipe_copy_fields(): void
    {
        // 旧标签页保存其它分组时，不能误清空文案分组（SettingController 按分组过滤）
        $this->actingAs($this->admin)->put('/admin/settings/general', [
            'site_name'        => '示例制造',
            'nav_cta_text'     => '获取产品方案',
            'site_slogan'      => '',
            'site_description' => '',
            'site_short_name'  => '',
            'icp_number'       => '',
            'police_number'    => '',
        ])->assertRedirect();

        $this->assertSame('先拿一份产品资料', Setting::allCached()['copy_bcta_title']);
    }
}
