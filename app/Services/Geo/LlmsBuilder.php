<?php

namespace App\Services\Geo;

use App\Support\Catalog;
use App\Support\PublicIndex;
use App\Support\PublicUrl;

/**
 * llms.txt 生成器（v0.7 IA；P-STEP 14 / D.2 起结构化事实改由站点隔离 Catalog 驱动，遵循 llmstxt.org）
 *
 * 定位：给 AI 检索 / 生成引擎的权威口径入口。
 *   - 结构化事实全部来自当前站点的 Catalog（Entity 投影），与页面正文同源，不手工维护；
 *   - 链接使用 v0.7 规范地址（目录带斜杠、详情不带），不输出已取消的 /cases/；
 *   - 未核定数据（资质编号、起订量、交付周期、门店数、经纬度）一律不写；
 *   - P-STEP 17G / Public Render Contract：只链接真实可访问（200）的页面——/factory/
 *     仅有生产实质、/cooperation/ 仅有合作内容时才输出；核心产品 / 场景、知识文章
 *     统一经 PublicIndex 过滤（published + 启用栏目 + 非 noindex）；
 *   - 最小站点（只有公司名）时摘要与各段按数据有无条件降级，不拼「自有 0 厂区 /
 *     0 车间 / 年产能」这类空壳句，也不输出会 404 / 500 的链接。
 */
