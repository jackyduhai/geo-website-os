<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3-3 区块与配置术语（第 3 批 · 工作包 3）。
 *
 * 核心是 B3-DISC-1 方案 A 的真实功能修复：区块编辑表单的两个 select 渲染模板
 * （fields/select.blade.php 与 _item_cells.blade.php）此前对「关联数组 options」
 * 错误地把中文 label 当作 option value 提交，导致 Hero 选「居中文字」后实际存入
 * 中文、被 CompositionPolicy 白名单拒绝并静默回退 split。
 *
 * 本测试覆盖：
 *  - 两个 select 模板对「索引数组 / 关联数组」两种 options 的渲染契约；
 *  - Hero 真实表单选「居中文字」→ 提交英文 center → 回显 → 前台 hero-center（不回退 split）；
 *  - C047 特性网格 17 个图标中文显示、英文 key 提交、前台 SVG 正常；
 *  - C023 组合页变体下拉中文显示 / 英文 value；
 *  - C022 GEO 语义常驻图例（受控词 chip 保持英文）；
 *  - G2/C025 数据源方式 label 与模式按钮中文、提交英文枚举；
 *  - C019 表单字段编辑结构标签中文化；
 *  - C027/C028 站点设置 seeder 中文化且不覆盖自定义 value。
 */
