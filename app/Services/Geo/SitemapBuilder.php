<?php

namespace App\Services\Geo;

use App\Models\Category;
use App\Support\Catalog;
use App\Support\PublicIndex;

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
        $today = now()->toDateString();
        $urls  = [];

        // 同一规范地址只收录一次（固定 IA、自定义栏目、文章之间可能重合，如 /knowledge/）
        $seen = [];
        $add = function (string $loc, string $changefreq, string $priority, ?string $lastmod = null) use (&$urls, &$seen, $today) {
            if (isset($seen[$loc])) {
                return;
            }
            $seen[$loc] = true;
            $urls[] = ['loc' => $loc, 'lastmod' => $lastmod ?? $today, 'changefreq' => $changefreq, 'priority' => $priority];
        };

        // 首页
        $add(url('/'), 'daily', '1.0');

        // 业务目录（产品 / 场景 / 工厂 / 合作 / 关于 / 联系）只在当前站点确实存在
        // 目录数据（organization Entity）时收录。目录按站点隔离（Catalog），空站 /
        // 未播种站点这些页面返回 404 或空壳，不得把全局 / 他站目录 URL 写入本站 sitemap。
        $hasCatalog = ! empty(Catalog::company());

        // 可索引实体 slug 白名单（published + 非 noindex + 当前站点）：noindex 实体的
        // 详情页虽仍可直接访问（200），但不得进入 sitemap。
        $indexableEntitySlugs = PublicIndex::indexableEntitySlugs();

        if ($hasCatalog) {
            // 产品中心：总览 + 含产品的系列独立页 + 核心产品详情
            $add(url('/products/'), 'weekly', '0.9');
            foreach (Catalog::productLines() as $line) {
                $lineSlug = $line['slug'];
                if (count(Catalog::productsByLine($lineSlug)) >= 1) {
                    $add(url('/products/' . $lineSlug . '/'), 'weekly', '0.8');
                }
            }
            foreach (Catalog::products() as $p) {
                if (Catalog::isCoreProduct($p['slug'])
                    && in_array($p['slug'], $indexableEntitySlugs, true)) {
                    $add(url('/products/' . $p['slug']), 'weekly', '0.7');
                }
            }

            // 应用场景：总览 + 各可索引场景详情
            $add(url('/solutions/'), 'monthly', '0.9');
            foreach (Catalog::scenes() as $scene) {
                if (in_array($scene['slug'], $indexableEntitySlugs, true)) {
                    $add(url('/solutions/' . $scene['slug'] . '/'), 'monthly', '0.8');
                }
            }

            // 工厂与合作：仅有实质内容时收录，避免收录会 404 的地址
            if (Catalog::hasProduction()) {
                $add(url('/factory/'), 'monthly', '0.7');
            }
            if (Catalog::hasCooperation()) {
                $add(url('/cooperation/'), 'monthly', '0.7');
            }
        }

        // 知识中心：总览 + 各启用子栏目（groups 数据驱动）+ 已发布文章（扁平 URL）
        $add(url('/knowledge/'), 'weekly', '0.7');
        foreach (\App\Models\Group::knowledgeChannels() as $ch) {
            $add(url('/knowledge/' . $ch->slug . '/'), 'weekly', '0.6');
        }
        // 仅收录知识分类下、公开可索引（启用栏目 + 非 noindex）的文章（扁平 /knowledge/{slug}）。
        $knowledgeArticles = PublicIndex::contentQuery()
            ->whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->with('category.parent')
            ->orderByDesc('published_at')->get();
        foreach ($knowledgeArticles as $article) {
            $add(
                url('/knowledge/' . $article->slug),
                'monthly',
                '0.6',
                ($article->updated_at ?? $article->published_at)?->toDateString()
            );
        }

        // 关于我们三子页 + 联系（仅当本站存在目录 / 公司数据，否则这些页面 404）
        if ($hasCatalog) {
            $add(url('/about/profile/'), 'yearly', '0.5');
            $add(url('/about/history/'), 'yearly', '0.5');
            $add(url('/about/culture/'), 'yearly', '0.5');
            $add(url('/contact/'), 'yearly', '0.6');
        }

        // 后台可运营的自定义栏目（如新闻 /news/）：启用的列表/产品型栏目页收录；
        // 单页型（type=single）直接渲染其下文章，规范地址是文章 URL，故不重复收录栏目地址。
        foreach (Category::where('is_active', true)->where('type', '!=', 'single')->with('parent')->orderBy('sort')->get() as $category) {
            $add($category->url(), 'weekly', '0.5');
        }

        // 后台发布的全部公开可索引文章（知识类已在上面以 0.6 收录，此处自动去重；
        // 新闻等其余栏目在此补齐）。PublicIndex 已排除停用栏目与 noindex 内容。
        $extraArticles = PublicIndex::contentQuery()
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
