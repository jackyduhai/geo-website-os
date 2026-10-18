<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Site;
use App\Models\User;
use App\Support\Catalog;
use App\Support\PageCache;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-1 纵深防御：区块配置提取时 select 字段的服务端白名单归一化。
 *
 * 背景：PageController::extractConfig() 此前对 select 类型直接落 (string) 提交值，
 * 未按服务端字段 options 校验。虽然 setVariant 单独路由已用 CompositionPolicy
 * fail-closed，但伪造 POST 到 updateBlock 的 field[variant] 仍可绕过它把非法值
 * 写入持久化配置（前台虽安全回退，但数据已被污染）。
 *
 * 当前区块配置中唯一的 select 字段是 hero.variant（split/center）。本测试验证：
 *  - 合法英文值（split/center）原样保存、回显、前台渲染不变；
 *  - 非空非法值（伪造字符串 / 中文 label）归一到权威默认 split，不污染持久化；
 *  - 空值 / 缺失值归一到默认；
 *  - 归一化某字段时不影响同表单其他合法字段。
 */
class BlockSelectWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Site $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        Catalog::flush();
        PageCache::flush();
    }

    private function makePage(string $slug): Page
    {
        return Page::create([
            'site_id'  => $this->default->id,
            'template' => 'landing',
            'slug'     => $slug,
            'title'    => 'D-1 白名单页',
            'status'   => Page::STATUS_PUBLISHED,
            'locale'   => 'zh-CN',
        ]);
    }

    private function makeHeroBlock(Page $page): PageBlock
    {
        return PageBlock::create([
            'site_id'   => $page->site_id,
            'page'      => 'page',
            'page_id'   => $page->id,
            'slot'      => 'main',
            'type'      => 'hero',
            'sort'      => 0,
            'is_active' => true,
            'content'   => json_encode(['variant' => 'split', 'title' => '旧标题'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<int|string,mixed>  $variantField
     * @return array<string,mixed>
     */
    private function heroPayload($variant): array
    {
        return [
            'field' => [
                'variant'  => $variant,
                'eyebrow'  => '',
                'title'    => 'D-1 首屏标题',
                'subtitle' => 'D-1 副标题',
                'buttons'  => [],
                'image_id' => null,
            ],
        ];
    }

    public function test_legal_split_value_persists_unchanged(): void
    {
        $page = $this->makePage('d1-split');
        $hero = $this->makeHeroBlock($page);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $this->heroPayload('split'))
            ->assertRedirect();

        $this->assertSame('split', PageBlock::find($hero->id)->cfg()['variant']);
    }

    public function test_legal_center_value_persists_unchanged_and_renders_center(): void
    {
        $page = $this->makePage('d1-center');
        $hero = $this->makeHeroBlock($page);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $this->heroPayload('center'))
            ->assertRedirect();

        $this->assertSame('center', PageBlock::find($hero->id)->cfg()['variant']);

        $front = $this->get('/d1-center')->getContent();
        $this->assertStringContainsString('class="hero-center', $front);
        $this->assertStringNotContainsString('class="hero-split', $front);
    }

    public function test_forged_nonempty_variant_is_normalized_to_default_split(): void
    {
        $page = $this->makePage('d1-forged');
        $hero = $this->makeHeroBlock($page);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $this->heroPayload('evil'))
            ->assertRedirect();

        $saved = PageBlock::find($hero->id)->cfg();
        // 非法值绝不能落库，归一到权威默认 split
        $this->assertSame('split', $saved['variant']);
        $this->assertStringNotContainsString('evil', json_encode($saved, JSON_UNESCAPED_UNICODE));
    }

    public function test_forged_chinese_label_is_normalized_instead_of_persisted(): void
    {
        $page = $this->makePage('d1-zhlabel');
        $hero = $this->makeHeroBlock($page);

        // 伪造者直接把中文显示名当作提交值（B3-DISC-1 修复前的旧缺陷形态）
        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $this->heroPayload('居中文字'))
            ->assertRedirect();

        $variant = PageBlock::find($hero->id)->cfg()['variant'];
        $this->assertSame('split', $variant);
        $this->assertNotSame('居中文字', $variant);
    }

    public function test_empty_string_variant_is_normalized_to_default(): void
    {
        $page = $this->makePage('d1-empty');
        $hero = $this->makeHeroBlock($page);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $this->heroPayload(''))
            ->assertRedirect();

        $this->assertSame('split', PageBlock::find($hero->id)->cfg()['variant']);
    }

    public function test_missing_variant_key_is_normalized_to_default(): void
    {
        $page = $this->makePage('d1-missing');
        $hero = $this->makeHeroBlock($page);

        // 整个 field 中不提交 variant（缺失）
        $payload = $this->heroPayload('split');
        unset($payload['field']['variant']);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $payload)
            ->assertRedirect();

        $this->assertSame('split', PageBlock::find($hero->id)->cfg()['variant']);
    }

    public function test_normalizing_variant_does_not_affect_other_fields(): void
    {
        $page = $this->makePage('d1-others');
        $hero = $this->makeHeroBlock($page);

        $payload = $this->heroPayload('evil');
        $payload['field']['title'] = '需要保留的标题';
        $payload['field']['subtitle'] = '需要保留的副标题';

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $payload)
            ->assertRedirect();

        $cfg = PageBlock::find($hero->id)->cfg();
        $this->assertSame('split', $cfg['variant'], '非法 variant 归一为 split');
        $this->assertSame('需要保留的标题', $cfg['title'], '同表单标题不受影响');
        $this->assertSame('需要保留的副标题', $cfg['subtitle'], '同表单副标题不受影响');
    }

    public function test_forged_variant_normalizes_and_frontend_renders_safe_layout(): void
    {
        $page = $this->makePage('d1-front');
        $hero = $this->makeHeroBlock($page);

        $payload = $this->heroPayload('evil');
        $payload['field']['title'] = '前台安全首屏';

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $hero]), $payload)
            ->assertRedirect();

        // 归一为 split 后，前台按权威 split 布局渲染，标题正常，不出现坏布局
        $front = $this->get('/d1-front')->getContent();
        $this->assertStringContainsString('class="hero-split', $front);
        $this->assertStringContainsString('前台安全首屏', $front);
    }
}
