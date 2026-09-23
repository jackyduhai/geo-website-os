<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Content;
use App\Models\PageBlock;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Catalog;
use App\Support\HomeBlockDefaults;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\Http\Request;

/**
 * 首页（S01–S10，实体中心 + 可整体装修）
 *
 * 渲染顺序 / 显隐 / 标题 / 手动选稿仍由 page_blocks 决定（后台首页装修可运营）；
 * 结构化默认内容（场景、参数、合作、车间、案例、FAQ）统一来自 Catalog 站点目录读模型
 * （Entity 投影）与 config/pages，信任数字只从已核定事实派生，不允许虚构。
 */
class HomeController extends Controller
{
    public function index(Request $request, SchemaBuilder $schema, SeoMetaResolver $seoResolver)
    {
        $blocks = PageBlock::forPage('home')->active()->with('category')->get();
        $byType = $blocks->keyBy('type');

        $company = Catalog::company();

        $data = [
            'blocks'  => $blocks,
            'banners' => Banner::active()->at('home_top')->with('image')->get(),
            // 首页中部横幅（由「首页中部横幅」装修区块驱动，无图时该区块自身不渲染）
            'midBanners' => Banner::active()->at('home_mid')->with('image')->get(),
            'company' => $company,
            'schemas' => [
                $schema->organization(),
                $schema->website(),
            ],
        ];

        // S01 Hero：取首个核心产品的关键参数卡（数据驱动，最多 5 行，真实可溯）
        $data['heroProduct'] = collect(Catalog::products())->firstWhere('core', true) ?: null;
        $data['heroParams'] = $this->heroParams($data['heroProduct']);

        // S02 应用场景（后台条目优先，缺省取统一默认源）
        $sceneBlock = $byType->get('scenes');
        $data['sceneList'] = ($sceneBlock && $it = $sceneBlock->items()) ? $it : HomeBlockDefaults::scenes();

        // S03 产品体系（含产品数，各系列由视图按实际数量呈现）
        $data['productLines'] = collect(Catalog::productLines())->map(function ($l) {
            $l['products'] = Catalog::productsByLine($l['slug']);
            $l['count'] = count($l['products']);
            return $l;
        })->all();

        // S04 反白参数表（行数随核心产品数据驱动）+ 差异化条目（由首页装修区块
        // 承载，仅 Demo 装载；出厂空站无区块时为空，模板不渲染制造口径）
        $data['paramRows'] = $this->s04Rows();
        $paramsBlock = $byType->get('params');
        $data['paramDifferentiators'] = ($paramsBlock && $paramsItems = $paramsBlock->items()) ? $paramsItems : [];
        // 区块未自定义时的缺省差异化条目：渲染时按当前 locale 取翻译键（不写死、不进 block content）
        $data['differentiatorItems'] = [
            ['title' => __('ui.param_diff_1_title'), 'text' => __('ui.param_diff_1_text')],
            ['title' => __('ui.param_diff_2_title'), 'text' => __('ui.param_diff_2_text')],
            ['title' => __('ui.param_diff_3_title'), 'text' => __('ui.param_diff_3_text')],
        ];

        // S05 信任数据条 + 生产车间（后台区块可覆盖，缺省取 Catalog 站点目录并带默认图标）
        $data['stats'] = $this->buildStats($company);
        $data['workshopItems'] = $this->workshopItems($byType->get('workshops'));

        // S06 合作方式 + 合作流程（条目后台可覆盖，缺省取统一默认源）
        $coopBlock = $byType->get('cooperation');
        $data['coopModes'] = ($coopBlock && $it = $coopBlock->items()) ? $it : HomeBlockDefaults::cooperation();
        $data['stepItems'] = $this->stepItems($byType->get('steps'));

        // S07 匿名合作剪影（后台条目优先，缺省取统一默认源，匿名无引述）
        $caseBlock = $byType->get('cases');
        $data['caseList'] = ($caseBlock && $it = $caseBlock->items()) ? $it : HomeBlockDefaults::cases();

        // S09 首页 FAQ（条数由 config/pages 驱动，后台区块可覆盖）
        $data['homeFaqs'] = $this->homeFaqItems($byType->get('faqs'));

        // 能力点（后台可装修，缺省内置）
        $data['capabilityItems'] = $this->capabilityItems($byType->get('capabilities'));

        // 资质与产能事实条（S6 GEO 证据层）：统一从 Catalog 站点目录派生（中英同源），
        // 不直接读单语言 Fact 表；剔除已在页眉/页脚出现的公司全称、品牌名、官方电话。
        $data['factRows'] = $this->homeFactRows($company);

        // 知识 / 新闻：手动指定优先，否则按来源栏目取最新
        $data['knowledgeItems'] = $this->sourceItems($byType->get('knowledge'));
        $data['newsItems'] = $this->sourceItems($byType->get('news'));

        // 首页 FAQ 输出 FAQPage（与可见内容一致）
        if (! empty($data['homeFaqs'])) {
            $faqPairs = array_map(fn ($f) => ['q' => $f['title'] ?? $f['q'] ?? '', 'a' => $f['text'] ?? $f['a'] ?? ''], $data['homeFaqs']);
            $data['schemas'][] = $schema->faqPageFromList($faqPairs, PublicUrl::home());
        }

        // SEO: 使用 SeoMetaResolver 统一解析
        $currentSite = SiteContext::currentSite();
        if ($currentSite) {
            $seoResult = $seoResolver->resolveSite($currentSite);
            $data['seo'] = [
                'title'       => $seoResult->title,
                'title_full'  => $seoResult->title,
                'description' => $seoResult->description,
                'canonical'   => $seoResult->canonical,
                'noindex'     => $seoResult->noindex,
                'type'        => $seoResult->ogType,
                'image'          => $seoResult->ogImage,
                'og_title'       => $seoResult->ogTitle,
                'og_description' => $seoResult->ogDescription,
                'twitter_card'   => $seoResult->twitterCard,
            ];
        } else {
            // Fallback: 开发环境兼容
            $data['seo'] = [
                'title_full'  => 'Website',
                'description' => '',
                'canonical'   => PublicUrl::home(),
                'noindex'     => false,
                'type'        => 'website',
            ];
        }

        return view('site.home', $data);
    }

