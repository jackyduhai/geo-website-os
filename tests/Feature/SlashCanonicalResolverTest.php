<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalizeSlash;
use App\Models\Category;
use App\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 动态路由尾斜杠实体判定回归（v0.9.37 验收审计）。
 *
 * 背景：knowledge.channel 路由同时承载频道列表（目录型，带斜杠）与扁平文章
 * （详情型，无斜杠）；page 统一分发器同时承载栏目页与文章页。固定路由的
 * _slash 默认无法表达这种差异，曾导致「canonical 声明的 URL 自身 301」矛盾
 * （知识文章被强制补斜杠、新闻栏目被强制去斜杠）。
 *
 * 真实 HTTP 的 301 方向由 CanonicalizeSlashTest 纯规则 + curl 实测锁定；
 * 此处锁定实体级判定 resolveWantsSlash()，保证与 PageController 匹配顺序一致。
 */
class SlashCanonicalResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_published_knowledge_article_is_detail_type_without_slash(): void
    {
        $article = Content::where('slug', 'how-to-choose-industrial-coatings')->firstOrFail();

        $this->assertFalse(CanonicalizeSlash::resolveWantsSlash('/knowledge/'.$article->slug));
        $this->assertFalse(CanonicalizeSlash::resolveWantsSlash('knowledge/'.$article->slug.'/'));
    }

    public function test_active_list_category_is_directory_type_with_slash(): void
    {
        // 新闻栏目是启用的 list 型栏目，规范地址 /news/ 带尾斜杠
        $this->assertTrue(CanonicalizeSlash::resolveWantsSlash('/news'));
        $this->assertTrue(CanonicalizeSlash::resolveWantsSlash('/news/'));
    }

    public function test_unknown_path_resolves_to_null_so_controller_returns_404(): void
    {
        $this->assertNull(CanonicalizeSlash::resolveWantsSlash('/knowledge/no-such-slug'));
        $this->assertNull(CanonicalizeSlash::resolveWantsSlash('/totally-fake-path'));
    }

    public function test_inactive_category_is_not_directory(): void
    {
        // 已停用的顶级栏目不应被中间件当作目录型补斜杠（判定时只查 is_active 实体）
        $about = new Category(['name' => '关于我们', 'slug' => 'about', 'type' => 'list']);
        $about->is_active = false;
        $about->save();

        $this->assertFalse($about->is_active);
        $this->assertNull(CanonicalizeSlash::resolveWantsSlash('/about'));
    }
}
