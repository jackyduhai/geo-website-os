<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Services\Geo\GeoGraphBuilder;
use App\Services\Geo\LlmsBuilder;
use App\Services\Geo\SitemapBuilder;

/**
 * GEO 产出：sitemap.xml / llms.txt / robots.txt / feed.xml / geo.json
 *
 * 全部动态生成，内容变更即生效，不需要手工维护文件。
 * robots.txt 里的爬虫清单在 config/geo.php 维护。
 */
class FeedController extends Controller
{
    public function sitemap(SitemapBuilder $builder)
    {
        return response($builder->build(), 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function llms(LlmsBuilder $builder)
    {
        return response($builder->build(), 200, [
            'Content-Type'  => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * 机器可读知识结构（STEP 06）：正式数据模型（主体/关系/内容/事实/SEO）的
     * 统一 GEO 输出，site-scoped，供 AI 检索与生成引擎直接消费。
     */
    public function graph(GeoGraphBuilder $builder)
    {
        return response()->json($builder->build(), 200, [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots()
    {
        $lines = [];
        $lines[] = '# robots.txt · ' . config('app.name') . ' · ' . url('/');
        $lines[] = '# 全站默认可抓取；仅阻断后台、接口、内部检索与临时资源。';
        $lines[] = '';
        $lines[] = '# ---------- 通用爬虫 ----------';
        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';
        foreach ((array) config('geo.robots_disallow', []) as $dis) {
            $lines[] = 'Disallow: ' . $dis;
        }
        $lines[] = '';
        $lines[] = '# ---------- 生成式引擎 / AI 检索爬虫：显式放行（GEO 前提） ----------';

        foreach ((array) config('geo.ai_crawlers', []) as $ua) {
            $lines[] = 'User-agent: ' . $ua;
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = '# ---------- 传统搜索引擎 ----------';
        foreach (['Googlebot', 'bingbot', 'Sogou web spider', '360Spider', 'Baiduspider'] as $ua) {
            $lines[] = 'User-agent: ' . $ua;
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = '# ---------- Sitemap ----------';
        $lines[] = 'Sitemap: ' . url('/sitemap.xml');
        $lines[] = '';

        $extra = (string) (config('geo.robots_extra') ?: '');
        if ($extra !== '') {
            $lines[] = '# ---------- 自定义追加 ----------';
            $lines[] = $extra;
        }

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type'  => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function rss()
    {
        // RSS 只收录知识 / 新闻类文章；被 config 页取代的旧公司内容、产品综述不进 feed，
        // 且文章 URL 与前台一致采用扁平 /knowledge/{slug}（知识类）。
        $items = \App\Models\Content::published()
            ->whereHas('category', fn ($q) => $q->whereIn('slug', ['knowledge', 'news']))
            ->with('category')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get();

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<rss version="2.0"><channel>';
        $xml[] = '<title>' . e(config('app.name')) . '</title>';
        $xml[] = '<link>' . url('/') . '</link>';
        $xml[] = '<description>' . e((string) config('app.name')) . '</description>';

        foreach ($items as $it) {
            $xml[] = '<item>';
            $xml[] = '<title>' . e($it->title) . '</title>';
            $xml[] = '<link>' . $it->url() . '</link>';
            $xml[] = '<guid>' . $it->url() . '</guid>';
            if ($it->published_at) {
                $xml[] = '<pubDate>' . $it->published_at->toRfc2822String() . '</pubDate>';
            }
            $xml[] = '<description>' . e((string) $it->summary) . '</description>';
            $xml[] = '</item>';
        }

        $xml[] = '</channel></rss>';

        return response(implode('', $xml), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }
}