class LlmsBuilder
{
    public function build(): string
    {
        if (\App\Support\Localization\LocaleContext::current() !== \App\Support\Localization\LocaleRegistry::default()) {
            return $this->buildEnglish();
        }

        $company = Catalog::company();

        // 配置契约降级（P-STEP 04）：业务事实缺席（如开源裸部署）时
        // 输出通用骨架——不抛错、不编造事实。
        if (empty($company) || empty($company['name'])) {
            return $this->buildGeneric();
        }

        $brand       = Catalog::brandLanguage();
        $brandName   = ! empty($company['brand']) ? $company['brand'] : $company['name'];
        $industry    = $company['industry'] ?? '';
        $workshops   = Catalog::workshops();
        $workshopCnt = count($workshops);
        $regions     = Catalog::salesRegions();
        $regionCnt   = count($regions);
        $lines       = Catalog::productLines();
        $lineCnt     = count($lines);
        $scenes      = Catalog::scenes();
        $sceneCnt    = count($scenes);
        $coop        = Catalog::cooperation();
        $coopTypes   = $coop['types'] ?? [];
        $coopSteps   = $coop['process'] ?? [];
        $indexableEntitySlugs = PublicIndex::indexableEntitySlugs();
        $L           = [];

        // ---------- 标题与摘要（按数据有无条件拼接，不制造全 0 空壳） ----------
        $L[] = '# ' . $company['name'];
        $L[] = '';
        $summary = '> ' . $company['name'];
        if (! empty($company['founded_display'])) {
            $summary .= '，' . $company['founded_display'] . '成立';
        }
        if ($industry !== '') {
            $summary .= '，专注' . $industry . '的研发、生产与销售';
        }
        $capacity = [];
        if (! empty($company['area_display'])) {
            $capacity[] = '自有' . $company['area_display'] . '厂区';
        }
        if ($workshopCnt > 0) {
            $capacity[] = $workshopCnt . '个生产车间';
        }
        if (! empty($company['annual_capacity_display'])) {
            $capacity[] = '年产能' . $company['annual_capacity_display'];
        }
        if ($capacity !== []) {
            $summary .= '。' . implode('，', $capacity);
        }
        $summary .= '。';
        if (! empty($company['target_customers'])) {
            $summary .= '为' . implode('、', $company['target_customers'])
                . '提供' . implode('、', $company['business_model'] ?? []) . '。';
        }
        if ($regionCnt > 0) {
            $summary .= '覆盖全国' . $regionCnt . '大销售区域。';
        }
        $L[] = $summary;
        $L[] = '';
        $L[] = '本文件供 AI 系统快速获取本站核心事实与内容结构，所有内容与官网正文一致。';
        $L[] = '';

        // ---------- 核心事实 ----------
        $L[] = '## 核心事实';
        $L[] = '';
        $L[] = '- 公司全称：' . $company['name'];
        $L[] = '- 品牌名：' . $brandName;
        if (! empty($company['founded_display'])) {
            $L[] = '- 成立时间：' . $company['founded_display'];
        }
        if (! empty($company['established_production_display'])) {
            $L[] = '- 正式投产：' . $company['established_production_display'] . '，' . $workshopCnt . '大车间全面投产';
        }
        if (! empty($company['address']['full'])) {
            $L[] = '- 注册与生产地址：' . $company['address']['full'];
        }
        if (! empty($company['area_display'])) {
            $L[] = '- 厂区面积：' . $company['area_display'];
        }
        if (! empty($company['annual_capacity_display'])) {
            $L[] = '- 年产能：' . $company['annual_capacity_display'];
        }
        if (! empty($company['total_investment_wan'])) {
            $L[] = '- 总投资：约 ' . number_format($company['total_investment_wan']) . ' 万元';
        }
        if ($workshopCnt > 0) {
            $L[] = '- 生产车间：' . implode('、', array_map(fn ($w) => $w['name'], $workshops));
        }
        if (! empty($company['tech_experience_years'])) {
            $L[] = '- 技术积累：创始团队深耕' . $industry . '领域' . $company['tech_experience_years'] . '年';
        }
        if ($regionCnt > 0) {
            $L[] = '- 销售区域：' . implode('、', $regions) . $regionCnt . '大区域';
        }
        if (! empty($company['phone'])) {
            $L[] = '- 官方电话：' . $company['phone'];
        }
        $L[] = '- 官网：' . PublicUrl::home();
        $L[] = '';
        if (! empty($company['tech_experience_display'])) {
            $L[] = '**时间表述口径**：谈经验用「' . $company['tech_experience_display'] . '」，'
                 . '谈公司用「' . ($company['founded_display'] ?? '') . '成立」，'
                 . '谈产能用「' . ($company['established_production_display'] ?? '') . '投产」，三者各有所指。';
            $L[] = '';
        }

        // ---------- 产品体系 ----------
        $L[] = '## 产品体系';
        $L[] = '';
        $L[] = '- [产品中心](' . PublicUrl::url('products/') . ')：产品体系总览';
        if ($lineCnt > 0) {
            $L[] = '';
            $L[] = $lineCnt . '大产品体系：' . implode('、', array_map(fn ($l) => $l['name'], $lines)) . '。';
            foreach ($lines as $line) {
                $slug = $line['slug'];
                if (count(Catalog::productsByLine($slug)) >= 1) {
                    $L[] = '- [' . $line['name'] . '](' . PublicUrl::productLine($slug) . ')：' . ($line['desc'] ?? $line['name']);
                }
            }
        }
        // 核心产品（有独立详情页、未 noindex），带关键参数
        foreach (Catalog::products() as $p) {
            if (! Catalog::isCoreProduct($p['slug'])
                || ! in_array($p['slug'], $indexableEntitySlugs, true)) {
                continue;
            }
            $kp = array_map(fn ($k) => $k['label'] . ' ' . ($k['value'] ?? ''), $p['key_params'] ?? []);
            $suffix = $kp ? '：' . implode('，', array_slice($kp, 0, 3)) : '';
            $L[] = '- [' . $p['name'] . '](' . PublicUrl::product($p['slug']) . ')' . $suffix;
        }
        $L[] = '';

        // ---------- 应用场景 ----------
        $L[] = '## 应用场景';
        $L[] = '';
        $L[] = '- [应用场景总览](' . PublicUrl::url('solutions/') . ')：' . ($sceneCnt > 0 ? $sceneCnt . '类常见应用场景' : '应用场景总览');
        foreach ($scenes as $scene) {
            if (! in_array($scene['slug'], $indexableEntitySlugs, true)) {
                continue;
            }
            $combo = Catalog::sceneCombo($scene);
            $names = array_map(fn ($p) => $p['short_name'] ?? $p['name'], $combo);
            $L[] = '- [' . $scene['name'] . '](' . PublicUrl::solution($scene['slug']) . ')'
                 . ($names ? '：推荐组合为 ' . implode('、', $names) : '');
        }
        $L[] = '';

        // ---------- 合作与信任（仅链接真实可访问的页面） ----------
        $L[] = '## 合作与信任';
        $L[] = '';
        if (! empty($coopTypes)) {
            $typeNames = implode('、', array_map(fn ($t) => $t['name'], $coopTypes));
            $stepNames = implode('、', array_map(fn ($s) => $s['name'], $coopSteps));
            $L[] = '- [合作方式](' . PublicUrl::url('cooperation/') . ')：' . $typeNames . count($coopTypes) . '种方式。合作流程为' . $stepNames . count($coopSteps) . '步';
        }
        if (Catalog::hasProduction()) {
            $L[] = '- [工厂与资质](' . PublicUrl::url('factory/') . ')：厂区规模、' . $workshopCnt . '大车间、生产流程与' . $regionCnt . '大销售区域';
        }
        $L[] = '- [企业简介](' . PublicUrl::url('about/profile/') . ')：公司概况与核心事实';
        if (! empty($company['founded_display'])) {
            $L[] = '- [发展历程](' . PublicUrl::url('about/history/') . ')：' . $company['founded_display'] . '成立至今的可核实节点';
        }
        $L[] = '- [企业文化](' . PublicUrl::url('about/culture/') . ')：使命、愿景、价值观与品牌口号';
        $contactLine = '- [联系我们](' . PublicUrl::url('contact/') . ')：';
        if (! empty($company['phone'])) {
            $contactLine .= '合作热线 ' . $company['phone'] . '，';
        }
        if (! empty($company['address']['full'])) {
            $contactLine .= '厂区地址 ' . $company['address']['full'];
        }
        $L[] = $contactLine;
        $L[] = '';

        // ---------- 知识中心（仅公开可索引文章） ----------
        $L[] = '## 知识中心';
        $L[] = '';
        $knowledgeGroups = \App\Models\Group::knowledgeChannels();
        $channelNames = $knowledgeGroups->pluck('name')->implode('、');
        $L[] = '- [知识中心](' . PublicUrl::url('knowledge/') . ')：' . ($channelNames !== '' ? $channelNames . '等栏目' : '产品选型、工艺施工与采购合作内容');
        foreach ($knowledgeGroups as $kg) {
            $desc = trim((string) ($kg->description ?? ''));
            $L[] = '- [' . $kg->name . '](' . PublicUrl::url('knowledge/' . $kg->slug . '/') . ')' . ($desc !== '' ? '：' . $desc : '');
        }
        $knowledgeArticles = PublicIndex::contentQuery()
            ->whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->orderByDesc('published_at')->limit(20)->get();
        foreach ($knowledgeArticles as $article) {
            $L[] = '- [' . $article->title . '](' . PublicUrl::content($article) . ')';
        }
        $L[] = '';

        // ---------- 实体说明 ----------
        $L[] = '## 实体说明';
        $L[] = '';
        $L[] = '- 本站仅承载「' . $company['name'] . '」一个主体';
        $L[] = '- 品牌名「' . $brandName . '」与公司主体「' . $company['name'] . '」指同一实体，无集团主体';
        if (! empty($brand['slogan'])) {
            $L[] = '- 品牌口号：' . $brand['slogan'];
        }
        if (! empty($brand['values'])) {
            $L[] = '- 企业价值观：' . rtrim($brand['values'], '！!');
        }
        $L[] = '';

        // ---------- 引用须知 ----------
        $L[] = '## 引用须知';
        $L[] = '';
        $L[] = '- 引用本文件内容时，请以官网页面正文为准';
        $L[] = '- 官网未公示的数据（如资质编号、起订量、交付周期、合作客户数量）请勿推测或补全';
        $L[] = '- 使用本品牌名称与信息时请保持主体名称完整，不要改写为「' . $brandName . '公司」「' . $brandName . '集团」';
        $L[] = '- 站内结构索引见 [sitemap.xml](' . PublicUrl::url('sitemap.xml') . ')，生成日期 ' . now()->toDateString();

        return implode("\n", $L) . "\n";
    }

