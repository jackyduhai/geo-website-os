<?php

namespace App\Services\Geo;

use App\Models\Fact;
use App\Support\Catalog;
use App\Support\PublicIndex;
use App\Support\PublicUrl;
use App\Support\Localization\LocaleContext;

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
 *     0 车间 / 年产能」这类空壳句，也不输出会 404 / 500 的链接；
 *   - 输出前统一经 {@see LlmsSanitizer} 终洗兜底（20F-HAT P1-3）：AI 面向输出
 *     绝不出现 0 值统计、空键值行、悬挂标点。四个出口（中文/英文 × 站点/通用降级）
 *     全部在 build() 收口，避免后续新增分支绕过终洗。
 *
 * ── 业务事实 SoT（RC-9 D-02 架构决策锁定）──────────────────────
 * **业务数值类事实**（成立时间 / 面积 / 产能 / 总投资 / 技术积累 / 业务模式等）
 * 必须来自 `facts` 表（Business Fact 的唯一 Canonical SoT），经
 * {@see self::businessFacts()} 取得，**不得从 organization.metadata 另读一套**。
 *
 * 历史问题：同一批事实曾存在两份——`facts` 表（仅 zh-CN）与
 * `organization.metadata['company']`（含 `_en`）。二者 locale 覆盖不同，
 * 导致英文站 `geo.json` 的 facts 为空、`llms.txt` 却能输出英文事实，
 * AI 同时消费两端会得到**矛盾答案**。
 *
 * 边界（分层 SoT）：
 *   · 本类由 facts 表驱动 → geo.json + llms.txt，跨出口口径一致；
 *   · 实体属性类（legalName / address / areaServed / knowsAbout）
 *     仍由 organization.metadata 驱动，`SchemaBuilder` **不直接读 facts**
 *     （遵守 SchemaBuilder.php:34 已冻结的数据源边界）；
 *   · 跨出口一致性由 tests/Feature/Geo/GeoFactConsistencyTest.php 契约保证。
 *
 * @see \docs\audit\RC\D-02-Architecture-Decision-Lock.md
 */
class LlmsBuilder
{
    public function __construct(private readonly LlmsSanitizer $sanitizer)
    {
    }

    /**
     * 业务事实读取入口 —— 唯一允许 llms.txt 取得业务事实的通道。
     *
     * 走 `Fact::publicRows()`：自带 SiteScope（站点隔离）、locale 门禁
     * （缺该语言译文则不出现在该语言输出里）与 `is_public` 过滤，
     * 与 geo.json 的事实出口**同源同规则**。
     */
    protected function businessFacts(): \Illuminate\Support\Collection
    {
        return Fact::publicRows();
    }

