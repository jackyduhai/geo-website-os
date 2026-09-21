<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 08：剩余 Controller / 页面 SEO 迁移审计与验证。
 *
 * 资源类型结论（详见 docs/audit/remaining-controller-seo-audit.md）：
 *   About / Contact / Factory / Cooperation / Product / Solution / Search
 *   七个 Controller 均为「配置驱动固定 IA 页」（资源来自 config/copy.php、
 *   config/facts.php 与后台装修，无 DB 资源绑定）——不强行改造成
 *   Content / Entity（冻结契约只允许 Site / Content / Entity 三类 SeoMeta 绑定）。
 *
 * 迁移形态：页面级 SEO 数组（业务文案单一源）→ SeoHeadComposer 归一化
 *   （缺失键回退 SeoMetaResolver::resolveSite 站点级 Resolution）→ Blade 纯消费。
 *
 * 本测试从最终 HTML 验证每个页面七项 SEO 标签完整、canonical 绝对地址、
 * noindex 仅出现在 search。
 */
class RemainingControllerSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // P-STEP 14 / D.2：固定 IA 页（产品 / 场景 / 工厂 / 合作 / 关于 / 联系）的数据
        // 改由站点隔离的 Catalog（Entity 投影）提供，空库不再有全局 config facts 兜底。
        // 本测试验证「有目录的站点」SEO 头完整性，故先播种 Example 目录（DemoSeeder→CatalogSeeder）。
        $this->seed();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update([
            'name'        => 'Test Site',
            'domain'      => 'example.com',
            'description' => 'Site Description',
            'logo'        => '/site-logo.png',
        ]);
        SiteContext::setSite($site);
    }

    public static function fixedPages(): array
    {
        return [
            'about-profile'      => ['/about/profile/', false],
            'about-history'      => ['/about/history/', false],
            'about-culture'      => ['/about/culture/', false],
            'contact'            => ['/contact/', false],
            'factory'            => ['/factory/', false],
            'cooperation'        => ['/cooperation/', false],
            'products-index'     => ['/products/', false],
            'product-detail'     => ['/products/epoxy-primer-100', false],
            'solutions-index'    => ['/solutions/', false],
            'search'             => ['/search', true],
        ];
    }

    /**
     * @dataProvider fixedPages
     */
    public function test_fixed_page_outputs_complete_normalized_seo_head(string $path, bool $noindex): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>.+<\/title>/u', $html, "$path 必须有标题");
        $this->assertMatchesRegularExpression('/<meta name="description" content=".+">/u', $html, "$path 必须有描述");
        $this->assertMatchesRegularExpression(
            '#<link rel="canonical" href="https?://[^"]+">#',
            $html,
            "$path 必须有绝对地址 canonical"
        );
        $this->assertMatchesRegularExpression('/<meta property="og:title" content=".+">/u', $html);
        $this->assertMatchesRegularExpression('/<meta property="og:description" content=".+">/u', $html);
        $this->assertMatchesRegularExpression('/<meta property="og:image" content=".+">/u', $html);

        $expectedRobots = $noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1';
        $this->assertStringContainsString('<meta name="robots" content="' . $expectedRobots . '">', $html);
    }

    public function test_product_detail_canonical_matches_business_url(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->assertOk()->getContent();

        // 固定 IA 页 canonical = 其真实公开地址（业务 URL 生成器职责，5.12 收敛）
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
        $this->assertStringContainsString('/products/epoxy-primer-100">', $html);
    }

    public function test_search_page_is_noindex_and_robots_excluded_tags_consistent(): void
    {
        $html = $this->get('/search')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        // canonical 仍输出（供浏览器/引用一致性），但 robots 已禁止索引
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
    }
}