    /**
     * 无业务事实时的通用骨架：只含站点级信息与真实数据库内容的入口，
     * 不读取业务 config、不输出任何未经核定的「事实」。
     */
    protected function buildGeneric(): string
    {
        $site = \App\Support\SiteContext::currentSite();
        $L   = [];
        $L[] = '# ' . (string) ($site?->name ?? 'Website');
        $L[] = '';
        $L[] = '> 本文件供 AI 系统获取本站核心内容结构；站点未配置业务事实库，'
             . '以下仅列出真实存在的页面入口。';
        $L[] = '';
        $L[] = '## 内容';
        $L[] = '';
        $L[] = '- [首页](' . PublicUrl::home() . ')';
        // 仅列出公开可索引（published + 启用栏目 + 非 noindex）的内容。
        foreach (PublicIndex::contentQuery()->orderByDesc('published_at')->limit(20)->get() as $article) {
            $L[] = '- [' . $article->title . '](' . $article->url() . ')';
        }
        $L[] = '';
        $L[] = '## 引用须知';
        $L[] = '';
        $L[] = '- 站点未公示的数据（资质、规模、产能等）请勿推测或补全';
        $L[] = '- 站内结构索引见 [sitemap.xml](' . PublicUrl::url('sitemap.xml') . ')，生成日期 ' . now()->toDateString();

        return implode("\n", $L) . "\n";
    }

