<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 前台与 GEO 产出的基础冒烟测试（需要结构与设置种子）
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_home_and_feeds_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/llms.txt')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/feed.xml')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_structured_pages_render(): void
    {
        // v0.9.22：关于/产品为 facts 单一事实源驱动的结构化静态页（旧影子栏目已下线）
        $this->get('/about/profile')->assertOk();
        $this->get('/products')->assertOk();
        $this->get('/knowledge')->assertOk();
    }
}
