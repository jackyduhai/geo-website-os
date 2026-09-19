<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P-STEP 02：Demo / Example 隔离。
 *
 * Core Runtime ≠ Demo Content：
 *   - 全新库 + migrate（不装任何 Seeder）→ Core 完整可启动：
 *     首页 200 且 SEO head 正常、sitemap/robots/geo.json/health 全部可用；
 *   - 业务演示数据只在显式装载 DemoSeeder 后存在；
 *   - Core 层无业务专属标识（健康检查服务名等）。
 */
class FreshBootTest extends TestCase
{
    use RefreshDatabase;

    /** @test 当前测试类不装载任何 Seeder */
    public function test_core_boots_on_fresh_database_without_demo_data(): void
    {
        // 全新库：无演示内容、无演示设置
        $this->assertSame(0, DB::table('contents')->count());
        $this->assertSame(0, DB::table('facts')->count());
        // 迁移自带的 default site 存在（Core 结构，非演示数据）
        $this->assertSame(1, DB::table('sites')->count());

        // 迁移种子（结构缺省区块）不得携带业务文案（P-STEP 02 C6 修正守护）
        $blockText = json_encode(DB::table('page_blocks')->get(['title', 'subtitle', 'content'])->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['Demo Tenant A', 'Sample Snack', 'Sample Marinade', 'Sample City', 'demo-tenant-a'] as $kw) {
            $this->assertStringNotContainsString($kw, $blockText, "迁移种子含业务文案: {$kw}");
        }

        // 首页：无业务数据也能渲染，SEO head 完整（值来自站点级 Resolution）
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $html);

        // GEO 产出与运维端点在空数据下同样可用
        $this->get('/sitemap.xml')->assertOk();
        $this->get('/robots.txt')->assertOk();
        $this->get('/llms.txt')->assertOk();

        $graph = $this->get('/geo.json')->assertOk()->json();
        $this->assertSame([], $graph['entities']);
        $this->assertSame([], $graph['contents']);

        $health = $this->get('/api/v1/health')->assertOk()->json();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('geo-os', $health['service'], 'Core 运维端点不得携带业务服务名');
    }

    public function test_demo_content_exists_only_after_demo_seeder(): void
    {
        // 装载前：无任何业务内容
        $this->assertSame(0, DB::table('contents')->count());

        // 显式装载 Demo 数据后：业务演示内容出现
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->assertGreaterThan(0, DB::table('contents')->count());
        $this->assertGreaterThan(0, DB::table('facts')->count());

        // 演示站点此时可渲染业务首页
        $this->get('/')->assertOk();
    }

    public function test_database_seeder_composition_still_loads_demo(): void
    {
        // 兼容编排（DatabaseSeeder → DemoSeeder）不影响既有开发/测试工作流
        $this->seed();

        $this->assertGreaterThan(0, DB::table('contents')->count());
        $this->get('/')->assertOk();
    }
}