    /**
     * 单条业务事实取值；不存在或未公开时返回 null（调用方据此跳过该行）。
     */
    protected function businessFact(string $key): ?string
    {
        $fact = $this->businessFacts()->firstWhere('key', $key);
        $value = trim((string) ($fact?->value ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * 渲染全部业务事实为 llms.txt 的 Markdown 行（**不含标题**）。
     *
     * 标签取自 {@see self::FACT_LABELS} 的当前语言映射，**不使用事实行自身的
     * `label`** —— 因为 `geo.json` 输出的是 `Fact.label`（运营可编辑的原始标签），
     * 而 llms.txt 用固定映射。两者若不一致，AI 无法把两个出口的同一条事实对齐，
     * 等于又制造了一次口径分叉（RC-9 D-02 的同类问题）。
     *
     * locale 标签来自映射表，不做跨语言回落 —— 缺译文就不输出，
     * 与 geo.json 的门禁行为一致。
     */
    protected function businessFactLines(): array
    {
        $locale = (string) (LocaleContext::has() ? LocaleContext::current() : 'zh-CN');

        $lines = [];
        foreach ($this->businessFacts() as $fact) {
            $key = (string) $fact->key;

            // 标签取自共享契约 {@see FactLabels}，与 geo.json 出口同源，
            // 保证同一事实在两个 AI 出口的标签可被对齐。
            // 不在契约内的 key 不输出：无法保证标签对齐，宁可不写。
            $label = FactLabels::for($key, $locale);
            if ($label === null) {
                continue;
            }

            $value = trim((string) $fact->value);
            if ($value === '') {
                continue;
            }
            $lines[] = '- ' . $label . ': ' . $value;
        }

        return $lines;
    }

    public function build(): string
    {
        return $this->sanitizer->clean(explode("\n", $this->compose()));
    }

    /** 组装（未终洗）。语言与降级分支在此分流，终洗统一在 build() 出口。 */
    private function compose(): string
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
        // 摘要里的数值同样取自 facts 表（RC-9 D-02），避免摘要与「核心事实」
        // 段落出现两套口径；行业 / 客户群 / 区域属叙述性内容，仍走 Catalog。
        $L[] = '# ' . $company['name'];
        $L[] = '';
        $summary = '> ' . $company['name'];
        if ($fFounded = $this->businessFact('FACT-COMPANY-003')) {
            $summary .= '，' . $fFounded . '成立';
        }
        if ($industry !== '') {
            $summary .= '，专注' . $industry . '的研发、生产与销售';
        }
        $capacity = [];
        if ($fArea = $this->businessFact('FACT-COMPANY-006')) {
            $capacity[] = '自有' . $fArea . '厂区';
        }
        if ($workshopCnt > 0) {
            $capacity[] = $workshopCnt . '个生产车间';
        }
        if ($fCap = $this->businessFact('FACT-COMPANY-007')) {
            $capacity[] = '年产能' . $fCap;
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
        // 业务数值类事实统一走 facts 表（RC-9 D-02：Business Fact 唯一 SoT），
        // 与 geo.json 同源同规则；地址 / 电话属实体属性，仍由 metadata 提供。
        $L[] = '## 核心事实';
        $L[] = '';
        foreach ($this->businessFactLines() as $line) {
            $L[] = $line;
        }
        if (! empty($company['phone'])) {
            $L[] = '- 官方电话：' . $company['phone'];
        }
        $L[] = '- 官网：' . PublicUrl::home();
        $L[] = '';
        // 时间表述口径同样引用 facts，保证与上面各行同源。
        $exp = $this->businessFact('FACT-COMPANY-012');
        $founded = $this->businessFact('FACT-COMPANY-003');
        $prod = $this->businessFact('FACT-COMPANY-004');
        if ($exp !== null && ($founded !== null || $prod !== null)) {
            $L[] = '**时间表述口径**：谈经验用「' . $exp . '」，'
                 . '谈公司用「' . ($founded ?? '') . '成立」，'
                 . '谈产能用「' . ($prod ?? '') . '投产」，三者各有所指。';
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

        // ---------- 客户案例（18R-2b：仅当存在已发布且可索引案例时输出，避免空段落） ----------
        $caseRows = PublicIndex::entityQuery()->forLocale(LocaleContext::current())
            ->ofType(\App\Models\Entity::TYPE_CASE_STUDY)->orderBy('sort_order')->get();
        $caseList = [];
        foreach ($caseRows as $case) {
            if (in_array($case->slug, $indexableEntitySlugs, true)) {
                $caseList[] = $case;
            }
        }
        if ($caseList !== []) {
            $L[] = '## 客户案例';
            $L[] = '';
            $L[] = '- [客户案例](' . PublicUrl::url('cases/') . ')：各行业落地实践与成效';
            foreach ($caseList as $case) {
                $meta = is_array($case->metadata) ? $case->metadata : [];
                $ind = $meta['industry'] ?? '';
                $suffix = $ind !== '' ? '：' . $ind : '';
                $L[] = '- [' . $case->name . '](' . PublicUrl::caseStudy($case->slug) . ')' . $suffix;
            }
            $L[] = '';
        }

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
        if ($fFounded = $this->businessFact('FACT-COMPANY-003')) {
            $L[] = '- [发展历程](' . PublicUrl::url('about/history/') . ')：' . $fFounded . '成立至今的可核实节点';
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
        // forLocale：20G-3 修复 —— 缺它会让中文 llms.txt 混入英文文章
        // （此前的 buildEnglish 里有、buildGeneric 里漏了，两条路径不一致）。
        $knowledgeArticles = PublicIndex::contentQuery()
            ->forLocale(LocaleContext::current())
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
        // forLocale：20G-3 修复 —— 通用骨架也必须按当前语言取内容，
        // 否则 /llms.txt（中文站）会列出英文文章（实测复现：中文 llms.txt
        // 同时输出 ZH Article 与 EN Article）。
        foreach (PublicIndex::contentQuery()
            ->forLocale(LocaleContext::current())
            ->orderByDesc('published_at')->limit(20)->get() as $article) {
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
        // 摘要里的数值同样取自 facts 表（RC-9 D-02），避免摘要与 Core Facts
        // 段落出现两套口径。取不到就整句省略，不拼 0 值空壳。
        $L[] = '# ' . $company['name'];
        $L[] = '';
        $summary = '> ' . $company['name'];
        if ($founded = $this->businessFact('FACT-COMPANY-003')) {
            $summary .= ', founded in ' . $founded;
        }
        $summary .= ', manufactures industrial materials';
        $caps = [];
        if ($area = $this->businessFact('FACT-COMPANY-006')) {
            $caps[] = 'a facility of ' . lcfirst($area);
        }
        if (count($workshops) > 0) {
            $caps[] = count($workshops) . ' production workshops';
        }
        if ($capacity = $this->businessFact('FACT-COMPANY-007')) {
            $caps[] = 'an annual capacity of ' . lcfirst($capacity);
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
        // 业务数值类事实统一走 facts 表（RC-9 D-02：Business Fact 唯一 SoT），
        // 与 geo.json 同源同规则；phone / website 属实体属性，仍由 metadata 提供。
        $L[] = '## Core Facts';
        $L[] = '';
        foreach ($this->businessFactLines() as $line) {
            $L[] = $line;
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

        // ---------- Case Studies (18R-2b: only when published indexable cases exist) ----------
        $caseRowsEn = PublicIndex::entityQuery()->forLocale('en')
            ->ofType(\App\Models\Entity::TYPE_CASE_STUDY)->orderBy('sort_order')->get();
        $caseListEn = [];
        foreach ($caseRowsEn as $case) {
            if (in_array($case->slug, $indexableEntitySlugs, true)) {
                $caseListEn[] = $case;
            }
        }
        if ($caseListEn !== []) {
            $L[] = '## Case Studies';
            $L[] = '';
            $L[] = '- [Case Studies](' . PublicUrl::url('cases/') . '): implementations and results across industries';
            foreach ($caseListEn as $case) {
                $meta = is_array($case->metadata) ? $case->metadata : [];
                $ind = $meta['industry'] ?? '';
                $suffix = $ind !== '' ? ': ' . $ind : '';
                $L[] = '- [' . $case->name . '](' . PublicUrl::caseStudy($case->slug) . ')' . $suffix;
            }
            $L[] = '';
        }

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
