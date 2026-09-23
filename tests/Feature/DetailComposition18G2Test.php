<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Page;
use App\Models\PageBlock;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18G-2a — Detail Composition Migration 防回归。
 *
 * 把 Product / Service 详情从「Controller → 固定 Blade」迁入 18G-1 建立的
 * Template + Page + Block 统一管线，验证八类契约：
 *   A Product 详情渲染 / section 顺序 / H1 / 非 core · 缺失 404；
 *   B Service 详情渲染 / section 顺序 / 尾斜杠契约 / 缺失 404；
 *   C TD-61（P0）管理员为 Entity 设置的 entity-level SeoMeta（title / desc /
 *     canonical / OG / noindex）前台真实消费（旧手工 SEO 双轨已拆除）；
 *   D 方案 ii：entity_id override Page 整槽替换可组合槽 related，固定槽仍由
 *     Entity 直驱，draft override 不生效，且 Page 不复制业务数据；
 *   E Draft Entity 不公开 / 跨站 Entity 404；
 *   F Entity 更新经模型钩子失效详情整页缓存；
 *   G Locale：en 详情走 /en 前缀、英文化、canonical / html lang 正确；
 *   H entity_id 绑定：删 Entity 级联删 override Page（block / seo），同 Entity
 *     不允许两个 override Page。
 */
class DetailComposition18G2Test extends TestCase
{
    use RefreshDatabase;