    /**
     * S01 Hero 参数卡：直接取产品数据中的关键参数 key_params（数据驱动，最多 5 行）。
     * 不按任何行业专属的参数名 / 工序名过滤，换一套事实数据即可自动呈现对应参数。
     */
    private function heroParams(?array $p): array
    {
        if (! $p) {
            return [];
        }
        $rows = [];
        foreach (($p['key_params'] ?? []) as $kp) {
            if (empty($kp['label'])) {
                continue;
            }
            $rows[] = ['label' => $kp['label'], 'value' => $kp['value'] ?? ''];
            if (count($rows) >= 5) {
                break;
            }
        }
        return $rows;
    }

    /** S04 参数对比行：取核心产品（最多 6 个）的关键参数值，全部源自 Catalog 站点目录。 */
    private function s04Rows(): array
    {
        $rows = [];
        foreach (Catalog::coreProductSlugs() as $slug) {
            $p = Catalog::product($slug);
            if (! $p) {
                continue;
            }
            $vals = [];
            foreach (($p['key_params'] ?? []) as $kp) {
                if (! empty($kp['value'])) {
                    $vals[] = $kp['value'];
                }
            }
            $rows[] = ['label' => $p['short_name'] ?? $p['name'], 'value' => implode('｜', $vals)];
            if (count($rows) >= 6) {
                break;
            }
        }
        return $rows;
    }


    private function capabilityItems(?PageBlock $block): array
    {
        return ($block && $items = $block->items()) ? $items : HomeBlockDefaults::capabilities();
    }

    /** 生产车间：后台装修优先，缺省取统一默认源。 */
    private function workshopItems(?PageBlock $block): array
    {
        return ($block && $items = $block->items()) ? $items : HomeBlockDefaults::workshops();
    }

    /** 合作流程：后台装修优先，缺省取统一默认源。 */
    private function stepItems(?PageBlock $block): array
    {
        return ($block && $items = $block->items()) ? $items : HomeBlockDefaults::steps();
    }

    private function homeFaqItems(?PageBlock $block): array
    {
        return ($block && $items = $block->items()) ? $items : HomeBlockDefaults::faqs();
    }

