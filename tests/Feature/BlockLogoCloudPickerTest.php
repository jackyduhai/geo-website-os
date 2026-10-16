<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\MediaReferenceScanner;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C026b Logo 墙重复行媒体选择器（Batch 5）。
 *
 * 真实区块类型 key 为 logo_cloud（label「Logo 墙」），其 items 重复行的 media_id
 * 此前在 _item_cells.blade.php 是与 number 共用的纯数字框，无选择 / 预览 / 媒体库入口。
 * C026b 为每一行复用 C026 共享弹窗，加入选择 / 清除按钮与 40px 行内缩略图。
 *
 * 不可变契约（本测试守护）：
 *  - 提交字段名仍为 field[items][ri][media_id]，存储合同不变：非空→int；
 *  - 不改 extractItems / 引用扫描 / 删除阻断 / 前台渲染；
 *  - 动态行模板 proto-items 内的 media 单元格与已有行同构（__IDX__ 占位）；
 *  - 每行操作只影响所属行，不串行、不串值；
 *  - 媒体集合按当前站点隔离，items 已选兜底也不得跨站读取；
 *  - 选择 / 清除按钮 type=button 且无 name，不意外提交；
 *  - feature_grid / stats / faq / testimonial 等其他重复行区块不出现媒体选择器。
 *
 * 浏览器内 <dialog> 打开、点击回填、addRepeat 动态行的真实 JS 运行时行为不在此执行，
 * 须在实施 Gate 以真实浏览器 E2E 补足；此处覆盖“结构契约 + 提交落库链路”。
 */