    protected User $super;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->super = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Setting::set('site_supported_locales', ['zh-CN', 'en']);
        Catalog::flush();
        PageCache::flush();
    }

    private function product(string $locale = 'zh-CN'): Entity
    {
        return Entity::where('type', Entity::TYPE_PRODUCT)
            ->where('slug', 'epoxy-primer-100')->where('locale', $locale)
            ->firstOrFail();
    }

    private function service(string $locale = 'zh-CN'): Entity
    {
        return Entity::where('type', Entity::TYPE_SERVICE)
            ->where('slug', 'equipment-manufacturing')->where('locale', $locale)
            ->firstOrFail();
    }

    /** @return array<int,string> 页面 <section> 的 class 顺序。 */
    private function sectionClasses(string $html): array
    {
        preg_match_all('/<section[^>]*class="([^"]*)"/', $html, $m);

        return $m[1];
    }

    /**
     * 建一个绑定 Entity 的详情载体 Page（方案 ii），可附带槽位 block。
     *
     * @param  array<int,array{0:string,1:string,2:array}>  $blockCfgs  [type, slot, cfg]
     */
    private function makeOverridePage(Entity $entity, string $locale, array $blockCfgs = []): Page
    {
        $page = Page::create([
            'site_id'   => $entity->site_id,
            'template'  => 'detail',
            'entity_id' => $entity->id,
            'slug'      => 'ov-' . uniqid(),
            'title'     => '详情覆盖',
            'status'    => Page::STATUS_PUBLISHED,
            'locale'    => $locale,
        ]);

        foreach ($blockCfgs as $i => [$type, $slot, $cfg]) {
            PageBlock::create([
                'site_id'   => $entity->site_id,
                'page'      => 'page',
                'page_id'   => $page->id,
                'slot'      => $slot,
                'type'      => $type,
                'sort'      => $i,
                'is_active' => true,
                'content'   => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $page;
    }

    // ----------------------------------------------------------------
    // A Product 详情
    // ----------------------------------------------------------------

    public function test_product_detail_renders_sections_in_order(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->getContent();

        // prod-hero → 使用步骤(tint) → 适用场景 → 相关产品 → FAQ(tint) → bcta
        // （规格 demo 无 net_weight 等数据，数据驱动不渲染，与原 blade 一致）。
        $this->assertSame(
            ['page-hero prod-hero', 'sec sec-tint', 'sec', 'sec', 'sec sec-tint', 'bcta'],
            $this->sectionClasses($html)
        );
    }

    public function test_product_detail_h1_is_entity_name(): void
    {
        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>\s*环氧富锌底漆\s*ZP-100#u', $html
        );
    }

    public function test_non_core_product_detail_is_404(): void
    {
        $this->get('/products/heat-resistant-coating-300')->assertNotFound();
    }

    public function test_missing_product_detail_is_404(): void
    {
        $this->get('/products/no-such-product')->assertNotFound();
    }

    // ----------------------------------------------------------------
    // B Service 详情
    // ----------------------------------------------------------------

    public function test_service_detail_renders_sections_in_order(): void
    {
        $html = $this->get('/solutions/equipment-manufacturing/')->getContent();

        // page-hero → 痛点 → 推荐组合(tint) → 关键参数(is-inverse) → 使用流程
        // → FAQ(tint) → 相邻场景 → bcta。
        $this->assertSame(
            ['page-hero', 'sec', 'sec sec-tint', 'sec is-inverse', 'sec', 'sec sec-tint', 'sec', 'bcta'],
            $this->sectionClasses($html)
        );
    }

    // 注：Service 无尾斜杠 → 带尾斜杠的 301 由 CanonicalizeSlash 中间件负责，
    // 该中间件在 runningUnitTests() 下有意跳过（测试客户端会剥尾斜杠致自跳），
    // 规则由独立的 CanonicalizeSlashTest 锁定，此处不重复。

    public function test_missing_service_detail_is_404(): void
    {
        $this->get('/solutions/no-such-scene/')->assertNotFound();
    }

    // ----------------------------------------------------------------
    // C TD-61：Entity SeoMeta 前台消费
    // ----------------------------------------------------------------

    public function test_entity_seo_meta_title_desc_canonical_consumed(): void
    {
        $entity = $this->product();

        SeoMeta::create([
            'site_id'     => $this->default->id,
            'entity_id'   => $entity->id,
            'title'       => '自定义产品标题XYZ',
            'description' => '自定义产品描述ABC',
            'canonical'   => 'http://localhost/custom-canonical',
        ]);

        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertStringContainsString('<title>自定义产品标题XYZ', $html);
        $this->assertStringContainsString(
            '<meta name="description" content="自定义产品描述ABC">', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/custom-canonical">', $html);
    }

    public function test_entity_seo_meta_noindex_consumed(): void
    {
        $entity = $this->product();

        SeoMeta::create([
            'site_id'   => $this->default->id,
            'entity_id' => $entity->id,
            'title'     => 'Noindex Product',
            'noindex'   => true,
        ]);

        $html = $this->get('/products/epoxy-primer-100')->getContent();
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, follow">', $html);

        // noindex Entity 不进入 sitemap。
        $sitemap = $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString(
            'http://localhost/products/epoxy-primer-100', $sitemap);
    }

    public function test_entity_seo_meta_og_consumed(): void
    {
        $entity = $this->product();

        SeoMeta::create([
            'site_id'        => $this->default->id,
            'entity_id'      => $entity->id,
            'title'          => '产品标题',
            'og_title'       => 'OG自定义标题',
            'og_description' => 'OG自定义描述',
        ]);

        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="OG自定义标题"', $html);
        $this->assertStringContainsString(
            '<meta property="og:description" content="OG自定义描述"', $html);
    }

    // ----------------------------------------------------------------
    // D 方案 ii：override Page
    // ----------------------------------------------------------------

    public function test_override_page_replaces_related_slot(): void
    {
        $this->makeOverridePage($this->product(), 'zh-CN', [
            ['cta', 'related', ['title' => '自定义相关区标题', 'tint' => false]],
        ]);

        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertStringContainsString('自定义相关区标题', $html);
        // 默认 related 槽（相关产品 → FAQ → bottom CTA）被整槽替换：bcta 不再渲染。
        $this->assertStringNotContainsString('class="bcta"', $html);
    }

    public function test_override_keeps_fixed_slots_driven_by_entity(): void
    {
        $this->makeOverridePage($this->product(), 'zh-CN', [
            ['cta', 'related', ['title' => '自定义相关区标题', 'tint' => false]],
        ]);

        $html = $this->get('/products/epoxy-primer-100')->getContent();

        // header（prod-hero）与 main（步骤 / 场景）固定槽仍由 Entity + Catalog 直驱。
        $classes = $this->sectionClasses($html);
        $this->assertSame('page-hero prod-hero', $classes[0]);
        $this->assertStringContainsString('环氧富锌底漆 ZP-100', $html);
        $this->assertStringContainsString('sec sec-tint', $html); // 使用步骤仍在
    }

    public function test_draft_override_page_does_not_apply(): void
    {
        $page = $this->makeOverridePage($this->product(), 'zh-CN', [
            ['cta', 'related', ['title' => '自定义相关区标题', 'tint' => false]],
        ]);
        $page->status = Page::STATUS_DRAFT;
        $page->save();

        $html = $this->get('/products/epoxy-primer-100')->getContent();

        $this->assertStringNotContainsString('自定义相关区标题', $html);
        $this->assertStringContainsString('class="bcta"', $html); // 默认 related 仍渲染
    }

    public function test_override_page_does_not_duplicate_entity_data(): void
    {
        $entity = $this->product();
        $this->makeOverridePage($entity, 'zh-CN');

        // Entity 业务事实仍唯一存在，Page 仅一份结构绑定（不复制产品数据）。
        $this->assertDatabaseHas('entities', [
            'id' => $entity->id, 'name' => $entity->name,
        ]);
        $this->assertSame(1, Page::where('entity_id', $entity->id)->count());
        // Page 行不含产品业务文案（只存结构 / 覆盖配置）。
        $page = Page::where('entity_id', $entity->id)->firstOrFail();
        $this->assertStringNotContainsString($entity->name, (string) $page->title);
    }

    // ----------------------------------------------------------------
    // E 状态边界
    // ----------------------------------------------------------------

    public function test_draft_entity_detail_is_404(): void
    {
        $entity = $this->product();
        Entity::where('translation_group', $entity->translation_group)
            ->update(['status' => Entity::STATUS_DRAFT]);
        Catalog::flush();

        $this->get('/products/epoxy-primer-100')->assertNotFound();
    }

    public function test_cross_site_entity_detail_is_404(): void
    {
        Site::create([
            'name' => 'Site B', 'slug' => 'site-b', 'domain' => 'b.test',
            'status' => Site::STATUS_ACTIVE, 'is_default' => false,
        ]);

        $this->get('http://b.test/products/epoxy-primer-100')->assertNotFound();
    }

    // ----------------------------------------------------------------
    // F 缓存失效
    // ----------------------------------------------------------------

    public function test_entity_update_invalidates_detail_cache(): void
    {
        // 首次访问进入整页缓存。
        $this->assertStringContainsString(
            '环氧富锌底漆 ZP-100',
            $this->get('/products/epoxy-primer-100')->getContent()
        );

        $entity = $this->product();
        $entity->name = '改名后的产品XYZ';
        $entity->slug = 'renamed-product-xyz';
        $entity->save(); // saved 钩子触发 PageCache::flush
        Catalog::flush();

        $html = $this->get('/products/renamed-product-xyz')->getContent();
        $this->assertStringContainsString('改名后的产品XYZ', $html);
    }

    // ----------------------------------------------------------------
    // G Locale
    // ----------------------------------------------------------------

    public function test_en_product_detail_renders_english(): void
    {
        $en = $this->product('en');

        $r = $this->get('/en/products/epoxy-primer-100');
        $r->assertOk();
        $html = $r->getContent();

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString($en->name, $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://localhost/en/products/epoxy-primer-100">',
            $html);
    }

    public function test_en_service_detail_renders_english(): void
    {
        $en = $this->service('en');

        $r = $this->get('/en/solutions/equipment-manufacturing/');
        $r->assertOk();
        $this->assertStringContainsString($en->name, $r->getContent());
        $this->assertStringContainsString('lang="en"', $r->getContent());
    }

    // ----------------------------------------------------------------
    // H entity_id 绑定 / 级联
    // ----------------------------------------------------------------

    public function test_deleting_entity_cascades_override_page(): void
    {
        $entity = $this->product();
        $page = $this->makeOverridePage($entity, 'zh-CN', [
            ['cta', 'related', ['title' => 'X']],
        ]);
        SeoMeta::create([
            'site_id' => $this->default->id, 'page_id' => $page->id, 'title' => 'Page SEO',
        ]);

        $entity->delete();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('page_blocks', ['page_id' => $page->id]);
        $this->assertDatabaseMissing('seo_metas', ['page_id' => $page->id]);
    }

    public function test_override_page_is_unique_per_entity(): void
    {
        $this->makeOverridePage($this->product(), 'zh-CN');

        $this->expectException(QueryException::class);
        $this->makeOverridePage($this->product(), 'zh-CN');
    }

    /**
     * TD-64：站点级与页面级 SeoMeta 必须可共存，且 resolveSite 不误取 page-level。
     * 修复前 sites_seo_meta_unique 谓词未排除 page_id，第二行触发 unique 冲突；
     * findSiteLevelSeo 未排除 page_id 时还会把 page-level 当 site-level。
     */
    public function test_site_and_page_level_seo_meta_coexist_and_resolve_separately(): void
    {
        SeoMeta::create([
            'site_id' => $this->default->id,
            'title' => 'Site Level Title',
            'description' => 'Site Level Desc',
        ]);

        $page = Page::create([
            'site_id' => $this->default->id, 'template' => 'landing',
            'slug' => 'lp-'.uniqid(), 'title' => 'Landing',
            'status' => Page::STATUS_PUBLISHED, 'locale' => 'zh-CN',
        ]);
        SeoMeta::create([
            'site_id' => $this->default->id, 'page_id' => $page->id,
            'title' => 'Page Level Title',
            'description' => 'Page Level Desc',
        ]);

        $resolver = app(SeoMetaResolver::class);

        $site = $resolver->resolveSite($this->default);
        $this->assertSame('Site Level Title', $site->title);
        $this->assertStringNotContainsString('Page Level Title', $site->title);

        $pageSeo = $resolver->resolvePage($page);
        $this->assertSame('Page Level Title', $pageSeo->title);
    }
}
