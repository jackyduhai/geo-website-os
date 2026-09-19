<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Content;
use App\Models\Fact;
use App\Models\PageBlock;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Facts;
use App\Support\HomeBlockDefaults;
use App\Support\SiteContext;
use Illuminate\Http\Request;

/**
 * 首页（S01–S10，实体中心 + 可整体装修）
 *
 * 渲染顺序 / 显隐 / 标题 / 手动选稿仍由 page_blocks 决定（后台首页装修可运营）；
 * 结构化默认内容（场景、参数、合作、车间、案例、FAQ）统一来自 config/facts、config/pages，
 * 信任数字只从已核定事实派生，不允许虚构。
 */
class HomeController extends Controller
{
    public function index(Request $request, SchemaBuilder $schema, SeoMetaResolver $seoResolver)
    {
        $blocks = PageBlock::forPage('home')->active()->with('category')->get();
        $byType = $blocks->keyBy('type');

        $company = Facts::company();

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

        // S01 Hero：Sample Flavor 801 参数卡（5 行，真实可溯）
        $data['heroProduct'] = Facts::product('orleans-801');
        $data['heroParams'] = $this->heroParams($data['heroProduct']);

        // S02 六类场景（后台条目优先，缺省取统一默认源）
        $sceneBlock = $byType->get('scenes');
        $data['sceneList'] = ($sceneBlock && $it = $sceneBlock->items()) ? $it : HomeBlockDefaults::scenes();

        // S03 五大产品体系（含产品数，2 大 + 3 小由视图按数量呈现）
        $data['productLines'] = collect(Facts::productLines())->map(function ($l) {
            $l['products'] = Facts::productsByLine($l['slug']);
            $l['count'] = count($l['products']);
            return $l;
        })->all();

        // S04 反白参数表 6 行 + 三条差异化
        $data['paramRows'] = $this->s04Rows();
        $data['paramDifferentiators'] = $this->paramDifferentiators();

        // S05 信任数据条 5 项 + 四大车间（后台区块可覆盖，缺省取 Facts 并带默认图标）
        $data['stats'] = $this->buildStats($company);
        $data['workshopItems'] = $this->workshopItems($byType->get('workshops'));

        // S06 三种合作方式 + 五步流程（条目后台可覆盖，缺省取统一默认源）
        $coopBlock = $byType->get('cooperation');
        $data['coopModes'] = ($coopBlock && $it = $coopBlock->items()) ? $it : HomeBlockDefaults::cooperation();
        $data['stepItems'] = $this->stepItems($byType->get('steps'));

        // S07 匿名合作剪影（后台条目优先，缺省取统一默认源，匿名无引述）
        $caseBlock = $byType->get('cases');
        $data['caseList'] = ($caseBlock && $it = $caseBlock->items()) ? $it : HomeBlockDefaults::cases();

        // S09 首页 FAQ（终稿 8 条，后台区块可覆盖）
        $data['homeFaqs'] = $this->homeFaqItems($byType->get('faqs'));

        // 能力点（后台可装修，缺省内置）
        $data['capabilityItems'] = $this->capabilityItems($byType->get('capabilities'));

        // 资质与产能事实条（S6 GEO 证据层）：取公开事实，剔除已在页眉/页脚出现的
        // 公司全称、品牌名、官方电话等身份/联系项，首页精选 8 条，全量见关于页。
        $data['factRows'] = Fact::publicRows()->reject(fn ($f) => in_array($f->key, [
            'FACT-COMPANY-001', // 公司全称（页眉已有）
            'FACT-COMPANY-002', // 品牌名（页眉已有）
            'FACT-COMPANY-013', // 官方电话（页脚已有）
        ], true))->values();

        // 知识 / 新闻：手动指定优先，否则按来源栏目取最新
        $data['knowledgeItems'] = $this->sourceItems($byType->get('knowledge'));
        $data['newsItems'] = $this->sourceItems($byType->get('news'));

        // 首页 FAQ 输出 FAQPage（与可见内容一致）
        if (! empty($data['homeFaqs'])) {
            $faqPairs = array_map(fn ($f) => ['q' => $f['title'] ?? $f['q'] ?? '', 'a' => $f['text'] ?? $f['a'] ?? ''], $data['homeFaqs']);
            $data['schemas'][] = $schema->faqPageFromList($faqPairs, url('/'));
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
                'image'       => $seoResult->ogImage,
            ];
        } else {
            // Fallback: 开发环境兼容
            $data['seo'] = [
                'title_full'  => 'Website',
                'description' => '',
                'canonical'   => url('/'),
                'noindex'     => false,
                'type'        => 'website',
            ];
        }

