<?php

namespace App\Services\Geo;

use App\Models\Category;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicIndex;
use App\Support\PublicUrl;

/**
 * sitemap.xml 生成器（v0.7 IA；P-STEP 14 / D.2 起目录数据改由站点隔离 Catalog 驱动）
 *
 * 判据（Public Render Contract，P-STEP 17G 强化）：只收录「真实可访问（返回 200）、
 * 已发布、当前站点可见、未 noindex 且有实质正文」的规范地址。
 *   - 目录型带尾斜杠、详情型不带，与 CanonicalizeSlash / canonical 完全一致；
 *   - 仅标记为核心（core）且未 noindex 的产品有独立详情页（ProductController@show
 *     对非核心产品 404），其余产品只在产品系列页内以锚点呈现，不进 sitemap；
 *   - 场景详情同理（SolutionController@show）；
 *   - /factory/ 仅在站点有生产实质（车间 / 面积 / 产能）时收录，/cooperation/ 仅在
 *     有合作内容时收录——与对应控制器的 404 判定一致，不收录会 404 的地址；
 *   - 内容统一经 {@see PublicIndex::contentQuery()}：published + 启用栏目（或无栏目
 *     单页）+ 非 noindex + 当前站点，停用栏目 / noindex 文章不得泄漏；
 *   - 不建案例中心，故不输出 /cases/；
 *   - 知识文章 URL 扁平为 /knowledge/{slug}。
 * lastmod 由内容变更驱动，不写死。
 */
class SitemapBuilder
{
    public function build(): string
    {
        /**
         * lastmod 取业务真值（C-8 · Derived Output Integrity · 20G-4）。
         *
         * 绝不以「今天」兜底：固定 IA 页（首页 / 目录 / 关于）没有对应内容实体，
         * 其真实更新时间并不存在，填 today 等于向搜索引擎声明假事实——lastmod
         * 是 GEO 产品的核心信号（AI 侧据此判断内容新鲜度），24 个 URL 里 24 个
         * 编造日期会让整站 sitemap 的可信度归零。
         *
         * 规范允许 lastmod 缺失：无真实更新时间时**省略该字段**，不做任何假填充。
         *
         * @var array<int,array{loc:string,lastmod:?string,changefreq:string,priority:string}>
         */
        $urls  = [];

        // 同一规范地址只收录一次（固定 IA、自定义栏目、文章之间可能重合，如 /knowledge/）
        $seen = [];
        $add = function (string $loc, string $changefreq, string $priority, ?string $lastmod = null) use (&$urls, &$seen) {
            if (isset($seen[$loc])) {
                return;
            }
            $seen[$loc] = true;
            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $changefreq, 'priority' => $priority];
        };

        // 首页 loc 冻结为「无尾斜杠」形态：默认语言为根地址 PublicUrl::base()，
        // 非默认语言为 base()/{locale}（如 /en，与 /en 路由 200、/en/ 301 的契约一致）。
        // 声明性 feed 绝对地址统一经 PublicUrl 裁决规范 host（TD-09）。首页 canonical
        // 经 PublicUrl::home() 输出同一形态（默认根 /、前缀语言 /en 无尾斜杠；TD-105 后
        // canonical 与 sitemap loc 契约统一）。
        $homePrefix = LocaleRegistry::prefix(LocaleContext::current());
        $add(PublicUrl::base() . ($homePrefix !== '' ? '/' . $homePrefix : ''), 'daily', '1.0');

        // 业务目录（产品 / 场景 / 工厂 / 合作 / 关于 / 联系）只在当前站点确实存在
        // 目录数据（organization Entity）时收录。目录按站点隔离（Catalog），空站 /
        // 未播种站点这些页面返回 404 或空壳，不得把全局 / 他站目录 URL 写入本站 sitemap。
        $hasCatalog = ! empty(Catalog::company());

        // 可索引实体 slug 白名单（published + 非 noindex + 当前站点）：noindex 实体的
        // 详情页虽仍可直接访问（200），但不得进入 sitemap。
        $indexableEntitySlugs = PublicIndex::indexableEntitySlugs();

        /**
         * 实体内容型 URL 的真实 lastmod：updated_at 优先，回退 published_at，
         * 查不到（目录项无对应实体）时返回 null → 省略 lastmod，绝不填「今天」。
         *
         * @var \Closure(string):?string
         */
        $entityLastmod = function (string $slug): ?string {
            $entity = PublicIndex::entityQuery()
                ->forLocale(LocaleContext::current())
                ->where('slug', $slug)
                ->first();

            return ($entity?->updated_at ?? $entity?->published_at)?->toDateString();
        };

        // P-STEP 18R-2a：实体是否进 sitemap 由 EntityCapabilityRegistry::isSitemap() 声明。
        // 2a 仅 product/service 有真实路由且被下方 Catalog 循环收录；case_study 虽声明
        // sitemap=true，但 /cases 路由 2b 才建，故 2a 不新增收录段落（无 URL 可输出，
        // 输出会 404）；download_asset 声明 sitemap=false，永不进 sitemap。