class Batch3BlocksConfigTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $default;

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
            'title'    => 'B3-3 组合页',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ]);
    }

    /**
     * @param  array<string,mixed>  $content
     */
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

    // =============================================================
    // B3-DISC-1 契约 1：fields/select.blade.php 对两种 options 的渲染
    // =============================================================

    public function test_field_select_template_indexed_options_uses_value_as_label(): void
    {
        $html = view('admin.pages.fields.select', [
            'key' => 'k',
            'cfg' => ['k' => 'b'],
            'f'   => ['options' => ['a', 'b']],
        ])->render();

        // 索引数组：元素值既是提交值也是显示
        $this->assertStringContainsString('<option value="a"', $html);
        $this->assertStringContainsString('<option value="b"', $html);
        $this->assertSame(2, substr_count($html, '<option'));
    }

    public function test_field_select_template_associative_options_separates_key_and_label(): void
    {
        $html = view('admin.pages.fields.select', [
            'key' => 'variant',
            'cfg' => ['variant' => 'center'],
            'f'   => ['options' => ['split' => '左右分栏（文案 + 配图）', 'center' => '居中文字']],
        ])->render();

        // 关联数组：key 是受控英文提交值，值是中文显示
        $this->assertStringContainsString('<option value="split"', $html);
        $this->assertStringContainsString('<option value="center"', $html);
        $this->assertStringContainsString('左右分栏（文案 + 配图）', $html);
        $this->assertStringContainsString('居中文字', $html);
        // 绝不能把中文 label 当成 value
        $this->assertStringNotContainsString('value="居中文字"', $html);
        $this->assertStringNotContainsString('value="左右分栏', $html);
        // 已保存值 center 应回显选中
        $this->assertMatchesRegularExpression('/<option value="center"[^>]*selected/', $html);
    }

    // =============================================================
    // B3-DISC-1 契约 2：_item_cells.blade.php 对两种 options 的渲染
    // =============================================================

    public function test_item_cells_select_handles_indexed_and_associative_options(): void
    {
        $renderCells = function (array $itemFields, array $val): string {
            return view('admin.pages._item_cells', [
                'key' => 'items', 'ri' => 0, 'itemFields' => $itemFields, 'val' => $val,
            ])->render();
        };

        // 索引数组（向后兼容）：value 与显示都是元素值
        $indexed = $renderCells(
            [['key' => 'icon', 'type' => 'select', 'options' => ['sparkle', 'shield']]],
            ['icon' => 'shield']
        );
        $this->assertStringContainsString('name="field[items][0][icon]"', $indexed);
        $this->assertStringContainsString('<option value="shield"', $indexed);

        // 关联数组（C047 图标中文名）：value 是英文 key、显示中文
        $assoc = $renderCells(
            [['key' => 'icon', 'type' => 'select', 'options' => [
                'sparkle' => '星芒（特色亮点）', 'shield' => '品质保障',
            ]]],
            ['icon' => 'shield']
        );
        $this->assertStringContainsString('<option value="shield"', $assoc);
        $this->assertStringContainsString('品质保障', $assoc);
        $this->assertStringNotContainsString('value="品质保障"', $assoc);
        $this->assertMatchesRegularExpression('/<option value="shield"[^>]*selected/', $assoc);
    }

    // =============================================================
    // B3-DISC-1 端到端：Hero 真实表单保存「居中文字」
    // =============================================================

    public function test_hero_block_form_renders_chinese_label_with_english_option_value(): void
    {
        $page = $this->makePage('b33-hero-form');
        $hero = $this->makeBlock($page, 'hero', ['variant' => 'split', 'title' => 'T']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $hero]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<option value="split"', $html);
        $this->assertStringContainsString('<option value="center"', $html);
        $this->assertStringContainsString('居中文字', $html);
        // 修复前的缺陷：option value 是中文 label
        $this->assertStringNotContainsString('value="居中文字"', $html);
    }

    public function test_hero_save_center_persists_english_selected_and_renders_center(): void
    {
        $page = $this->makePage('b33-hero-center');
        $hero = $this->makeBlock($page, 'hero', ['variant' => 'split', 'title' => '旧标题']);

        // 按真实区块表单提交（浏览器提交的是 option value，即英文 center）
        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $hero]), [
            'field' => ['variant' => 'center', 'eyebrow' => '', 'title' => '居中首屏标题',
                'subtitle' => '副标题', 'buttons' => [], 'image_id' => null],
        ])->assertRedirect();

        // 1) 落库的是受控英文值，不是中文 label
        $saved = PageBlock::find($hero->id)->cfg();
        $this->assertSame('center', $saved['variant']);
        $this->assertNotSame('居中文字', $saved['variant']);

        // 2) 刷新编辑页仍回显「居中文字」且 center 选中
        $editHtml = $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $hero]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="center"[^>]*selected/', $editHtml);

        // 3) 前台真实呈现 hero-center，不再静默回退 split
        $front = $this->get('/b33-hero-center')->getContent();
        $this->assertStringContainsString('class="hero-center', $front);
        $this->assertStringNotContainsString('class="hero-split', $front);
        $this->assertStringContainsString('居中首屏标题', $front);
    }

    // =============================================================
    // C047：特性网格图标下拉 17 项中文显示 / 英文 key
    // =============================================================

    public function test_feature_grid_icon_select_shows_chinese_labels_with_english_keys(): void
    {
        $page = $this->makePage('b33-icon-form');
        $grid = $this->makeBlock($page, 'feature_grid');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $grid]))
            ->assertOk()->getContent();

        $expected = [
            'sparkle' => '星芒（特色亮点）', 'shield' => '品质保障', 'sliders' => '滑块（参数定制）',
            'package' => '包装（成品交付）', 'gear' => '设备工艺', 'check' => '对勾（确认）',
            'award' => '荣誉资质', 'clock' => '时效交付', 'users' => '服务客户', 'truck' => '物流配送',
            'leaf' => '叶片（环保/天然）', 'flask' => '烧瓶（实验研发）', 'beaker' => '烧杯（实验检测）',
            'chart' => '数据统计', 'star' => '星级推荐', 'doc' => '资料文档', 'default' => '默认通用',
        ];
        $this->assertCount(17, $expected);
        foreach ($expected as $key => $zh) {
            $this->assertStringContainsString('<option value="'.$key.'"', $html, "图标 key {$key} 应为提交值");
            $this->assertStringContainsString($zh, $html, "图标 {$key} 应显示中文");
            $this->assertStringNotContainsString('value="'.$zh.'"', $html, "中文名不得作为 value");
        }
    }

    public function test_feature_grid_save_icon_keeps_english_key_and_renders_svg(): void
    {
        $page = $this->makePage('b33-icon-save');
        $grid = $this->makeBlock($page, 'feature_grid');

        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $grid]), [
            'field' => ['title' => '能力特色', 'subtitle' => '', 'columns' => 3,
                'items' => [
                    ['icon' => 'shield', 'title' => '品质保障一', 'text' => '说明'],
                    ['icon' => 'check', 'title' => '确认交付', 'text' => '说明'],
                ]],
        ])->assertRedirect();

        $cfg = PageBlock::find($grid->id)->cfg();
        $this->assertSame('shield', $cfg['items'][0]['icon']);
        $this->assertSame('check', $cfg['items'][1]['icon']);

        // 前台以英文 key 取 _icon 渲染 SVG（shield / check 均为白名单图标）
        $front = $this->get('/b33-icon-save')->getContent();
        $this->assertStringContainsString('品质保障一', $front);
        $this->assertStringContainsString('feat-ic', $front);
        // shield 图标的稳定 path 片段
        $this->assertStringContainsString('M12 3l7 3', $front);
        // check 图标的圆形 path
        $this->assertStringContainsString('<circle cx="12" cy="12" r="9"', $front);
    }

    // =============================================================
    // C023：组合页变体下拉中文显示 / 英文 value
    // =============================================================

    public function test_composer_variant_select_chinese_label_english_value(): void
    {
        $page = $this->makePage('b33-composer-variant');
        $this->makeBlock($page, 'hero', ['variant' => 'center', 'title' => 'T']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.pages.composer', $page))->assertOk()->getContent();

        // 快速切换下拉走 setVariant 白名单，value 必须仍是英文
        $this->assertStringContainsString('<option value="split"', $html);
        $this->assertStringContainsString('<option value="center"', $html);
        $this->assertStringContainsString('居中文字', $html);
        $this->assertStringNotContainsString('value="居中文字"', $html);
    }

    // =============================================================
    // C022：GEO 语义常驻图例；chip 受控词保持英文
    // =============================================================

    public function test_composer_semantic_legend_lists_four_vocabularies_and_chips_stay_english(): void
    {
        $page = $this->makePage('b33-legend');
        $this->makeBlock($page, 'hero', ['variant' => 'split', 'title' => 'T']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.pages.composer', $page))->assertOk()->getContent();

        // 图例四类标题齐全
        $this->assertStringContainsString('Section 叙事角色', $html);
        $this->assertStringContainsString('Purpose 沟通目的', $html);
        $this->assertStringContainsString('Entity 关联实体', $html);
        $this->assertStringContainsString('Conversion 转化行为', $html);
        // 抽查官方中文释义（取自 geo-section-semantic-standard §3）
        $this->assertStringContainsString('首屏价值主张', $html);   // section hero
        $this->assertStringContainsString('直接推动一次转化动作', $html); // purpose conversion
        $this->assertStringContainsString('Organization', $html);
        $this->assertStringContainsString('组织', $html);
        $this->assertStringContainsString('appointment', $html);
        $this->assertStringContainsString('预约', $html);

        // 实际区块的 chip 仍是受控英文（legend 与 chip 均输出英文 key）
        $this->assertStringContainsString('§ hero', $html);
        // 前台 data-section 仍输出英文受控值
        $front = $this->get('/b33-legend')->getContent();
        $this->assertStringContainsString('data-section="hero"', $front);
    }

    // =============================================================
    // G2 + C025：数据来源方式 label 与模式按钮中文 / 提交英文
    // =============================================================

    public function test_source_mode_buttons_chinese_but_submit_english_with_renamed_label(): void
    {
        $page = $this->makePage('b33-source');
        $grid = $this->makeBlock($page, 'product_grid');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $grid]))->assertOk()->getContent();

        // G2：字段标题改为「数据来源方式」
        $this->assertStringContainsString('数据来源方式', $html);
        // C025：五个模式按钮显示中文
        foreach (['全部', '按产品线/分组', '手动指定', '当前栏目', '关联推荐'] as $zh) {
            $this->assertStringContainsString('>'.$zh.'</button>', $html);
        }
        // hidden input 与 onclick 仍使用受控英文枚举
        $this->assertStringContainsString('id="src-mode-source"', $html);
        $this->assertStringContainsString("pickSource('source', 'line'", $html);
        $this->assertStringNotContainsString('>all</button>', $html);
        $this->assertStringNotContainsString('>line</button>', $html);

        // 保存 line 模式：落库仍是英文枚举
        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $grid]), [
            'field' => ['title' => '', 'subtitle' => '', 'limit' => 6,
                'source' => ['mode' => 'line', 'line' => 'series-a', 'ids' => '']],
        ])->assertRedirect();
        $source = PageBlock::find($grid->id)->cfg()['source'];
        $this->assertSame('line', $source['mode']);
        $this->assertSame('series-a', $source['line']);
    }

    // =============================================================
    // C019：表单字段编辑的结构标签中文化
    // =============================================================

    public function test_form_field_editor_structure_labels_are_chinese(): void
    {
        $form = Form::create(['slug' => 'b33-field-form', 'name' => 'B33 字段表单']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.forms.fields.create', $form))->assertOk()->getContent();

        $this->assertStringContainsString('字段标签 label', $html);
        $this->assertStringContainsString('占位提示 placeholder', $html);
        $this->assertStringContainsString('帮助说明 help text', $html);
        // 不再是裸英文 label / placeholder
        $this->assertStringNotContainsString('<label>label</label>', $html);
        $this->assertStringNotContainsString('<label>placeholder</label>', $html);
        // 提交键 name 仍是英文标识（不动）
        $this->assertStringContainsString('字段 name（英文标识，提交键）', $html);
    }

    // =============================================================
    // C027 / C028：站点设置 seeder 中文化、英文专名保留、不覆盖 value
    // =============================================================

    public function test_settings_seeder_chinese_labels_preserve_custom_values(): void
    {
        // 模拟已部署站点：运营已自定义若干 value
        Setting::where('key', 'theme_primary')->firstOrFail()->update(['value' => '#abcdef']);
        Setting::where('key', 'analytics_ga4_id')->firstOrFail()->update(['value' => 'G-TEST123']);

        // 重跑产品级 seeder（geo:upgrade 语义）
        $this->seed(DefaultSettingSeeder::class);

        $theme = Setting::where('key', 'theme_primary')->firstOrFail();
        $this->assertSame('品牌主色', $theme->label);
        $this->assertStringContainsString('Brand Seed', $theme->hint);
        $this->assertSame('#abcdef', $theme->value, '自定义品牌主色不得被 seeder 覆盖');

        $typo = Setting::where('key', 'theme_typography')->firstOrFail();
        $this->assertSame('排版气质', $typo->label);
        $this->assertStringContainsString('Typography Profile', $typo->hint);
        $this->assertSame('standard', $typo->value, '默认 value 不变');

        $ga4 = Setting::where('key', 'analytics_ga4_id')->firstOrFail();
        $this->assertStringContainsString('GA4 衡量 ID', $ga4->label);
        $this->assertStringContainsString('Measurement ID', $ga4->label);
        $this->assertSame('G-TEST123', $ga4->value, '已填 GA4 ID 不得被覆盖');

        $gtm = Setting::where('key', 'analytics_gtm_id')->firstOrFail();
        $this->assertStringContainsString('GTM 容器 ID', $gtm->label);
        $this->assertStringContainsString('内容安全策略 CSP nonce', $gtm->hint);

        $consent = Setting::where('key', 'analytics_consent_required')->firstOrFail();
        $this->assertStringContainsString('基础同意模式 Basic Consent Mode', $consent->hint);

        $meta = Setting::where('key', 'analytics_meta_id')->firstOrFail();
        $this->assertStringContainsString('Meta Pixel', $meta->label);
        // key 不变（受控英文）
        $this->assertSame('analytics_ga4_id', $ga4->key);
    }
}