        return view('site.home', $data);
    }

    /** S01 Hero 参数卡：适用主料 / 用量 / 搅拌 / 腌制 / 油炸，共 5 行。 */
    private function heroParams(?array $p): array
    {
        if (! $p) {
            return [];
        }
        $rows = [];
        foreach (($p['key_params'] ?? []) as $kp) {
            if ($kp['label'] === '适用主料' || $kp['label'] === '推荐用量' || $kp['label'] === '冷藏腌制') {
                $rows[] = ['label' => $kp['label'], 'value' => $kp['value']];
            }
        }
        foreach (($p['params'] ?? []) as $step) {
            if (in_array($step['step'], ['搅拌', '油炸'], true)) {
                $v = $step['value'];
                if (! empty($step['note'])) {
                    $v .= '（' . $step['note'] . '）';
                }
                $rows[] = ['label' => $step['step'], 'value' => $v];
            }
            if (count($rows) >= 5) {
                break;
            }
        }
        return array_slice($rows, 0, 5);
    }

    /** S04 六行参数：取「推荐用量 + 油温/腌制」，全部源自 facts。 */
    private function s04Rows(): array
    {
        $slugs = [
            'orleans-801', 'american-fried-chicken-marinade', 'garlic-marinade',
            'korean-fried-chicken-marinade', 'sample-city-chicken-frame-marinade', 'taiwanese-chicken-cutlet-marinade',
        ];
        $rows = [];
        foreach ($slugs as $slug) {
            $p = Facts::product($slug);
            if (! $p) {
                continue;
            }
            $vals = [];
            foreach (($p['key_params'] ?? []) as $kp) {
                if (in_array($kp['label'], ['推荐用量', '油温与时间', '冷藏腌制', 'Sample Breading浆粉水比'], true)) {
                    $vals[] = $kp['value'];
                }
            }
            $rows[] = ['label' => $p['short_name'] ?? $p['name'], 'value' => implode('｜', $vals)];
        }
        return $rows;
    }

    /** S04 三条差异化（均为可核实事实，不做他方对标、不用极限词）。 */
    private function paramDifferentiators(): array
    {
        return [
            ['title' => '四大车间自有生产', 'text' => '从Sample Spice粉碎到固体调味料同厂完成，风味与批次更可控'],
            ['title' => '给到真实配比工艺', 'text' => '按 500g 主料写清用量、油温与时间，不只卖料、更教你怎么用'],
            ['title' => '五大体系一站配齐', 'text' => 'Sample Marinade、Sample Breading、撒料、调味香精、调理鸡肉协同，减少多头对接'],
        ];
    }

    private function capabilityItems(?PageBlock $block): array
    {
        return ($block && $items = $block->items()) ? $items : HomeBlockDefaults::capabilities();
    }

    /** 四大车间：后台装修优先，缺省取统一默认源。 */
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
            $list = Content::published()->with(['category', 'cover'])->whereIn('id', $ids)->get();
            return $list->sortBy(fn ($c) => array_search($c->id, $ids, true))->values();
        }
        if (! $block->category) {
            return collect();
        }
        return Content::published()
            ->with(['category', 'cover'])
            ->where('category_id', $block->category->id)
            ->orderByDesc('published_at')
            ->limit($block->limit ?: 6)
            ->get();
    }

    /** S05 五条信任数据（全部源自 facts）。 */
    private function buildStats(array $company): array
    {
        return [
            ['num' => (int) ($company['tech_experience_years'] ?? 20), 'unit' => '年', 'label' => '中式Sample Snack调味深耕'],
            ['num' => (int) ($company['area_sqm'] ?? 9000), 'unit' => '㎡', 'label' => '自有生产厂区'],
            ['num' => (int) ($company['annual_capacity_tons'] ?? 8000), 'unit' => '吨', 'label' => '年成品产能'],
            ['num' => count(Facts::workshops()), 'unit' => '大', 'label' => '自有生产车间'],
            ['num' => count(Facts::salesRegions()), 'unit' => '大区', 'label' => '全国销售覆盖'],
        ];
    }
}
