<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageBlock;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Templates\TemplatePreviewSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 模板 recipe **落地契约**（RC-11 G3）。
 *
 * ============================ 为什么存在 ============================
 * RC-11 G 的 8 个测试全绿，人工浏览器验收却抓到真缺陷：
 * `TemplatePreviewSite::ensure()` 漏调 `RecipeApplier::apply()`，
 * 导致 manufacturing-pro 与 commerce-pro 的预览站长得一模一样
 * （区块全是骨架 Seeder 的 rich_text / contact_info，HTML 只差 13 字节）。
 *
 * 根因是**测试只验证控制流安全，不验证结果语义**：
 *   G 验证了 隔离 / 幂等 / 回收 / 安全边界
 *   G 没有验证 Template A → A 的区块，A ≠ B
 *
 * 因此这里补的判据不是 `assertNotSame($htmlA, $htmlB)`（脆弱、字节级），
 * 而是**模板包声明的 recipe 与预览站实际落地区块之间的结构对应关系**：
 *
 *     recipes/*.json  →  RecipeApplier::apply()  →  page_blocks
 *
 * 这条契约一旦断裂（漏调apply、槽位过滤写错、标记丢失、顺序错乱），
 * 立即失败，且不依赖 HTML 字节数或缓存状态。
 * ====================================================================
 *
 * ------------------- 与既有测试的分工（避免误以为已全覆盖） -------------------
 * · TemplateLivePreviewTest：隔离 / 幂等 / 回收 / 安全边界（**控制流**）
 * · TemplateStructureTest：包**声明**的结构摘要两两不同（**配方层**）
 * · 本测试：声明 → **落库结果**的对应关系（**落地层**，补G2 暴露的缺口）
 *
 * 前两者都不检查 page_blocks，所以三者缺一不可。
 * ----------------------------------------------------------------------
 */
class TemplateRecipeLandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 取出某站点首页的 **recipe 管理区块**（按 _recipe_index 排序）。
     *
     * 判据用 `_recipe` 标记而非「main 槽全部区块」：
     *骨架 Seeder 也会往main 槽写区块（contact_info / form_reference 等），
     * 那是预览站**应有的底座**，不属于 recipe 契约范围。
     * 用标记过滤后，本测试只对「模板包声明了什么」负责。
     *
     * @return array<string, array<int, string>>  locale => [type, type, ...]
     */
    private function landedRecipeBlocks(int $siteId, string $pack): array
    {
        $homePages = Page::withoutGlobalScopes()
            ->where('site_id', $siteId)
            ->where('is_home', true)
            ->get();

        $out = [];
        foreach ($homePages as $page) {
            $types = PageBlock::withoutGlobalScopes()
                ->where('page_id', $page->id)
                ->where('slot', 'main')
                ->get()
                ->filter(static fn (PageBlock $b): bool =>
                    (($b->cfg()['_recipe'] ?? '') === $pack . '.homepage')
                )
                ->sortBy(static fn (PageBlock $b): int =>
                    (int) ($b->cfg()['_recipe_index'] ?? PHP_INT_MAX)
                )
                ->map(static fn (PageBlock $b): string => (string) $b->type)
                ->values()
                ->all();

            $out[(string) $page->locale] = $types;
        }

        return $out;
    }

    /**
     * 契约本体：预览站落地的区块序列 =模板包 recipe 声明的区块序列。
     *
     * 逐个语言行断言（RecipeApplier 对每个已启用语言各落一份），
     * 顺带保证「不同语言不串区块」。
     */
    public function test_landed_blocks_match_recipe_declaration(): void
    {
        $pack = 'commerce-pro';
        $preview = TemplatePreviewSite::ensure($pack);
        $this->assertNotNull($preview, '应创建出预览站');

        // 契约来源：模板包自己声明的 homepage recipe（过滤掉不写 main 槽的块）
        $recipe = collect(TemplatePackageManager::recipes($pack))
            ->firstWhere('key', 'homepage');

        $this->assertNotNull($recipe, "{$pack} 应有 homepage recipe");

        $declared = array_values(array_map(
            static fn (array $b): string => (string) $b['type'],
            array_filter(
                (array) ($recipe['blocks'] ?? []),
                static fn (array $b): bool => ($b['slot'] ?? '') === 'main'
            )
        ));
        $this->assertNotEmpty($declared, 'homepage recipe 应声明 main 槽区块');

        $landed = $this->landedRecipeBlocks($preview->id, $pack);

        $this->assertNotEmpty($landed, '预览站应有首页语言行');

        foreach ($landed as $locale => $types) {
            $this->assertSame(
                $declared,
                $types,
                "预览站 {$locale} 首页落地的 recipe 区块序列"
                . '与模板包声明不一致 —— RecipeApplier 漏调或落地错乱'
            );
        }
    }

    /**
     * 护栏①：**两个不同模板的落地结果必须不同**。
     *
     * 这是 G2 缺陷最直接的语义判据。它不比较字节数（缓存 / 渲染差异会让
     * 字节判据不稳定），只比较**落库的区块语义** —— 模板 A 若真的落了 A 的
     * 区块、B 落了 B 的区块，语义必然不同。
     *
     * 变异验证：把 TemplatePreviewSite 里的 recipe 循环删掉后，
     * 两站都会只剩骨架区块，本断言立即失败（landing 断言同样失败）。
     */
    public function test_two_packs_land_different_structures(): void
    {
        $a = TemplatePreviewSite::ensure('manufacturing-pro');
        $b = TemplatePreviewSite::ensure('commerce-pro');
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $seqA = $this->landedRecipeBlocks($a->id, 'manufacturing-pro');
        $seqB = $this->landedRecipeBlocks($b->id, 'commerce-pro');

        $flatA = array_values(array_map(
            static fn (array $t): string => implode('>', $t),
            $seqA
        ));
        $flatB = array_values(array_map(
            static fn (array $t): string => implode('>', $t),
            $seqB
        ));

        $this->assertNotEmpty($flatA, 'manufacturing-pro 应有落地的 recipe 区块');
        $this->assertNotEmpty($flatB, 'commerce-pro 应有落地的 recipe 区块');
        $this->assertNotSame(
            $flatA,
            $flatB,
            '两个模板的落地结构必须不同 —— 若相同，说明 recipe 未真正落地（G2 缺陷复发）'
        );
    }

    /**
     * 护栏②：落地内容必须**忠实反映模板包**，不得凭空多出区块。
     *
     * 这是 Template Preview Fidelity 的方向性约束：
     *   允许：模板包声明什么，就渲染什么
     *   禁止：为了「预览更丰满」自行补模板未声明的区块
     *
     * 断言用**逐项相等**（已在测试①覆盖），此处单独固化意图：
     * 若将来有人为了视觉丰满度在预览站硬塞区块，本测试与①同时失败，
     * 且失败信息会直接指向「落地数多于声明数」。
     */
    public function test_no_blocks_beyond_recipe_declaration(): void
    {
        $pack = 'commerce-pro';
        $preview = TemplatePreviewSite::ensure($pack);
        $this->assertNotNull($preview);

        $recipe = collect(TemplatePackageManager::recipes($pack))->firstWhere('key', 'homepage');
        $declaredCount = count(array_filter(
            (array) ($recipe['blocks'] ?? []),
            static fn (array $b): bool => ($b['slot'] ?? '') === 'main'
        ));

        foreach ($this->landedRecipeBlocks($preview->id, $pack) as $locale => $types) {
            $this->assertLessThanOrEqual(
                $declaredCount,
                count($types),
                "预览站 {$locale} 落地的 recipe 区块多于模板包声明数"
                . ' —— 预览不得自行增加模板未声明的区块'
            );
        }
    }

    /**
     * 护栏③：预览不得污染真实站点。
     *
     * copyDemoContent() 从默认站**复制**内容，必须是只读复制。
     * 这条与 TemplateLivePreviewTest 的隔离测试互补：那边验站点行，
     * 这边验被复制的源数据行数不变。
     */
    public function test_content_copy_does_not_mutate_source_site(): void
    {
        $sourceId = \App\Models\Site::withoutGlobalScopes()
            ->where('slug', \App\Models\Site::DEFAULT_SLUG)
            ->value('id');

        $before = [
            'contents' => \Illuminate\Support\Facades\DB::table('contents')->where('site_id', $sourceId)->count(),
            'entities' => \Illuminate\Support\Facades\DB::table('entities')->where('site_id', $sourceId)->count(),
        ];

        $preview = TemplatePreviewSite::ensure('commerce-pro');
        $this->assertNotNull($preview);

        $after = [
            'contents' => \Illuminate\Support\Facades\DB::table('contents')->where('site_id', $sourceId)->count(),
            'entities' => \Illuminate\Support\Facades\DB::table('entities')->where('site_id', $sourceId)->count(),
        ];

        $this->assertSame(
            $before,
            $after,
            '创建预览站不得改变真实站的内容 / 实体行数'
        );
        $this->assertNotSame(
            $sourceId,
            $preview->id,
            '预览站必须是独立 site_id'
        );
    }

    /**
     * 护栏④：预览站必须 **noindex**。
     *
     * 预览站经`?site_slug=` 暴露在**当前域名**下，且复制了真实站的全部内容。
     * 一旦被收录，搜索引擎会得到「同内容、不同 URL」的重复页，
     * 稀释真实站的抓取权重与 canonical 信号 —— 对 GEO 产品是实打实的损害。
     *
     * 这条与 ThemeController::preview() 的 `X-Robots-Tag: noindex` 同等对待。
     *
     * 注意判据用「前台响应头 / meta」而不是内部方法：noindex 最终要落到
     * HTTP 响应上，中间任何一环断掉都算失败。
     */
    public function test_preview_site_is_noindex(): void
    {
        $preview = TemplatePreviewSite::ensure('commerce-pro');
        $this->assertNotNull($preview);

        $response = $this->get(
            '/?site_slug=' . TemplatePreviewSite::SLUG_PREFIX . 'commerce-pro'
        );
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString(
            'noindex',
            (string) $html,
            '预览站页面必须输出 noindex，否则会被搜索引擎收录为重复内容'
        );
    }
}
