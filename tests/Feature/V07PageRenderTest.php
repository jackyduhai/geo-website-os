<?php

namespace Tests\Feature;

use App\Support\Facts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.7 全站页面渲染与合规回归：
 *  - 所有规范路由可达、每个可索引页只有一个 H1；
 *  - 全站前台不得出现绝对化用语与竞品名（合规红线，防回潮）；
 *  - 不建案例中心：/cases 不存在、导航/页脚不出现案例中心入口。
 */
class V07PageRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public static function corePages(): array
    {
        return [
            'home'             => ['/'],
            'products-index'   => ['/products/'],
            'coatings-line'    => ['/products/coatings/'],
            'solutions-index'  => ['/solutions/'],
            'factory'          => ['/factory/'],
            'cooperation'      => ['/cooperation/'],
            'knowledge-index'  => ['/knowledge/'],
            'knowledge-sel'    => ['/knowledge/selection/'],
            'knowledge-proc'   => ['/knowledge/process/'],
            'knowledge-biz'    => ['/knowledge/business/'],
            'about-profile'    => ['/about/profile/'],
            'about-history'    => ['/about/history/'],
            'about-culture'    => ['/about/culture/'],
            'contact'          => ['/contact/'],
        ];
    }

    /**
     * @dataProvider corePages
     */
    public function test_core_page_renders_with_single_h1(string $path): void
    {
        $res = $this->get($path)->assertOk();
        $this->assertSame(1, substr_count($res->content(), '<h1'), $path . ' H1 数量异常');
    }

    public function test_core_products_render(): void
    {
        foreach (Facts::coreProductSlugs() as $slug) {
            $this->get('/products/' . $slug)->assertOk();
        }
    }

    public function test_scenes_render(): void
    {
        foreach (Facts::scenes() as $scene) {
            $this->get('/solutions/' . $scene['slug'] . '/')->assertOk();
        }
    }

    public function test_non_core_product_detail_is_404(): void
    {
        // 非核心产品不建独立详情页（只在体系页以锚点呈现）
        $all = Facts::products();
        $nonCore = collect($all)->first(fn ($p) => ! Facts::isCoreProduct($p['slug']));
        $this->assertNotNull($nonCore);
        $this->get('/products/' . $nonCore['slug'])->assertNotFound();
    }

    public function test_frontend_has_no_banned_or_competitor_terms(): void
    {
        $paths = ['/', '/products/', '/solutions/', '/factory/', '/cooperation/', '/about/profile/'];
        $banned = array_merge(Facts::bannedTerms(), Facts::bannedComparisons());
        // 裸「最」按最高级搭配识别，避免误伤「最终 / 最后 / 最多（CSS 注释）」等中性用法
        $superlative = '/最(大|好|强|优|佳|广|低|高|专业|先进|全|新|快|稳|安全|可靠|具|为|重要)/u';

        foreach ($paths as $path) {
            $html = $this->get($path)->content();
            // 只对可见文本做合规扫描：去掉内联 style / script
            $visible = preg_replace(['#<style.*?</style>#s', '#<script.*?</script>#s'], '', $html);

            foreach ($banned as $term) {
                if ($term === '最') {
                    $this->assertDoesNotMatchRegularExpression(
                        $superlative, $visible, "页面 {$path} 出现「最」字最高级表述"
                    );
                    continue;
                }
                $this->assertStringNotContainsString(
                    $term,
                    $visible,
                    "页面 {$path} 出现禁用表述「{$term}」"
                );
            }
        }
    }

    public function test_no_case_center(): void
    {
        $this->get('/cases/')->assertNotFound();
        $home = $this->get('/')->content();
        $this->assertStringNotContainsString('href="http://localhost/cases"', $home);
    }

    public function test_feeds_are_valid_and_use_new_ia(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->content();
        $this->assertStringContainsString('/solutions/', $sitemap);
        $this->assertStringNotContainsString('/scenarios', $sitemap);

        $robots = $this->get('/robots.txt')->assertOk()->content();
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('DeepSeekBot', $robots);

        $llms = $this->get('/llms.txt')->assertOk()->content();
        $this->assertStringNotContainsString('/cases/', $llms);
    }
}
