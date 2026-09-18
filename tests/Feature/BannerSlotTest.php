<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首页两个 Banner 投放位前后台对齐（均在「首页装修」内就地维护）：
 *  - home_top：首屏 B/C 模式（见 HeroModeTest）
 *  - home_mid：首页中部横幅装修区块（mid_banner）
 * 有启用且有图才渲染；无图不占位。（v0.9.8 起移除独立 Banner 模块与未使用的 category_top 栏目横幅）
 */
class BannerSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeBanner(string $position, string $url = 'http://example.com/x.jpg'): Banner
    {
        $media = Media::create(['disk' => 'public', 'path' => $url, 'mime' => 'image/jpeg']);

        return Banner::create([
            'position' => $position, 'image_id' => $media->id,
            'is_active' => true, 'sort' => 0, 'title' => '', 'subtitle' => '',
        ]);
    }

    public function test_home_mid_banner_renders_only_when_active_image_exists(): void
    {
        // 无 home_mid 横幅：不渲染中部横幅
        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('class="midbanner"', $html);

        $this->makeBanner('home_mid', 'http://example.com/mid.jpg');
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('class="midbanner"', $html);
        $this->assertStringContainsString('mid.jpg', $html);
    }

    public function test_inactive_home_mid_banner_is_hidden(): void
    {
        $b = $this->makeBanner('home_mid');
        $b->is_active = false;
        $b->save();

        $this->assertStringNotContainsString('class="midbanner"', $this->get('/')->getContent());
    }
}