    private function sourceItems(?PageBlock $block)
    {
        if (! $block) {
            return collect();
        }
        $ids = $block->pickedIds();
        if ($ids) {
            $base = Content::published()->with(['category', 'cover'])->whereIn('id', $ids)->get();
            $groupOrder = $base->pluck('translation_group')->all();
            $list = $this->localizeContentRows($base, LocaleContext::current());
            return $list->sortBy(function ($c) use ($groupOrder) {
                $pos = array_search($c->translation_group, $groupOrder, true);
                return $pos === false ? 999 : $pos;
            })->values();
        }
        if (! $block->category) {
            return collect();
        }
        $base = Content::published()
            ->with(['category', 'cover'])
            ->where('locale', \App\Support\Localization\LocaleRegistry::default())
            ->where('category_id', $block->category->id)
            ->orderByDesc('published_at')
            ->limit($block->limit ?: 6)
            ->get();

        return $this->localizeContentRows($base, LocaleContext::current());
    }

    /**
     * 把默认语言权威内容行映射为目标语言行（同 translation_group）。
     * 默认语言直接返回；目标语言无翻译的行不出现（Missing Translation Policy）。
     */
    private function localizeContentRows($base, string $locale)
    {
        if ($locale === \App\Support\Localization\LocaleRegistry::default()) {
            return $base;
        }
        $groups = $base->pluck('translation_group')->all();
        if ($groups === []) {
            return collect();
        }
        return Content::published()
            ->with(['category', 'cover'])
            ->where('locale', $locale)
            ->whereIn('translation_group', $groups)
            ->get();
    }

    /**
     * 首页资质与产能事实条：从 Catalog 站点目录（中英同源）构建，标签走翻译键，
     * 空值条目不渲染；避免直接读单语言 Fact 表导致英文页中文。
     */
    private function homeFactRows(array $company)
    {
        $workshops = collect(Catalog::workshops())->pluck('name')->implode(' / ');
        $regions = collect(Catalog::salesRegions())->implode(' / ');
        $lines = collect(Catalog::productLines())->pluck('name')->implode(' / ');

        $rows = [
            ['label' => __('ui.fact_founded'),    'value' => ($company['founded_display'] ?? '') ?: ($company['founded'] ?? '')],
            ['label' => __('ui.fact_production'), 'value' => $company['established_production_display'] ?? ''],
            ['label' => __('ui.fact_area'),       'value' => $company['area_display'] ?? ''],
            ['label' => __('ui.fact_capacity'),   'value' => $company['annual_capacity_display'] ?? ''],
            ['label' => __('ui.fact_investment'), 'value' => $company['total_investment_display'] ?? ''],
            ['label' => __('ui.fact_workshops'),  'value' => $workshops],
            ['label' => __('ui.fact_regions'),    'value' => $regions],
            ['label' => __('ui.fact_lines'),      'value' => $lines],
        ];

        return collect($rows)->filter(fn ($r) => trim((string) $r['value']) !== '')->values();
    }

    /**
     * S05 信任数据条（数值全部源自 Catalog 站点目录，标签行业中立）。
     * 出厂空站无公司事实时返回空，由区块自隐藏，绝不渲染全 0 空壳。
     */
    private function buildStats(array $company): array
    {
        if (empty($company)) {
            return [];
        }
        $industry = trim((string) ($company['industry'] ?? ''));

        // 仅输出真实存在的事实数值，数值为 0 的条目整体隐藏；单位 / 标签按 locale。
        $isEn = LocaleContext::current() === 'en';
        $stats = [
            ['num' => (int) ($company['tech_experience_years'] ?? 0), 'unit' => __('seo.factory_unit_year'), 'label' => $isEn
                ? __('seo.home_stat_exp_generic')
                : ($industry !== '' ? __('seo.home_stat_exp_label', ['industry' => $industry]) : __('seo.home_stat_exp_generic'))],
            ['num' => (int) ($company['area_sqm'] ?? 0), 'unit' => '㎡', 'label' => __('seo.home_stat_area_label')],
            ['num' => (int) ($company['annual_capacity_tons'] ?? 0), 'unit' => __('seo.factory_unit_ton'), 'label' => __('seo.home_stat_capacity_label')],
            ['num' => count(Catalog::workshops()), 'unit' => __('seo.home_unit_count'), 'label' => __('seo.home_stat_workshops_label')],
            ['num' => count(Catalog::salesRegions()), 'unit' => __('seo.home_unit_count'), 'label' => __('seo.home_stat_regions_label')],
        ];

        return array_values(array_filter($stats, static fn ($row) => (int) $row['num'] > 0));
    }
}