        if ($hasCatalog) {
            // 产品中心：总览 + 含产品的系列独立页 + 核心产品详情
            $add(PublicUrl::url('products/'), 'weekly', '0.9');
            foreach (Catalog::productLines() as $line) {
                $lineSlug = $line['slug'];
                if (count(Catalog::productsByLine($lineSlug)) >= 1) {
                    $add(PublicUrl::url('products/' . $lineSlug . '/'), 'weekly', '0.8');
                }
            }
            foreach (Catalog::products() as $p) {
                if (Catalog::isCoreProduct($p['slug'])
                    && in_array($p['slug'], $indexableEntitySlugs, true)) {
                    $add(PublicUrl::product($p['slug']), 'weekly', '0.7', $entityLastmod($p['slug']));
                }
            }

            // 应用场景：总览 + 各可索引场景详情
            $add(PublicUrl::url('solutions/'), 'monthly', '0.9');
            foreach (Catalog::scenes() as $scene) {
                if (in_array($scene['slug'], $indexableEntitySlugs, true)) {
                    $add(PublicUrl::solution($scene['slug']), 'monthly', '0.8', $entityLastmod($scene['slug']));
                }
            }

            // 客户案例（18R-2b）：仅当存在已发布且可索引案例时才收录总览 + 详情，
            // 避免零案例时把会 404 的 /cases/ 写进 sitemap。
            $caseRows = PublicIndex::entityQuery()->forLocale(LocaleContext::current())
                ->ofType(\App\Models\Entity::TYPE_CASE_STUDY)->orderBy('sort_order')->get();
            $caseSlugs = [];
            foreach ($caseRows as $case) {
                if (in_array($case->slug, $indexableEntitySlugs, true)) {
                    $caseSlugs[] = $case->slug;
                }
            }
            if ($caseSlugs !== []) {
                $add(PublicUrl::url('cases/'), 'monthly', '0.8');
                foreach ($caseRows as $case) {
                    if (! in_array($case->slug, $caseSlugs, true)) {
                        continue;
                    }
                    $add(
                        PublicUrl::caseStudy($case->slug),
                        'monthly',
                        '0.7',
                        ($case->updated_at ?? $case->published_at)?->toDateString()
                    );
                }
            }

            // 工厂与合作：仅有实质内容时收录，避免收录会 404 的地址
            if (Catalog::hasProduction()) {
                $add(PublicUrl::url('factory/'), 'monthly', '0.7');
            }
            if (Catalog::hasCooperation()) {
                $add(PublicUrl::url('cooperation/'), 'monthly', '0.7');
            }
        }

        // 知识中心：总览 + 各启用子栏目（groups 数据驱动）+ 已发布文章（扁平 URL）
        $add(PublicUrl::url('knowledge/'), 'weekly', '0.7');
        foreach (\App\Models\Group::knowledgeChannels() as $ch) {
            $add(PublicUrl::url('knowledge/' . $ch->slug . '/'), 'weekly', '0.6', $ch->updated_at?->toDateString());
        }
        // 仅收录知识分类下、公开可索引（启用栏目 + 非 noindex）的文章（扁平 /knowledge/{slug}）。
        $knowledgeArticles = PublicIndex::contentQuery()
            ->forLocale(LocaleContext::current())
            ->whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->with('category.parent')
            ->orderByDesc('published_at')->get();
        foreach ($knowledgeArticles as $article) {
            $add(
                PublicUrl::content($article),
                'monthly',
                '0.6',
                ($article->updated_at ?? $article->published_at)?->toDateString()
            );
        }

        // 关于我们三子页（仅当本站存在目录 / 公司数据，否则这些页面 404）
        if ($hasCatalog) {
            $add(PublicUrl::url('about/profile/'), 'yearly', '0.5');
            $add(PublicUrl::url('about/history/'), 'yearly', '0.5');
            $add(PublicUrl::url('about/culture/'), 'yearly', '0.5');
        }

        // TD-100：联系页是官网基础页，无公司事实时 ContactController 也安全降级、
        // 始终返回 200，故 sitemap 无条件收录 /contact/（不随 $hasCatalog）。
        $add(PublicUrl::url('contact/'), 'yearly', '0.6');

        // 后台可运营的自定义栏目（如新闻 /news/）：仅启用的列表 / 产品列表型栏目页收录。
        // 单页型（type=page）直接渲染其下文章，规范地址是文章 URL；外链型（external）跳转
        // 站外，均不产生站内可索引栏目地址，故排除（TD-16，含历史 single 别名兼容）。
        foreach (Category::where('is_active', true)
            ->whereNotIn('type', Category::typesExcludedFromSitemap())
            ->with('parent')->orderBy('sort')->get() as $category) {
            $add($category->url(), 'weekly', '0.5');
        }

        // 后台发布的全部公开可索引文章（知识类已在上面以 0.6 收录，此处自动去重；
        // 新闻等其余栏目在此补齐）。PublicIndex 已排除停用栏目与 noindex 内容。
        $extraArticles = PublicIndex::contentQuery()
            ->forLocale(LocaleContext::current())
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with('category.parent')
            ->orderByDesc('published_at')
            ->get();
        foreach ($extraArticles as $article) {
            $add(
                $article->url(),
                'monthly',
                '0.5',
                ($article->updated_at ?? $article->published_at)?->toDateString()
            );
        }

        return $this->toXml($urls);
    }

    protected function toXml(array $urls): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $u) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->esc($u['loc']) . '</loc>';
            if (! empty($u['lastmod'])) {
                $lines[] = '    <lastmod>' . $u['lastmod'] . '</lastmod>';
            }
            if (! empty($u['changefreq'])) {
                $lines[] = '    <changefreq>' . $u['changefreq'] . '</changefreq>';
            }
            if (! empty($u['priority'])) {
                $lines[] = '    <priority>' . $u['priority'] . '</priority>';
            }
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    protected function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
