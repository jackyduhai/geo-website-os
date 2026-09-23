<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18E — Frontend ↔ Backend Capability Closure（防回归）。
 *
 * 锁定本轮"能力对账"发现并最小修复的数据门控缺陷：
 *  1) 工厂页在只有部分生产事实（仅有车间、无厂区面积 / 年产能）时，H1 /
 *     数据条 / SEO 不得把缺失事实裸输出为 0；
 *  2) 底部统一 CTA 在未配置电话时不得渲染空的"或直接致电"行；
 *  3) 应用场景总览 H1 使用行业中性的"业务"，不使用零售 / 餐饮口径的"店"。
 */
class FrontendBackendClosure18ETest extends TestCase
{
    use RefreshDatabase;

    private Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
    }

    /** 模拟 geo:install 出厂：默认设置 + 清空垂直首页装修（不装 Demo）。 */
    private function seedBlank(): void
    {
        $this->seed(DefaultSettingSeeder::class);
        $this->seed(BlankHomepageSeeder::class);
        Catalog::flush();
        PageCache::flush();
    }

    /** 仅一个车间、无厂区面积 / 年产能的最小生产站点（hasProduction 仍为真）。 */
    private function makeWorkshopOnlyOrganization(): void
    {
        Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'partial-org',
            'name' => '示例机构',
            'summary' => '示例机构',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => [
                'company' => ['name' => '示例机构'],
                'production' => [
                    'workshops' => [
                        ['name' => '一号车间', 'desc' => '自有生产车间'],
                    ],
                ],
            ],
        ]);
        Catalog::flush();
        PageCache::flush();
    }

    public function test_factory_partial_production_does_not_render_zero_facts(): void
    {
        $this->seedBlank();
        $this->makeWorkshopOnlyOrganization();

        $page = $this->get('/factory/');
        $page->assertOk();
        $content = $page->getContent();

        // H1 只陈述真实存在的车间，不出现 0 ㎡ / 年产能约 0 吨
        $this->assertStringContainsString('1 个生产车间', $content);
        $this->assertStringNotContainsString('0 ㎡', $content);
        $this->assertStringNotContainsString('年产能约 0', $content);
        $this->assertStringNotContainsString('自有约 0', $content);

        // 数据条：车间一项保留；面积 / 产能等 0 值项被整体过滤
        $this->assertStringContainsString('自有生产车间', $content);
        $this->assertStringNotContainsString('年成品产能', $content);
        $this->assertStringNotContainsString('自有生产厂区', $content);
    }

    public function test_factory_seo_has_no_empty_or_zero_fragments(): void
    {
        $this->seedBlank();
        $this->makeWorkshopOnlyOrganization();

        $content = $this->get('/factory/')->getContent();

        // 标题不出现空面积拼出的"：厂区、"；描述不出现"年产能0 / 年产能，"
        $this->assertStringNotContainsString('厂区、', $content);
        $this->assertStringNotContainsString('年产能0', $content);
        $this->assertStringNotContainsString('年产能，', $content);
    }

    public function test_bottom_cta_hides_phone_row_when_no_phone(): void
    {
        $this->seedBlank();

        // 知识中心在空站仍可访问（非目录门控页），底部 CTA 不得出现空"致电"行
        $page = $this->get('/knowledge/');
        $page->assertOk();
        $page->assertDontSee('或直接致电');
    }

    public function test_solutions_index_uses_neutral_business_wording(): void
    {
        $this->seedBlank();
        $this->makeWorkshopOnlyOrganization();

        Entity::create([
            'site_id' => $this->default->id,
            'type' => Entity::TYPE_SERVICE,
            'slug' => 'scene-a',
            'name' => '场景 A',
            'summary' => '场景 A 描述',
            'status' => Entity::STATUS_PUBLISHED,
            'sort_order' => 0,
            'published_at' => now(),
            'metadata' => [],
        ]);
        Catalog::flush();
        PageCache::flush();

        $page = $this->get('/solutions/');
        $page->assertOk();
        $page->assertSee('你的业务属于哪一类？');
        $page->assertDontSee('你的店属于哪一类？');
    }
}
