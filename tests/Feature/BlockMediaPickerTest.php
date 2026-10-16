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
 * C026 区块媒体选择器（Batch 5）。
 *
 * 区块编辑页的顶层 media 字段（hero.image_id / image.media_id / media_text.media_id）
 * 由“新窗口查看并手填 ID”改为页内共享媒体选择弹窗，选择后把媒体的数字 ID 回填到
 * 原有 name="field[<key>]" 数字输入。
 *
 * 不可变契约（本测试守护）：
 *  - 提交字段名 / 存储合同不变：空→null、非空→int，写入 PageBlock.content；
 *  - 引用扫描（MediaReferenceScanner）与删除阻断不受影响；
 *  - 媒体集合按当前站点隔离，“已选兜底”也不得凭 ID 跨站读取；
 *  - 不新增路由 / AJAX；选择 / 清除按钮 type=button，不引发意外提交；
 *  - 原有新窗口媒体库入口仍可用。
 *
 * 说明：本套件为 Laravel Feature（服务端 + 渲染结构契约）。浏览器内 <dialog>
 * 打开、点击回填、清除的真实 JS 运行时行为不在此执行，标记为 NOT VERIFIED，
 * 需人工浏览器验收；此处用“结构契约 + 最终提交落库链路”覆盖服务端合同。
 */