class BlockLogoCloudPickerTest extends TestCase
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
            'title'    => 'C026b 组合页',
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

    private function makeImage(string $name, ?int $siteId = null): Media
    {
        return Media::create([
            'site_id'       => $siteId ?? $this->default->id,
            'disk'          => 'public',
            'path'          => 'media/202610/' . $name,
            'original_name' => $name,
            'mime'          => 'image/jpeg',
            'size'          => 12345,
            'width'         => 800,
            'height'        => 600,
            'alt'           => 'fixture-' . $name,
        ]);
    }

    private function editHtml(Page $page, PageBlock $block): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.pages.editBlock', [$page, $block]))
            ->assertOk()
            ->getContent();
    }

    // 1) 已选 logo 行：保留数字输入与提交名，呈现选择/清除按钮与 40px 行内缩略图回显。
    public function test_selected_logo_row_renders_inputs_buttons_and_inline_thumb(): void
    {
        $page = $this->makePage('c026b-row');
        $logo = $this->makeImage('logo-a.jpg');
        $block = $this->makeBlock($page, 'logo_cloud', [
            'title' => '合作伙伴',
            'items' => [['media_id' => $logo->id, 'name' => '客户 A']],
        ]);

        $html = $this->editHtml($page, $block);

        // 承载提交值的数字输入与字段名不变，值为该 ID。
        $this->assertMatchesRegularExpression(
            '/<input type="number"[^>]*name="field\[items\]\[0\]\[media_id\]"[^>]*value="' . $logo->id . '"/',
            $html
        );
        // 名称列输入仍在。
        $this->assertMatchesRegularExpression(
            '/name="field\[items\]\[0\]\[name\]"[^>]*value="客户 A"/',
            $html
        );
        // 行容器与选择 / 清除按钮（无值属性，纯事件委托）。
        $this->assertStringContainsString('media-picker-cell', $html);
        $this->assertStringContainsString('data-media-bound', $html);
        $this->assertMatchesRegularExpression(
            '/<button type="button"[^>]*data-media-open[^>]*>选择<\/button>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<button type="button"[^>]*data-media-clear[^>]*>清除<\/button>/',
            $html
        );
        // 40px 行内缩略图与已选文件名回显。
        $this->assertMatchesRegularExpression(
            '/media-picker-thumb-sm[\s\S]{0,400}?logo-a\.jpg/',
            $html
        );
    }

    // 2) 新增行模板 proto-items：media 单元格同构，name 为 __IDX__ 占位，含选择按钮。
    public function test_add_row_template_contains_indexed_media_cell(): void
    {
        $page = $this->makePage('c026b-proto');
        $block = $this->makeBlock($page, 'logo_cloud');

        $html = $this->editHtml($page, $block);

        $this->assertMatchesRegularExpression(
            '/name="field\[items\]\[__IDX__\]\[media_id\]"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<template id="proto-items">[\s\S]*?data-media-open[\s\S]*?<\/template>/',
            $html
        );
        // 无已有行时，仍有一个模板单元格；页面内无已选预览卡片。
        $this->assertSame(1, substr_count($html, 'data-media-bound'));
        $this->assertStringContainsString('id="media-picker-dialog"', $html);
    }

    // 3) 空媒体库：logo_cloud 编辑页可正常渲染，弹窗空态不报错。
    public function test_empty_library_renders_logo_cloud_without_error(): void
    {
        $page = $this->makePage('c026b-empty');
        $block = $this->makeBlock($page, 'logo_cloud');

        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('id="media-picker-dialog"', $html);
        $this->assertStringContainsString('当前站点还没有可选的图片媒体', $html);
        $this->assertStringContainsString('data-media-open', $html);
    }

    // 4) 站点隔离：本站 logo 出现在弹窗且行内回显，外站 logo 绝不出现。
    public function test_logo_picker_is_site_isolated(): void
    {
        $page = $this->makePage('c026b-isolated');
        $mine = $this->makeImage('logo-mine.jpg');
        $otherSite = Site::create(['slug' => 'other-c026b', 'name' => 'Other', 'status' => Site::STATUS_ACTIVE]);
        $foreign = $this->makeImage('logo-foreign.jpg', $otherSite->id);

        $block = $this->makeBlock($page, 'logo_cloud', [
            'title' => '',
            'items' => [['media_id' => $mine->id, 'name' => '本站']],
        ]);
        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('data-media-id="' . $mine->id . '"', $html);
        $this->assertStringContainsString('logo-mine.jpg', $html);
        $this->assertStringNotContainsString('data-media-id="' . $foreign->id . '"', $html);
        $this->assertStringNotContainsString('logo-foreign.jpg', $html);
    }

    // 5) 已选 logo 不在“最近 24 个”时，仍经 items 兜底出现且只出现一次（去重）。
    public function test_selected_logo_outside_recent_24_is_included_once(): void
    {
        $page = $this->makePage('c026b-recent');
        $oldest = $this->makeImage('logo-old-00.jpg');
        $oldest->forceFill(['created_at' => now()->subMinutes(30)])->save();
        for ($i = 1; $i <= 24; $i++) {
            $this->makeImage(sprintf('logo-new-%02d.jpg', $i));
        }

        $block = $this->makeBlock($page, 'logo_cloud', [
            'title' => '',
            'items' => [['media_id' => $oldest->id, 'name' => '老客户']],
        ]);
        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('data-media-id="' . $oldest->id . '"', $html);
        $this->assertStringContainsString('logo-old-00.jpg', $html);
        // 兜底的 1 个 + 最近 24 个，去重后共 25 个可选媒体。
        $this->assertSame(25, substr_count($html, 'class="media-picker-item"'));
    }

    // 6) 行内已选 ID 属于外站时，兜底查询受 SiteScope 约束，输入保留数字但不跨站读出。
    public function test_cross_site_logo_id_is_not_leaked_into_preview(): void
    {
        $page = $this->makePage('c026b-crossid');
        $otherSite = Site::create(['slug' => 'crossid-c026b', 'name' => 'Cross', 'status' => Site::STATUS_ACTIVE]);
        $foreign = $this->makeImage('logo-foreign-id.jpg', $otherSite->id);

        $block = $this->makeBlock($page, 'logo_cloud', [
            'title' => '',
            'items' => [['media_id' => $foreign->id, 'name' => '外站']],
        ]);
        $html = $this->editHtml($page, $block);

        // 数字输入保留该 ID（不掩盖数据），但行内预览 / 弹窗不读出外站文件。
        $this->assertMatchesRegularExpression(
            '/name="field\[items\]\[0\]\[media_id\]"[^>]*value="' . $foreign->id . '"/',
            $html
        );
        $this->assertStringNotContainsString('logo-foreign-id.jpg', $html);
        $this->assertStringNotContainsString('data-media-id="' . $foreign->id . '"', $html);
    }

    // 7) 多行保存：每行 media_id 独立落整数、不串值；引用扫描逐行识别；清除首列空行被既有规则剔除。
    public function test_multiple_rows_persist_distinct_integer_ids_and_scan_finds_each(): void
    {
        $page = $this->makePage('c026b-save');
        $m1 = $this->makeImage('logo-row-1.jpg');
        $m2 = $this->makeImage('logo-row-2.jpg');
        $block = $this->makeBlock($page, 'logo_cloud');

        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => [
                'title' => '合作伙伴',
                'items' => [
                    ['media_id' => (string) $m1->id, 'name' => '甲'],
                    ['media_id' => (string) $m2->id, 'name' => '乙'],
                ],
            ],
        ])->assertRedirect();

        $saved = PageBlock::find($block->id)->cfg();
        $this->assertCount(2, $saved['items']);
        $this->assertSame($m1->id, $saved['items'][0]['media_id']);
        $this->assertIsInt($saved['items'][0]['media_id']);
        $this->assertSame('甲', $saved['items'][0]['name']);
        $this->assertSame($m2->id, $saved['items'][1]['media_id']);
        $this->assertIsInt($saved['items'][1]['media_id']);
        $this->assertSame('乙', $saved['items'][1]['name']);

        // 引用扫描对两个 logo 均生效（删除阻断未退化）。
        $this->assertTrue(MediaReferenceScanner::hasReferences($m1));
        $this->assertTrue(MediaReferenceScanner::hasReferences($m2));

        // 清除第一行 Logo（首列 media_id 为空，name 保留）：按既有 extractItems 空行规则
        // 该行被整体跳过（logo_cloud 首列即 media_id，无 Logo 的行前台本就不渲染）；
        // 第二行保持不变。
        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => [
                'title' => '合作伙伴',
                'items' => [
                    ['media_id' => '', 'name' => '甲'],
                    ['media_id' => (string) $m2->id, 'name' => '乙'],
                ],
            ],
        ])->assertRedirect();
        $after = PageBlock::find($block->id)->cfg();
        $this->assertCount(1, $after['items']);
        $this->assertSame($m2->id, $after['items'][0]['media_id']);
        // 被清除的 m1 不再被本区块引用。
        $this->assertFalse(MediaReferenceScanner::hasReferences($m1));
    }

    // 8) 行内选择 / 清除按钮 type=button 且无 name，不会成为提交字段或意外提交。
    public function test_row_buttons_are_type_button_and_not_named(): void
    {
        $page = $this->makePage('c026b-btns');
        $block = $this->makeBlock($page, 'logo_cloud');

        $html = $this->editHtml($page, $block);

        preg_match_all('/<button[^>]*data-media-open[^>]*>/', $html, $open);
        preg_match_all('/<button[^>]*data-media-clear[^>]*>/', $html, $clear);
        $this->assertNotEmpty($open[0]);
        $this->assertNotEmpty($clear[0]);
        foreach (array_merge($open[0], $clear[0]) as $tag) {
            $this->assertStringContainsString('type="button"', $tag);
            $this->assertStringNotContainsString('name=', $tag);
        }
    }

    // 9) 其他含 items 的区块（feature_grid/stats/faq/testimonial）不渲染媒体选择器。
    public function test_other_repeat_blocks_do_not_render_media_picker(): void
    {
        $page = $this->makePage('c026b-other');
        $this->makeImage('should-not-open.jpg');

        foreach (['feature_grid', 'stats', 'faq', 'testimonial'] as $type) {
            $block = $this->makeBlock($page, $type);
            $html = $this->editHtml($page, $block);

            // data-media-bound 仅出现在真实渲染的 media 单元格（不在共享 CSS/JS 中），
            // 是“有没有 media 选择单元格”的可靠标记；media_id 列名同理。
            $this->assertStringNotContainsString('data-media-bound', $html, "{$type} 不应出现重复行媒体单元格");
            $this->assertStringNotContainsString('field[items][0][media_id]', $html, "{$type} 不应有 media_id 列");
            $this->assertStringNotContainsString('field[items][__IDX__][media_id]', $html, "{$type} 模板不应有 media_id 列");
        }
    }
}
