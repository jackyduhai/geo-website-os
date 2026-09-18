<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use App\Support\Narrative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.9.22 叙事插槽（narrative slot）
 * ------------------------------------------------------------------
 * 结构化页面的可运营叙事（hero 导语 / 企业简介正文）以 contents.slot 片段
 * 存储并接线前台；硬数据仍读事实库。覆盖：默认回退、自定义生效、片段无独立
 * URL、不泄漏到搜索/sitemap/feed、后台保存与恢复、H1 降级、产品/场景覆盖
 * 在 hero 与 SEO 中保持一致。
 */
class NarrativeSlotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Narrative::flush();
        $this->seed();
    }

    /** 直接写入一条 slot 片段 */
    protected function makeSlot(string $key, string $summary, string $body = ''): Content
    {
        $content = Content::withoutGlobalScope('not_slot')->create([
            'type'         => 'page',
            'slug'         => Narrative::reservedSlug($key),
            'slot'         => $key,
            'category_id'  => null,
            'group_id'     => null,
            'cover_id'     => null,
            'title'        => '叙事片段·' . $key,
            'summary'      => $summary,
            'body'         => $body,
            'status'       => 'published',
            'published_at' => now(),
            'owner'        => 'narrative-cms',
        ]);
        Narrative::flush();
        return $content;
    }

    public function test_structured_pages_render_default_narrative(): void
    {
        $lead = (string) config('pages.about.profile.lead');
        $this->assertNotSame('', $lead);

        $this->get('/about/profile')->assertOk()->assertSee($lead);
        $this->get('/factory/')->assertOk();
        $this->get('/cooperation/')->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/products')->assertOk();
        $this->get('/solutions')->assertOk();
    }

    public function test_override_lead_renders_on_about_page(): void
    {
        $default = (string) config('pages.about.profile.lead');
        $custom = '【测试自定义导语】Demo Tenant A专注中式Sample Snack风味的一句话，仅用于本断言。';

        $this->get('/about/profile')->assertSee($default)->assertDontSee($custom);

        $this->makeSlot('about.profile', $custom, "## 自定义正文段落\n\n这是一段由后台页面文案维护的企业简介正文。");

        $response = $this->get('/about/profile');
        $response->assertOk()->assertSee($custom)->assertDontSee($default);
        $response->assertSee('自定义正文段落');
    }

    public function test_markdown_h1_is_downgraded_to_h2(): void
    {
        $this->makeSlot('about.profile', '导语保持非空', "# 自定义一级标题\n\n正文内容。");

        $html = Narrative::html('about.profile', '<p>默认正文</p>');

        $this->assertStringContainsString('<h2>自定义一级标题</h2>', $html);
        $this->assertStringNotContainsString('<h1', $html);
    }

    public function test_slot_has_no_independent_url(): void
    {
        $this->makeSlot('about.profile', '某导语');

        // 保留 slug 不匹配任何前台路由
        $this->get('/' . Narrative::reservedSlug('about.profile'))->assertNotFound();
    }

    public function test_slot_is_excluded_from_search_sitemap_and_feed(): void
    {
        $marker = 'SLOT_MARKER_不会出现在任何公开列表';
        $this->makeSlot('factory.lead', $marker);

        // 常规 Content 查询（含后台列表/计数所用默认作用域）不含 slot
        $this->assertSame(0, Content::where('summary', $marker)->count());
        $this->assertSame(1, Content::withoutGlobalScope('not_slot')->where('slot', 'factory.lead')->count());

        // 搜索结果、sitemap、feed 均不得出现 slot 片段（搜索框会回显 q，故只断言保留 slug 不泄漏）
        $this->get('/search?q=' . urlencode($marker))->assertOk()->assertDontSee('_slot_factory-lead');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('_slot_factory-lead');
        $this->get('/feed.xml')->assertOk()->assertDontSee('_slot_factory-lead');
    }

    public function test_admin_can_save_and_reset_narrative(): void
    {
        $admin = User::firstOrFail();
        $custom = '【后台保存测试】这是通过页面文案表单保存的合作页导语。';

        $this->actingAs($admin)
            ->put('/admin/narrative/cooperation.lead', ['summary' => $custom])
            ->assertRedirect();

        $this->assertSame(1, Content::withoutGlobalScope('not_slot')->where('slot', 'cooperation.lead')->count());
        $this->get('/cooperation/')->assertOk()->assertSee($custom);

        // 恢复默认
        $this->actingAs($admin)
            ->delete('/admin/narrative/cooperation.lead')
            ->assertRedirect();

        $this->assertSame(0, Content::withoutGlobalScope('not_slot')->where('slot', 'cooperation.lead')->count());
        $this->get('/cooperation/')->assertOk()->assertDontSee($custom);
    }

    public function test_admin_empty_summary_is_rejected_but_both_empty_resets(): void
    {
        $admin = User::firstOrFail();

        // 有正文但导语为空：导语是必填，回退报错
        $this->actingAs($admin)
            ->put('/admin/narrative/about.profile', ['summary' => '', 'body' => '正文'])
            ->assertSessionHasErrors('summary');

        // 导语与正文都为空：视为恢复默认（删除覆盖）
        $this->makeSlot('about.profile', '临时导语', '临时正文');
        $this->actingAs($admin)
            ->put('/admin/narrative/about.profile', ['summary' => '', 'body' => ''])
            ->assertRedirect();
        $this->assertSame(0, Content::withoutGlobalScope('not_slot')->where('slot', 'about.profile')->count());
    }

    public function test_product_tagline_override_is_consistent_in_hero_and_seo(): void
    {
        $slug = 'orleans-801';
        $custom = '【产品覆盖测试】Sample FlavorSample Marinade 801 的自定义一句话定位。';

        $this->makeSlot('products.detail.' . $slug, $custom);

        $response = $this->get('/products/' . $slug);
        $response->assertOk();
        // hero、SEO description、Product Schema 三处都应使用覆盖后的 tagline
        $this->assertGreaterThanOrEqual(2, substr_count($response->content(), $custom));
    }

    public function test_scene_desc_override_renders_on_scene_page(): void
    {
        $scene = 'fried-chicken-shop';
        $custom = '【场景覆盖测试】Sample Snack店风味标准化方案的自定义导语。';

        $this->makeSlot('solutions.scene.' . $scene, $custom);

        $this->get('/solutions/' . $scene . '/')->assertOk()->assertSee($custom);
    }

    public function test_narrative_registry_lists_editable_slots(): void
    {
        $registry = Narrative::registry();
        $flat = collect($registry)->flatMap(fn ($g) => $g['items'])->pluck('key')->all();

        $this->assertContains('about.profile', $flat);
        $this->assertContains('factory.lead', $flat);
        $this->assertContains('contact.lead', $flat);
        $this->assertContains('products.detail.orleans-801', $flat);
        $this->assertContains('solutions.scene.fried-chicken-shop', $flat);
    }
}