class BlockMediaPickerTest extends TestCase
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
            'title'    => 'C026 组合页',
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

    // 1) 三个顶层 media 字段都呈现选择器，且保留数字输入与新窗口入口。
    public function test_three_top_level_media_fields_render_picker_and_new_window_link(): void
    {
        $page = $this->makePage('c026-fields');
        $cases = [
            ['hero', 'image_id'],
            ['image', 'media_id'],
            ['media_text', 'media_id'],
        ];
        foreach ($cases as [$blockType, $fieldKey]) {
            $block = $this->makeBlock($page, $blockType);
            $html = $this->editHtml($page, $block);

            // 承载提交值的原有数字输入仍在（字段名 / 数字类型合同不变）。
            $this->assertMatchesRegularExpression(
                '/<input type="number" name="field\[' . preg_quote($fieldKey, '/') . '\]"/',
                $html,
                "{$blockType}.{$fieldKey} 应保留数字输入"
            );
            // 页内选择按钮 + 一个共享弹窗。
            $this->assertStringContainsString('id="media-picker-dialog"', $html);
            $this->assertStringContainsString('data-media-open="' . $fieldKey . '"', $html);
            // 原有新窗口媒体库入口保留。
            $this->assertStringContainsString(route('admin.media.index'), $html);
            $this->assertMatchesRegularExpression('/target="_blank"[^>]*rel="noopener"|rel="noopener"[^>]*target="_blank"/', $html);
        }
    }

    // 2) 空媒体库：弹窗空态可渲染，不报错，选择按钮与新窗口入口仍在。
    public function test_empty_library_renders_dialog_empty_state(): void
    {
        $page = $this->makePage('c026-empty');
        $block = $this->makeBlock($page, 'image');
        $html = $this->editHtml($page, $block);

        $this->assertStringContainsString('id="media-picker-dialog"', $html);
        $this->assertStringContainsString('当前站点还没有可选的图片媒体', $html);
        $this->assertStringContainsString('data-media-open="media_id"', $html);
        $this->assertStringContainsString(route('admin.media.index'), $html);
    }

    // 3) 站点隔离：只出现本站媒体；当前已选媒体在预览中回显。
    public function test_picker_is_site_isolated_and_selected_media_previewed(): void
    {
        $page = $this->makePage('c026-isolated');
        $mine = $this->makeImage('mine-a.jpg');
        $otherSite = Site::create(['slug' => 'other-c026', 'name' => 'Other', 'status' => Site::STATUS_ACTIVE]);
        $foreign = $this->makeImage('foreign-b.jpg', $otherSite->id);

        $block = $this->makeBlock($page, 'image', ['media_id' => $mine->id, 'alt' => '', 'caption' => '']);
        $html = $this->editHtml($page, $block);

        // 本站媒体在弹窗中，外站媒体绝不出现。
        $this->assertStringContainsString('data-media-id="' . $mine->id . '"', $html);
        $this->assertStringContainsString('mine-a.jpg', $html);
        $this->assertStringNotContainsString('data-media-id="' . $foreign->id . '"', $html);
        $this->assertStringNotContainsString('foreign-b.jpg', $html);

        // 已选媒体回显：输入值为该 ID，且预览区出现其文件名。
        $this->assertMatchesRegularExpression(
            '/id="media-input-media_id"[^>]*value="' . $mine->id . '"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="media-preview-media_id"[\s\S]*mine-a\.jpg/',
            $html
        );
    }

    // 4) 已选媒体即便不在“最近 24 个”，仍经兜底出现且不重复（去重）。
    public function test_selected_media_outside_recent_24_is_included_once(): void
    {
        $page = $this->makePage('c026-recent');
        $oldest = $this->makeImage('old-00.jpg');
        $oldest->forceFill(['created_at' => now()->subMinutes(30)])->save();
        for ($i = 1; $i <= 24; $i++) {
            $this->makeImage(sprintf('new-%02d.jpg', $i));
        }

        $block = $this->makeBlock($page, 'image', ['media_id' => $oldest->id, 'alt' => '', 'caption' => '']);
        $html = $this->editHtml($page, $block);

        // 最旧的已选媒体靠兜底出现；最近 24 个亦在；总计 25 个可选媒体（兜底 + 24，去重）。
        $this->assertStringContainsString('data-media-id="' . $oldest->id . '"', $html);
        $this->assertStringContainsString('old-00.jpg', $html);
        $this->assertSame(25, substr_count($html, 'class="media-picker-item"'));
    }

    // 5) 已选 ID 属于外站时，兜底查询受 SiteScope 约束，不跨站读出。
    public function test_cross_site_selected_id_is_not_leaked_into_preview(): void
    {
        $page = $this->makePage('c026-crossid');
        $otherSite = Site::create(['slug' => 'crossid-c026', 'name' => 'Cross', 'status' => Site::STATUS_ACTIVE]);
        $foreign = $this->makeImage('foreign-id.jpg', $otherSite->id);

        // 异常数据：当前站区块 content 里存了一个外站媒体 ID。
        $block = $this->makeBlock($page, 'image', ['media_id' => $foreign->id, 'alt' => '', 'caption' => '']);
        $html = $this->editHtml($page, $block);

        // 输入仍保留该数字（展示不掩盖数据），但预览/弹窗不得跨站读出外站文件名。
        $this->assertMatchesRegularExpression(
            '/id="media-input-media_id"[^>]*value="' . $foreign->id . '"/',
            $html
        );
        $this->assertStringNotContainsString('foreign-id.jpg', $html);
        $this->assertStringNotContainsString('data-media-id="' . $foreign->id . '"', $html);
    }

    // 6) 选择 → 回填 → 保存为整数 ID（并保留其他字段）；清除 → null；引用扫描不受影响。
    public function test_pick_and_clear_persist_integer_id_and_preserve_other_fields(): void
    {
        $page = $this->makePage('c026-save');
        $media = $this->makeImage('picked.jpg');
        $block = $this->makeBlock($page, 'media_text', [
            'media_id' => null, 'alt' => '旧 alt', 'title' => '保留标题',
            'body' => '正文', 'button_label' => '', 'button_url' => '', 'reverse' => false,
        ]);

        // 模拟浏览器选择器最终回填：提交 field[media_id]=所选 ID（数字字符串），其他字段原样。
        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => [
                'media_id' => (string) $media->id,
                'alt' => '新 alt', 'title' => '保留标题', 'body' => '正文',
                'button_label' => '', 'button_url' => '', 'reverse' => 0,
            ],
        ])->assertRedirect();

        $saved = PageBlock::find($block->id)->cfg();
        // 落库必须是整数 ID，不是字符串。
        $this->assertSame($media->id, $saved['media_id']);
        $this->assertIsInt($saved['media_id']);
        // 其他字段未被选择器交互影响。
        $this->assertSame('保留标题', $saved['title']);
        $this->assertSame('正文', $saved['body']);
        // 引用关系仍被扫描识别（删除阻断合同未破坏）。
        $this->assertTrue(MediaReferenceScanner::hasReferences($media));

        // 清除：提交空值 → 落库 null。
        $this->actingAs($this->admin)->put(route('admin.pages.updateBlock', [$page, $block]), [
            'field' => [
                'media_id' => '', 'alt' => '新 alt', 'title' => '保留标题', 'body' => '正文',
                'button_label' => '', 'button_url' => '', 'reverse' => 0,
            ],
        ])->assertRedirect();
        $cleared = PageBlock::find($block->id)->cfg();
        $this->assertNull($cleared['media_id']);
    }

    // 7) 选择 / 清除按钮均为 type=button 且无 name，不会成为提交字段或意外提交表单。
    public function test_picker_buttons_are_type_button_and_not_named_fields(): void
    {
        $page = $this->makePage('c026-btns');
        $this->makeImage('btn-a.jpg');
        $block = $this->makeBlock($page, 'image');
        $html = $this->editHtml($page, $block);

        // 选择按钮
        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="button"[^>]*data-media-open="media_id"/',
            $html
        );
        // 清除按钮
        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="button"[^>]*data-media-clear="media_id"/',
            $html
        );
        // 弹窗内媒体项是 button 且无 name（不会被提交为字段）。
        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="button"[^>]*class="media-picker-item"[^>]*data-media-id="\d+"/',
            $html
        );

        // 选择 / 清除 / 媒体项按钮都不携带表单 name 属性（data-media-name 是数据属性，
        // 不会被提交）；剔除 data-media-name 后不应再出现 name=。
        foreach (['data-media-open="media_id"', 'data-media-clear="media_id"'] as $needle) {
            preg_match_all('/<button[^>]*' . preg_quote($needle, '/') . '[^>]*>/', $html, $mm);
            $this->assertNotEmpty($mm[0]);
            foreach ($mm[0] as $tag) {
                $stripped = preg_replace('/data-media-name="[^"]*"/', '', $tag);
                $this->assertStringNotContainsString('name=', $stripped, "按钮 {$needle} 不应带 name");
            }
        }
        preg_match_all('/<button[^>]*class="media-picker-item"[^>]*>/', $html, $items);
        $this->assertNotEmpty($items[0]);
        foreach ($items[0] as $tag) {
            $stripped = preg_replace('/data-media-name="[^"]*"/', '', $tag);
            $this->assertStringNotContainsString('name=', $stripped, '媒体项按钮不应带 name');
        }
    }
}
