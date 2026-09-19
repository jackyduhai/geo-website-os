<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 04：Configuration & Environment Contract。
 *
 * 配置优先级（冻结）：
 *   Environment(.env) → System Config(config) → Site Config(sites/settings)
 *   → Content/Entity → Runtime fallback
 *
 * 业务 config（facts / copy / pages / home_blocks）属于 Demo/业务数据层：
 * 卸载它们之后 Core Runtime 仍必须完整启动（优雅降级，不抛错、不 500）。
 */
class ConfigResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 模拟「业务 config 不存在」的开源部署环境
        config([
            'facts'       => null,
            'copy'        => null,
            'pages'       => null,
            'home_blocks' => null,
        ]);
    }

    public function test_core_boots_without_business_config(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $html);
    }

    public function test_geo_outputs_survive_without_business_config(): void
    {
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/llms.txt')->assertOk();
        $this->get('/geo.json')->assertOk();
        $this->get('/api/v1/health')->assertOk();
    }

    public function test_business_page_routes_degrade_gracefully_without_config(): void
    {
        // 业务路由在 config 缺席时不得 500（404/200 均为优雅降级）
        foreach (['/products/', '/solutions/', '/factory/', '/cooperation/', '/about/profile/', '/contact/'] as $path) {
            $res = $this->get($path);
            $this->assertContains($res->getStatusCode(), [200, 404], "{$path} 需优雅降级");
        }
    }
}
