<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C047 特性网格图标可视化网格预览（Batch 5）。
 *
 * B3-3 已完成 feature_grid 17 个图标的“中文名显示 + 英文 key 提交”；C047 在此基础上
 * 把普通下拉升级为可视化图标网格（复用 C026 共享 <dialog> 风格）：每个图标显示真实 SVG
 * 与中文名，点选后回填该行隐藏的原生 <select>，并在行内显示当前图标。
 *
 * 不可变契约（本测试守护）：
 *  - 承载提交值的仍是原生 <select>，字段名 field[items][ri][icon]、17 个英文 canonical key、
 *    中文 label 与 B3-3 完全一致；保存仍由 extractItems() 默认 trim 落英文 key 字符串；
 *  - 网格 17 项（含 default）均可预览：每项含真实 SVG、中文显示名、英文 data-icon-key；
 *  - 不新增清空选项；不新增路由 / AJAX / 后端逻辑 / 模型 / 迁移；
 *  - 动态新增行（proto-items 模板）与已存在行同构，逐行独立、不串值（运行时行为另以浏览器 E2E 举证）；
 *  - 选择 / 关闭按钮 type=button 且无 name，不意外提交外层表单；
 *  - 只有 feature_grid 的 items[].icon 出现图标网格；hero variant 等其他 select、
 *    stats/faq/testimonial/logo_cloud 等其他重复行区块不渲染图标网格弹窗。
 *
 * 弹窗打开、点击回填、addRepeat 动态行的真实 JS 运行时行为不在此执行，须在实施 Gate
 * 以真实浏览器 E2E 补足；此处覆盖“结构契约 + 提交落库 + 前台渲染”。
 */
class BlockIconGridPickerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $default;

    /** @var array<string,string> 17 个 canonical icon key => 权威中文显示名（与 config/icons.php 一致） */
    private const ICONS = [
        'sparkle' => '星芒（特色亮点）', 'shield' => '品质保障', 'sliders' => '滑块（参数定制）',
        'package' => '包装（成品交付）', 'gear' => '设备工艺', 'check' => '对勾（确认）',
        'award' => '荣誉资质', 'clock' => '时效交付', 'users' => '服务客户', 'truck' => '物流配送',
        'leaf' => '叶片（环保/天然）', 'flask' => '烧瓶（实验研发）', 'beaker' => '烧杯（实验检测）',
        'chart' => '数据统计', 'star' => '星级推荐', 'doc' => '资料文档', 'default' => '默认通用',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Catalog::flush();
        PageCache::flush();
    }

    private function makePage(string $slug): Page
    {
        return Page::create([
            'site_id'  => $this->default->id,
            'template' => 'landing',
            'slug'     => $slug,
            'title'    => 'C047 组合页',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ]);
    }

    /** @param array<string,mixed> $content */
    private function makeBlock(Page $page, string $type, array $content = []): PageBlock
    {
        return PageBlock::create([
            'site_id'   => $page->site_id,
            'page'      => 'page',
            'page_id'   => $page->id,
            'slot'      => 'main',
            'type'      => $type,
            'sort'      => 0,
            'is_active' => true,
            'content'   => json_encode($content, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function editHtml(Page $page, PageBlock $block): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $block]))
            ->assertOk()
            ->getContent();
    }

    // 1) 图标网格弹窗：恰好 17 项，每项含英文 key、中文显示名与真实 SVG（可预览）。
    public function test_icon_grid_dialog_lists_17_icons_with_key_chinese_and_svg(): void
    {
        $page = $this->makePage('c047-grid');
        $block = $this->makeBlock($page, 'feature_grid');

        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('id="icon-picker-dialog"', $html);
        $this->assertSame(17, substr_count($html, 'class="icon-picker-item"'), '网格应恰好 17 个图标');

        foreach (self::ICONS as $key => $zh) {
            $this->assertStringContainsString('data-icon-key="' . $key . '"', $html, "图标 {$key} 缺英文 key");
            $this->assertStringContainsString('data-icon-label="' . $zh . '"', $html, "图标 {$key} 缺中文显示名");
            $this->assertStringNotContainsString('data-icon-key="' . $zh . '"', $html, "中文名不得作为 key");
        }

        // 网格内真实 SVG：shield 的稳定 path（证明预览的是实际图标而非占位）。
        $this->assertMatchesRegularExpression(
            '/id="icon-picker-dialog"[\s\S]*?M12 3l7 3/',
            $html,
            '弹窗网格应渲染真实图标 SVG'
        );
    }

    // 2) 动态行模板 proto-items：隐藏原生 select 承载提交值，17 个英文 value / 中文 label，
    //    value 绝不能是中文（B3-3 契约保持），字段名含 __IDX__ 占位。
    public function test_proto_row_keeps_hidden_select_with_english_values_and_chinese_labels(): void
    {
        $page = $this->makePage('c047-proto');
        $block = $this->makeBlock($page, 'feature_grid');

        $html = $this->editHtml($page, $block);

        $this->assertMatchesRegularExpression(
            '/<template id="proto-items">[\s\S]*?<select[^>]*name="field\[items\]\[__IDX__\]\[icon\]"[^>]*data-icon-select[\s\S]*?<\/template>/',
            $html
        );

        foreach (self::ICONS as $key => $zh) {
            $this->assertStringContainsString('<option value="' . $key . '"', $html, "提交值应为英文 key {$key}");
            $this->assertStringContainsString('>' . $zh . '</option>', $html, "option 应显示中文 {$zh}");
            $this->assertStringNotContainsString('value="' . $zh . '"', $html, "中文名不得作为 option value");
        }
    }

    // 3) 已保存图标行：行内当前图标显示真实 SVG + 中文名，隐藏 select 对应 option 为 selected。
    public function test_saved_icon_row_echoes_current_svg_chinese_and_selected_option(): void
    {
        $page = $this->makePage('c047-echo');
        $block = $this->makeBlock($page, 'feature_grid', [
            'title' => '能力特色',
            'items' => [['icon' => 'truck', 'title' => '快速交付', 'text' => '说明']],
        ]);

        $html = $this->editHtml($page, $block);

        // 行内当前图标：data-icon-current 之后是 truck 的真实 SVG 与中文“物流配送”。
        $this->assertMatchesRegularExpression(
            '/data-icon-current[\s\S]{0,700}?M3 6h11v9H3z/',
            $html,
            '行内应回显已选 truck 的真实 SVG'
        );
        $this->assertMatchesRegularExpression(
            '/data-icon-current[\s\S]{0,700}?data-icon-label[^>]*>物流配送</',
            $html,
            '行内应回显已选图标中文“物流配送”'
        );
        // 隐藏 select 的 truck option 选中。
        $this->assertMatchesRegularExpression('/<option value="truck"[^>]*selected/', $html);
        // 选择按钮存在且是 type=button。
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*data-icon-open[^>]*>选择图标<\/button>/', $html);
    }

    // 4) 真实表单提交：选择图标落库为英文 key 字符串（非中文），刷新回显，前台渲染对应 SVG。
    public function test_save_icon_persists_english_key_and_frontend_renders_svg(): void
    {
        $page = $this->makePage('c047-save');
        $block = $this->makeBlock($page, 'feature_grid');

        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => ['title' => '能力特色', 'subtitle' => '', 'columns' => 3,
                'items' => [
                    ['icon' => 'truck', 'title' => '快速交付', 'text' => '说明'],
                    ['icon' => 'star', 'title' => '星级推荐', 'text' => '说明'],
                ]],
        ])->assertRedirect();

        $cfg = PageBlock::find($block->id)->cfg();
        $this->assertSame('truck', $cfg['items'][0]['icon']);
        $this->assertSame('star', $cfg['items'][1]['icon']);
        $this->assertNotSame('物流配送', $cfg['items'][0]['icon']);

        // 前台按英文 key 取 _icon 渲染：truck 与 star 的稳定 path。
        $front = $this->get('/' . $page->slug)->assertOk()->getContent();
        $this->assertStringContainsString('快速交付', $front);
        $this->assertStringContainsString('M3 6h11v9H3z', $front);   // truck
        $this->assertStringContainsString('M12 3.5l2.5 5.2', $front); // star
    }

    // 5) 动态新增行模板与已存在行同构：proto 内图标单元格含选择按钮与绑定容器（__IDX__）。
    public function test_add_row_template_icon_cell_is_indexed_and_complete(): void
    {
        $page = $this->makePage('c047-addrow');
        $block = $this->makeBlock($page, 'feature_grid');

        $html = $this->editHtml($page, $block);

        $this->assertMatchesRegularExpression(
            '/<template id="proto-items">[\s\S]*?data-icon-bound[\s\S]*?data-icon-open[\s\S]*?<\/template>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="field\[items\]\[__IDX__\]\[icon\]"/',
            $html
        );
    }

    // 6) 选择 / 关闭按钮均为 type=button 且无 name，不会成为提交字段或意外提交外层表单。
    public function test_icon_buttons_are_type_button_and_not_named(): void
    {
        $page = $this->makePage('c047-btns');
        $block = $this->makeBlock($page, 'feature_grid');

        $html = $this->editHtml($page, $block);

        preg_match_all('/<button[^>]*data-icon-(?:open|close)[^>]*>/', $html, $buttons);
        $this->assertNotEmpty($buttons[0], '应有选择/关闭按钮');
        foreach ($buttons[0] as $tag) {
            $this->assertStringContainsString('type="button"', $tag);
            $this->assertStringNotContainsString('name=', $tag);
        }
    }

    // 7) 空 feature_grid 编辑页可正常渲染（弹窗 + 模板行齐全），不报错。
    public function test_empty_feature_grid_renders_dialog_and_template_without_error(): void
    {
        $page = $this->makePage('c047-empty');
        $block = $this->makeBlock($page, 'feature_grid');

        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('id="icon-picker-dialog"', $html);
        $this->assertSame(17, substr_count($html, 'class="icon-picker-item"'));
        $this->assertMatchesRegularExpression('/name="field\[items\]\[__IDX__\]\[icon\]"/', $html);
    }

    // 8) 局部性：hero variant（关联数组 select）、stats/faq/testimonial/logo_cloud 等区块
    //    不渲染图标网格弹窗，也不出现图标绑定单元格（仅 feature_grid 有）。
    public function test_other_blocks_do_not_render_icon_grid(): void
    {
        $page = $this->makePage('c047-other');

        foreach (['hero', 'stats', 'faq', 'testimonial', 'logo_cloud'] as $type) {
            $block = $this->makeBlock($page, $type);
            $html = $this->editHtml($page, $block);

            $this->assertStringNotContainsString('id="icon-picker-dialog"', $html, "{$type} 不应渲染图标网格弹窗");
            $this->assertStringNotContainsString('data-icon-bound', $html, "{$type} 不应出现图标选择单元格");
            $this->assertStringNotContainsString('field[items][__IDX__][icon]', $html, "{$type} 不应有 icon 列");
        }
    }

    // 9) 无值/异常值：已保存的图标 key 不在枚举内时，行内预览安全回退到第一个枚举，不报错。
    public function test_unknown_saved_icon_falls_back_safely_in_edit_form(): void
    {
        $page = $this->makePage('c047-fallback');
        $block = $this->makeBlock($page, 'feature_grid', [
            'title' => '能力特色',
            'items' => [['icon' => 'not-a-real-icon', 'title' => '异常图标', 'text' => '']],
        ]);

        $html = $this->editHtml($page, $block);

        // 页面正常渲染，行内回退第一个枚举 sparkle，且不把非法 key 标为 selected。
        $this->assertMatchesRegularExpression('/data-icon-current[\s\S]{0,700}?data-icon-label[^>]*>星芒（特色亮点）</', $html);
        $this->assertStringNotContainsString('<option value="not-a-real-icon"', $html);
    }
}
