<?php

namespace App\Services\Geo;

use App\Models\Category;
use App\Models\Content;
use App\Support\Facts;

/**
 * sitemap.xml 生成器（v0.7：按 Facts 驱动的新 IA 重建）
 *
 * 判据：只收录「真实可访问（返回 200）且有实质正文」的规范地址。
 *   - 目录型带尾斜杠、详情型不带，与 CanonicalizeSlash / canonical 完全一致；
 *   - 仅 6 款核心产品有独立详情页（ProductController@show 对非核心产品 404），
 *     其余产品只在体系页内以锚点呈现，不进 sitemap；
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

        // 产品中心：总览 + 达到独立成页门槛（≥4 款）的体系页 + 6 款核心产品详情
        $add(url('/products/'), 'weekly', '0.9');
        foreach (Facts::productLines() as $line) {
            $lineSlug = $line['slug'];
            if (count(Facts::productsByLine($lineSlug)) >= 4) {
                $add(url('/products/' . $lineSlug . '/'), 'weekly', '0.8');
            }
        }
        foreach (Facts::products() as $p) {
            if (Facts::isCoreProduct($p['slug'])) {
                $add(url('/products/' . $p['slug']), 'weekly', '0.7');
            }
        }

        // 应用场景：总览 + 六类
        $add(url('/solutions/'), 'monthly', '0.9');
        foreach (Facts::scenes() as $scene) {
            $add(url('/solutions/' . $scene['slug'] . '/'), 'monthly', '0.8');
        }

        // 工厂与合作
        $add(url('/factory/'), 'monthly', '0.7');
        $add(url('/cooperation/'), 'monthly', '0.7');

        // 知识中心：总览 + 各启用子栏目（groups 数据驱动）+ 已发布文章（扁平 URL）
        $add(url('/knowledge/'), 'weekly', '0.7');
        foreach (\App\Models\Group::knowledgeChannels() as $ch) {
            $add(url('/knowledge/' . $ch->slug . '/'), 'weekly', '0.6');
        }
        // 仅收录知识分类下的文章（扁平 /knowledge/{slug}）；旧产品综述/公司内容已被
        // config 页取代，不进 sitemap，避免跨分类重复地址。
        $knowledgeArticles = Content::published()
            ->where('noindex', false)
            ->whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->orderByDesc('published_at')->get();
        foreach ($knowledgeArticles as $article) {
            $add(
                url('/knowledge/' . $article->slug),
                'monthly',
                '0.6',
                ($article->updated_at ?? $article->published_at)?->toDateString()
            );
        }

        // 关于我们三子页 + 联系
        $add(url('/about/profile/'), 'yearly', '0.5');
        $add(url('/about/history/'), 'yearly', '0.5');
        $add(url('/about/culture/'), 'yearly', '0.5');
        $add(url('/contact/'), 'yearly', '0.6');

        // 后台可运营的自定义栏目（如新闻 /news/）：启用的列表/产品型栏目页收录；
        // 单页型（type=single）直接渲染其下文章，规范地址是文章 URL，故不重复收录栏目地址。
        foreach (Category::where('is_active', true)->where('type', '!=', 'single')->orderBy('sort')->get() as $category) {
            $add($category->url(), 'weekly', '0.5');
        }

        // 后台发布的全部文章（知识类已在上面以 0.6 收录，此处自动去重；新闻等其余栏目在此补齐）。
        // 仅收录归属启用栏目的文章，避免停用栏目下的内容泄漏进 sitemap。
        $extraArticles = Content::published()
            ->where('noindex', false)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with('category')
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
