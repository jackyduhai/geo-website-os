<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Site;
use App\Support\Blocks\SectionSemantic;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18L-4b-1 — GEO Semantic Foundation（TD-132，吸收 TD-121）防回归。
 * ------------------------------------------------------------------
 * 验证每个 block 根容器输出受控 data-section / data-purpose / data-entity /
 * data-conversion：
 *   A Demo 首页（Composition）携带语义，全部值落在受控词表内；
 *   B Product 详情 system block data-entity=Product（Context 细化），不串 Service；
 *   C Service 详情 data-entity=Service，不串 Product；
 *   D 公开契约不回退：section 顺序 / H1 / JSON-LD / canonical / hreflang /
 *     sitemap / llms.txt 与加语义前一致（data-* 为纯增量属性）；
 *   E en 详情同样携带正确语义。
 */
class GeoSemantic18L4b1Test extends TestCase
{
    use RefreshDatabase;

    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    // ---------------------------------------------------------------
    // A Demo 首页语义
    // ---------------------------------------------------------------

    public function test_demo_home_hero_opens_with_semantic_attributes(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '#<section[^>]*data-section="hero"[^>]*data-purpose="brand"#u', $html);
    }

    public function test_demo_home_all_semantic_values_are_controlled(): void
    {
        $this->assertAllSemanticValid($this->get('/')->getContent());
    }

    // ---------------------------------------------------------------
    // B Product 详情
    // ---------------------------------------------------------------

    public function test_product_detail_hero_uses_product_entity(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertMatchesRegularExpression(
            '#<section[^>]*class="page-hero prod-hero"[^>]*data-entity="Product"#u', $html);
    }

    public function test_product_detail_entities_center_on_product(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->getContent();
        $entities = $this->dataValues($html, 'data-entity');

        $this->assertContains('Product', $entities);
        $this->assertAllSemanticValid($html);

        // 唯一跨实体引用：适用场景推荐（solution → Service），属 Product↔Service
        // 关系的正确表达，而非主体身份串用（hero 身份由上一测试锁定为 Product）。
        $this->assertMatchesRegularExpression(
            '#data-section="solution"[^>]*data-entity="Service"#u', $html);
    }

    // ---------------------------------------------------------------
    // C Service 详情
    // ---------------------------------------------------------------

    public function test_service_detail_hero_uses_service_entity(): void
    {
        $html = $this->get('/solutions/equipment-manufacturing/')->getContent();

        $this->assertMatchesRegularExpression(
            '#<section[^>]*class="page-hero"[^>]*data-entity="Service"#u', $html);
    }

    public function test_service_detail_entities_center_on_service(): void
    {
        $html = $this->get('/solutions/equipment-manufacturing/')->getContent();
        $entities = $this->dataValues($html, 'data-entity');

        $this->assertContains('Service', $entities);
        $this->assertAllSemanticValid($html);

        // 唯一跨实体引用：推荐组合（product → Product），属 Service→Product
        // 搭配关系的正确表达，而非主体身份串用（hero 身份由上一测试锁定为 Service）。
        $this->assertMatchesRegularExpression(
            '#data-section="product"[^>]*data-entity="Product"#u', $html);
    }

    // ---------------------------------------------------------------
    // D 公开契约不回退（迁移前后对拍）
    // ---------------------------------------------------------------

    public function test_product_public_contracts_unchanged_after_semantic_added(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->getContent();

        // section 顺序与 18G-2a 契约一致。
        $this->assertSame(
            ['page-hero prod-hero', 'sec sec-tint', 'sec', 'sec', 'sec sec-tint', 'bcta'],
            $this->sectionClasses($html)
        );

        // H1 不变。
        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>\s*环氧富锌底漆\s*ZP-100#u', $html);

        // JSON-LD 实体不变。
        $this->assertMatchesRegularExpression('/"@type"\s*:\s*"Organization"/', $html);
        $this->assertMatchesRegularExpression('/"@type"\s*:\s*"Product"/', $html);

        // canonical / hreflang 不变。
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/products/epoxy-primer-100">', $html);
        $this->assertStringContainsString('hreflang="zh-CN"', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);

        // sitemap 仍含该 URL；llms.txt 仍可访问。
        $this->assertStringContainsString(
            'http://localhost/products/epoxy-primer-100',
            $this->get('/sitemap.xml')->getContent());
        $this->get('/llms.txt')->assertOk();
    }

    public function test_service_public_contracts_unchanged_after_semantic_added(): void
    {
        $html = $this->get('/solutions/equipment-manufacturing/')->getContent();

        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>#u', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/solutions/equipment-manufacturing/"', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertAllSemanticValid($html);
    }

    // ---------------------------------------------------------------
    // E en 详情
    // ---------------------------------------------------------------

    public function test_en_product_detail_carries_product_semantic(): void
    {
        $html = $this->get('/en/products/epoxy-primer-100')->getContent();

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertMatchesRegularExpression('#data-entity="Product"#u', $html);
        $this->assertAllSemanticValid($html);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return array<int,string> */
    private function dataValues(string $html, string $attr): array
    {
        preg_match_all('/' . $attr . '="([^"]*)"/', $html, $m);

        return $m[1];
    }

    /** @return array<int,string> */
    private function sectionClasses(string $html): array
    {
        preg_match_all('/<section[^>]*class="([^"]*)"/', $html, $m);

        return $m[1];
    }

    private function assertAllSemanticValid(string $html): void
    {
        foreach ($this->dataValues($html, 'data-section') as $value) {
            $this->assertContains($value, SectionSemantic::SECTIONS);
        }
        foreach ($this->dataValues($html, 'data-purpose') as $value) {
            $this->assertContains($value, SectionSemantic::PURPOSES);
        }
        foreach ($this->dataValues($html, 'data-entity') as $value) {
            $this->assertContains($value, SectionSemantic::ENTITIES);
        }
        foreach ($this->dataValues($html, 'data-conversion') as $value) {
            $this->assertContains($value, SectionSemantic::CONVERSIONS);
        }
    }
}
