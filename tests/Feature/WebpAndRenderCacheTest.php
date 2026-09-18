<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Support\ImageOptimizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v0.9.18 渐进增强回归：
 *  - WebP 兄弟文件存在时 webpUrl() 正确映射；缺失/外链/空值返回 null（前端据此回退原图，绝不裂图）
 *  - 知识正文 Markdown 渲染缓存 bodyHtml() 输出与 Str::markdown 完全一致
 */
class WebpAndRenderCacheTest extends TestCase
{
    public function test_webp_url_maps_only_when_sibling_exists(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');

        $this->assertNull(ImageOptimizer::webpUrl('/storage/blocks/a.jpg'), '无 webp 兄弟时返回 null');
        $this->assertNull(ImageOptimizer::webpUrl('/img/logo.png'), '非 /storage 路径不处理');
        $this->assertNull(ImageOptimizer::webpUrl('https://cdn.example.com/x.jpg'), '外链不处理');
        $this->assertNull(ImageOptimizer::webpUrl(null));

        $disk->put('blocks/a.jpg', 'jpeg-bytes');
        $disk->put('blocks/a.webp', 'webp-bytes');
        $this->assertSame('/storage/blocks/a.webp', ImageOptimizer::webpUrl('/storage/blocks/a.jpg'));

        $disk->put('media/202609/b.PNG', 'png');
        $disk->put('media/202609/b.webp', 'webp');
        $this->assertSame('/storage/media/202609/b.webp', ImageOptimizer::webpUrl('/storage/media/202609/b.PNG'));
    }

    public function test_content_body_html_equals_markdown_parse(): void
    {
        $md = "## 标题\n\n这是**正文**段落。\n\n- 项目一\n- 项目二\n";
        $content = new Content(['title' => '渲染缓存测试', 'body' => $md]);

        $expected = (string) Str::markdown($md);
        $this->assertSame($expected, $content->bodyHtml());
        $this->assertStringContainsString('<strong>正文</strong>', $content->bodyHtml());

        // 空正文安全返回空串
        $empty = new Content(['body' => '']);
        $this->assertSame('', $empty->bodyHtml());
    }
}