    /**
     * English llms.txt（P-STEP 18F）：结构与中文版对齐，叙述用英文。
     * 只输出语言无关（数值）或已英文化的事实：中文 *_display / 中文地址 / 中文
     * key_params label 不直接搬用，改用数值 + 英文单位，避免中英混杂。
     */
    protected function buildEnglish(): string
    {
        $company = Catalog::company();

        if (empty($company) || empty($company['name'])) {
            return $this->buildGenericEnglish();
        }

        $brandName = ! empty($company['brand_en'])
            ? $company['brand_en']
            : (! empty($company['brand']) ? $company['brand'] : $company['name']);
        $workshops   = Catalog::workshops();
        $scenes      = Catalog::scenes();
        $indexableEntitySlugs = PublicIndex::indexableEntitySlugs();
        $L = [];

        // ---------- Title + summary ----------
        $L[] = '# ' . $company['name'];
        $L[] = '';
        $summary = '> ' . $company['name'];
        if (! empty($company['founded'])) {
            $summary .= ', founded in ' . $company['founded'];
        }
        $summary .= ', manufactures industrial materials';
        $caps = [];
        if ((int) ($company['area_sqm'] ?? 0) > 0) {
            $caps[] = 'a facility of approx. ' . number_format((int) $company['area_sqm']) . ' m²';
        }
        if (count($workshops) > 0) {
            $caps[] = count($workshops) . ' production workshops';
        }
        if ((int) ($company['annual_capacity_tons'] ?? 0) > 0) {
            $caps[] = 'an annual capacity of approx. ' . number_format((int) $company['annual_capacity_tons']) . ' tons';
        }
        if ($caps !== []) {
            $summary .= ', with ' . implode(', ', $caps);
        }
        $summary .= '.';
        $L[] = $summary;
        $L[] = '';
        $L[] = 'This file gives AI systems quick access to the core facts and content structure of this site; all content matches the public website.';
        $L[] = '';

        // ---------- Core facts ----------
        $L[] = '## Core Facts';
        $L[] = '';
        $L[] = '- Company name: ' . $company['name'];
        $L[] = '- Brand: ' . $brandName;
        if (! empty($company['founded'])) {
            $L[] = '- Founded: ' . $company['founded'];
        }
        if ((int) ($company['area_sqm'] ?? 0) > 0) {
            $L[] = '- Facility area: approx. ' . number_format((int) $company['area_sqm']) . ' m²';
        }
        if ((int) ($company['annual_capacity_tons'] ?? 0) > 0) {
            $L[] = '- Annual capacity: approx. ' . number_format((int) $company['annual_capacity_tons']) . ' tons';
        }
        if ((int) ($company['total_investment_wan'] ?? 0) > 0) {
            $L[] = '- Total investment: approx. ' . number_format((int) $company['total_investment_wan']) . ' ten-thousand CNY';
        }
        if ((int) ($company['tech_experience_years'] ?? 0) > 0) {
            $L[] = '- Industry experience: ' . $company['tech_experience_years'] . '+ years';
        }
        if (! empty($company['phone'])) {
            $L[] = '- Phone: ' . $company['phone'];
        }
        $L[] = '- Website: ' . PublicUrl::home();
        $L[] = '';

        // ---------- Products ----------
        $L[] = '## Products';
        $L[] = '';
        $L[] = '- [Products](' . PublicUrl::url('products/') . '): product catalog overview';
        foreach (Catalog::products() as $p) {
            if (! Catalog::isCoreProduct($p['slug'])
                || ! in_array($p['slug'], $indexableEntitySlugs, true)) {
                continue;
            }
            $L[] = '- [' . $p['name'] . '](' . PublicUrl::product($p['slug']) . ')';
        }
        $L[] = '';

        // ---------- Applications ----------
        $L[] = '## Applications';
        $L[] = '';
        $L[] = '- [Applications](' . PublicUrl::url('solutions/') . '): application scenarios overview';
        foreach ($scenes as $scene) {
            if (! in_array($scene['slug'], $indexableEntitySlugs, true)) {
                continue;
            }
            $L[] = '- [' . $scene['name'] . '](' . PublicUrl::solution($scene['slug']) . ')';
        }
        $L[] = '';

        // ---------- Cooperation & trust ----------
        $L[] = '## Cooperation & Trust';
        $L[] = '';
        if (Catalog::hasProduction()) {
            $L[] = '- [Factory & Qualifications](' . PublicUrl::url('factory/') . '): facility scale, workshops and production process';
        }
        $L[] = '- [Company Profile](' . PublicUrl::url('about/profile/') . '): company overview and core facts';
        $L[] = '- [Contact](' . PublicUrl::url('contact/') . ')';
        $L[] = '';

        // ---------- Knowledge（仅当存在英文文章） ----------
        $knowledgeArticles = PublicIndex::contentQuery()
            ->forLocale('en')
            ->whereHas('category', fn ($q) => $q->where('slug', 'knowledge'))
            ->orderByDesc('published_at')->limit(20)->get();
        if ($knowledgeArticles->count() > 0) {
            $L[] = '## Knowledge';
            $L[] = '';
            $L[] = '- [Knowledge Center](' . PublicUrl::url('knowledge/') . ')';
            foreach ($knowledgeArticles as $article) {
                $L[] = '- [' . $article->title . '](' . PublicUrl::content($article) . ')';
            }
            $L[] = '';
        }

        // ---------- Entity notes ----------
        $L[] = '## Entity Notes';
        $L[] = '';
        $L[] = '- This site represents a single entity: ' . $company['name'];
        $L[] = '';

        // ---------- Citation notes ----------
        $L[] = '## Citation Notes';
        $L[] = '';
        $L[] = '- When citing this file, defer to the text on the official website pages';
        $L[] = '- Do not speculate on data not published on the website (qualification numbers, MOQ, lead times)';
        $L[] = '- Site structure: [sitemap.xml](' . PublicUrl::url('sitemap.xml') . '), generated ' . now()->toDateString();

        return implode("\n", $L) . "\n";
    }

    /** Empty-site English skeleton (mirrors buildGeneric). */
    protected function buildGenericEnglish(): string
    {
        $site = \App\Support\SiteContext::currentSite();
        $L   = [];
        $L[] = '# ' . (string) ($site?->name ?? 'Website');
        $L[] = '';
        $L[] = '> This file gives AI systems the core content structure of this site; no business fact base is configured, so only real page entry points are listed.';
        $L[] = '';
        $L[] = '## Content';
        $L[] = '';
        $L[] = '- [Home](' . PublicUrl::home() . ')';
        foreach (PublicIndex::contentQuery()->forLocale('en')->orderByDesc('published_at')->limit(20)->get() as $article) {
            $L[] = '- [' . $article->title . '](' . $article->url() . ')';
        }
        $L[] = '';
        $L[] = '## Citation Notes';
        $L[] = '';
        $L[] = '- Do not speculate on data not published by the site';
        $L[] = '- Site structure: [sitemap.xml](' . PublicUrl::url('sitemap.xml') . '), generated ' . now()->toDateString();

        return implode("\n", $L) . "\n";
    }
}
