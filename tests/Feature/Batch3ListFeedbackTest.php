<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3-2 后台术语精简（第 3 批 · 工作包 2：列表说明去重 + 操作反馈）。
 *
 * 覆盖审计项：C012/C049（实体列表）、C015（关系列表）、C017（表单列表）、
 * C020（站点列表）、C030（主题列表）、C031（插件列表）、C032（模板列表）、
 * C034（媒体删除确认预告引用阻断）、B3-DISC-2（媒体 alt/title 占位）、
 * C038（事实告警中文）、C045（登录页产品称谓）。
 *
 * 核心契约：本批只做展示层删并。每条被精简掉的重复规则，其信息更完整的
 * 权威版本必须仍在底部说明卡，且全页只出现一次；后端行为（引用阻断、删除
 * 生命周期、权限、路由）一律不变。
 */
class Batch3ListFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function makeMedia(string $mime, string $path, string $originalName, array $attributes = []): Media
    {
        return Media::create(array_merge([
            'site_id' => 1, 'disk' => 'public', 'path' => $path,
            'original_name' => $originalName, 'mime' => $mime, 'size' => 1234,
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // C012 / C049：实体列表 page-desc 精简，底部说明完整保留
    // ---------------------------------------------------------------

    public function test_entities_index_desc_lists_all_eight_types_and_keeps_bottom_rules(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/entities')->assertOk()->getContent();

        // page-desc 据实列全 8 类实体（原仅 6 类，缺客户案例 / 下载资料）
        foreach (['组织', '产品', '服务', '人物', '地点', '主题', '客户案例', '下载资料'] as $type) {
            $this->assertStringContainsString($type, $html);
        }
        // 原 page-desc 堆叠的技术细节已下沉，顶部不再出现该长句
        $this->assertStringNotContainsString('Schema / GEO / Sitemap 统一呈现', $html);
        // 底部说明卡的独有信息一条不少
        $this->assertStringContainsString('需先有一个「组织」实体', $html);       // 上线条件
        $this->assertStringContainsString('二者不互相 fallback', $html);         // 实体与内容
        $this->assertStringContainsString('两端实体均发布后该关系才进入 geo.json', $html); // 关系
    }

    // ---------------------------------------------------------------
    // C015：关系列表「两端发布才进 geo.json」全页仅一处，类型表/方向/级联保留
    // ---------------------------------------------------------------

    public function test_relations_index_keeps_single_visibility_rule_and_full_type_table(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/relations')->assertOk()->getContent();

        // 底部关系类型表 5 项全在
        foreach (['produces', 'offers', 'uses', 'located_in', 'related_to'] as $rel) {
            $this->assertStringContainsString($rel, $html);
        }
        // 方向性 / 自关系 / 多站点级联独有提示保留
        $this->assertStringContainsString('关系为有向边', $html);
        $this->assertStringContainsString('自关系 / 反向关系允许', $html);
        $this->assertStringContainsString('外键级联自动清除', $html);
        // 可见性规则（底部，含草稿/归档）唯一存在一次
        $this->assertSame(1, substr_count($html, '该边才进入 /geo.json'));
        $this->assertStringContainsString('草稿 / 归档实体会让关系暂时不对外输出', $html);
        // 原 page-desc 的重复发布表述已移除
        $this->assertStringNotContainsString('关系本身没有发布状态', $html);
    }

    // ---------------------------------------------------------------
    // C017：表单列表 Submission 投影规则唯一保留下方，放置/邮件两条保留
    // ---------------------------------------------------------------

    public function test_forms_index_keeps_submission_rule_once_and_placement_mail_notices(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/forms')->assertOk()->getContent();

        // 底部独有：前台放置表单、邮件通知
        $this->assertStringContainsString('在前台放置表单', $html);
        $this->assertStringContainsString('邮件通知', $html);
        // 投影规则全页只出现一次（底部「提交数据」），顶部 page-desc 不再重复
        $this->assertSame(1, substr_count($html, '投影'));
        $this->assertStringContainsString('向后兼容投影到「客户留言」', $html);
    }

    // ---------------------------------------------------------------
    // C020：站点列表据实核对——删除保护仅底部，三条独有规则保留
    // ---------------------------------------------------------------

    public function test_sites_index_keeps_default_protection_delete_guard_and_switch_notice(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/sites')->assertOk()->getContent();

        // page-desc 定位保留
        $this->assertStringContainsString('租户根', $html);
        $this->assertStringContainsString('一个域名对应一个站点', $html);
        // 底部三条高风险/独有规则全在
        $this->assertStringContainsString('默认站不可删除、不可取消默认', $html);
        $this->assertStringContainsString('任何业务数据时禁止删除', $html);
        $this->assertStringContainsString('当前管理站点', $html);
        // 删除保护只在底部（高风险句不得在顶部被误删）
        $this->assertSame(1, substr_count($html, '禁止删除'));
    }

    // ---------------------------------------------------------------
    // C030：主题列表 page-desc 精简，预览句下沉，底部机制一条不少
    // ---------------------------------------------------------------

    public function test_themes_index_desc_concise_and_bottom_keeps_preview_and_mechanisms(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/themes')->assertOk()->getContent();

        // 底部独有机制：视图覆盖回退 / 原子激活 / SDK / 配色归设置
        $this->assertStringContainsString('未覆盖的视图自动回退到基础视图', $html);
        $this->assertStringContainsString('不会留下「半激活」状态', $html);
        $this->assertStringContainsString('Theme SDK', $html);
        $this->assertStringContainsString('细粒度设置归「站点设置 → 外观与主题」', $html);
        // 预览不改变激活的规则下沉到底部，全页唯一
        $this->assertSame(1, substr_count($html, '不会改变当前激活主题'));
        // 顶部不再重复「不提供上传或在线编辑」
        $this->assertStringNotContainsString('不提供上传或在线编辑', $html);
    }

    // ---------------------------------------------------------------
    // C031：插件列表注册≠授权/隔离/安装边界下沉，路由探测说明保留
    // ---------------------------------------------------------------

    public function test_plugins_index_desc_concise_and_bottom_keeps_security_mechanisms(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->getContent();

        // page-desc 独有的实时路由探测说明保留
        $this->assertStringContainsString('真实前台请求结果', $html);
        // 底部安全机制：守卫 / 依赖闸门 / A·B 隔离 / SDK
        $this->assertStringContainsString('注册 ≠ 授权', $html);
        $this->assertStringContainsString('EnsurePluginEnabled', $html);
        $this->assertStringContainsString('依赖闸门', $html);
        $this->assertStringContainsString('A 站返回 200、在 B 站返回 404', $html);
        $this->assertStringContainsString('Plugin SDK', $html);
        // 顶部不再重复注册授权长句
        $this->assertStringNotContainsString('注册不等于授权', $html);
    }

    // ---------------------------------------------------------------
    // C032：模板列表 page-desc 精简，底部口诀/三层校验/事实源/SDK 保留
    // ---------------------------------------------------------------

    public function test_templates_index_desc_concise_and_bottom_keeps_fact_sources_and_checks(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/templates')->assertOk()->getContent();

        // page-desc 保留五动作与激活骨架 / 初始化不覆盖
        $this->assertStringContainsString('发现、预览、对比、按站点激活与初始化默认值', $html);
        $this->assertStringContainsString('激活只生成页面骨架（配方）', $html);
        $this->assertStringContainsString('不覆盖已有配置', $html);
        // 底部独有：口诀 / 三层校验 / 事实源 / Template SDK
        $this->assertStringContainsString('主题管视觉、模板管结构、区块管能力、内容管事实', $html);
        $this->assertStringContainsString('三层校验', $html);
        $this->assertStringContainsString('业务事实（产品 / 服务 / 文章）仍是唯一事实源', $html);
        $this->assertStringContainsString('Template SDK', $html);
        // 顶部不再重复「不提供上传或在线编辑」
        $this->assertStringNotContainsString('不提供上传或在线编辑', $html);
    }

    // ---------------------------------------------------------------
    // C034：媒体删除确认预告引用阻断与不可恢复（后端阻断行为不变）
    // ---------------------------------------------------------------

    public function test_media_delete_confirm_warns_about_reference_block_and_irreversibility(): void
    {
        $this->makeMedia('image/png', '2026/10/b32-confirm.png', 'B32 删除确认图.png',
            ['alt' => 'B32 删除确认', 'width' => 10, 'height' => 10]);

        $html = $this->actingAs($this->admin)->get('/admin/media')->assertOk()->getContent();

        $this->assertStringContainsString('系统将阻止删除', $html);
        $this->assertStringContainsString('无引用时删除后不可恢复', $html);
        // 旧的单薄确认文案已替换
        $this->assertStringNotContainsString("confirm('删除该文件？')", $html);
    }

    // ---------------------------------------------------------------
    // B3-DISC-2：媒体 alt/title 编辑框占位中文化
    // ---------------------------------------------------------------

    public function test_media_alt_title_placeholders_are_chinese(): void
    {
        $this->makeMedia('image/png', '2026/10/b32-placeholder.png', 'B32 占位图.png',
            ['alt' => 'B32 已有替代文本', 'width' => 10, 'height' => 10]);

        $html = $this->actingAs($this->admin)->get('/admin/media')->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="替代文本（alt，无障碍 / SEO）"', $html);
        $this->assertStringContainsString('placeholder="标题（可空）"', $html);
        // 旧英文占位不再出现
        $this->assertStringNotContainsString('placeholder="alt 描述"', $html);
        $this->assertStringNotContainsString('placeholder="title（可空）"', $html);
    }

    // ---------------------------------------------------------------
    // C038：事实列表与事实编辑两处告警统一为「事实」
    // ---------------------------------------------------------------

    public function test_fact_alerts_use_chinese_word_not_english_fact(): void
    {
        $index = $this->actingAs($this->admin)->get('/admin/facts')->assertOk()->getContent();
        $create = $this->actingAs($this->admin)->get('/admin/facts/create')->assertOk()->getContent();

        foreach (['事实列表' => $index, '事实编辑' => $create] as $page => $html) {
            $this->assertStringContainsString('事实用于', $html, "{$page} 告警应以「事实用于」开头");
            $this->assertStringContainsString('修改事实不会直接改变', $html, "{$page} 应写「修改事实」");
            $this->assertStringNotContainsString('Fact 用于', $html, "{$page} 不应再有英文 Fact 用于");
            $this->assertStringNotContainsString('修改 Fact', $html, "{$page} 不应再有「修改 Fact」");
        }
    }

    // ---------------------------------------------------------------
    // C045：登录页副标题与 title 统一产品称谓
    // ---------------------------------------------------------------

    public function test_login_subtitle_unifies_product_name_with_title(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('<title>登录 · GEO Website OS 后台</title>', $html);
        $this->assertStringContainsString('GEO Website OS · 多站点内容管理后台', $html);
        // 旧的不一致称谓已消失
        $this->assertStringNotContainsString('多站点 GEO 内容管理系统', $html);
    }
}
