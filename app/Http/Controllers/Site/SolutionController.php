<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Narrative;
use App\Support\Pages;
use App\Support\PublicUrl;

/**
 * 应用场景（按应用行业分诊，当前站点 Catalog 站点隔离读模型驱动）
 * 取代旧 ScenarioController / config('scenarios')，URL 由 /scenarios 迁到 /solutions。
 */
class SolutionController extends Controller
{
    public function index(SchemaBuilder $schema)
    {
        // D.2 多站目录隔离：空目录站（无 organization Entity）不渲染场景总览，
        // 与 factory / cooperation / about / contact 及场景详情页的 404 行为保持一致。
        abort_if(empty(Catalog::company()), 404);

        $scenes = Catalog::scenes();
        $lead = Narrative::lead('solutions.index.lead', Pages::narrative('solutions_index'));
        $company    = Catalog::company();
        $brandName  = ! empty($company['brand']) ? $company['brand'] : ($company['name'] ?? '');
        $sceneCnt   = count($scenes);
        $sceneNames = implode('、', array_map(fn ($s) => $s['name'], $scenes));
        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.solutions'), 'url' => PublicUrl::url('solutions/')],
        ];

        $solutionsIndexUrl = PublicUrl::url('solutions/');
        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            '@id'             => $solutionsIndexUrl . '#itemlist',
            'name'            => __('seo.solutions_itemlist_name', ['brand' => $brandName]),
            'itemListElement' => array_values(array_map(function ($s, $i) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $s['name'],
                    'url'      => PublicUrl::solution($s['slug']),
                ];
            }, $scenes, array_keys($scenes))),
        ];

        return view('site.solutions.index', [
            'scenes'  => $scenes,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $schema->webPage($solutionsIndexUrl, __('seo.solutions_index_title', ['count' => $sceneCnt]), __('seo.solutions_index_desc', ['names' => $sceneNames, 'count' => $sceneCnt]), 'CollectionPage', $solutionsIndexUrl . '#itemlist'),
                $itemList,
            ])),
            'seo' => [
                'title'       => __('seo.solutions_index_title', ['count' => $sceneCnt]),
                'description' => __('seo.solutions_index_desc', ['names' => $sceneNames, 'count' => $sceneCnt]),
                'canonical'   => PublicUrl::url('solutions/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    public function show(string $scene, SchemaBuilder $schema)
    {
        $data = Catalog::scene($scene);
        abort_if(! $data, 404);
        // 场景导语可运营：页头与 SEO 描述共用同一覆盖（组合理由 combo_reason 仍读 facts）
        $data['desc'] = Narrative::lead('solutions.scene.' . $scene, $data['desc'] ?? '');

        $combo     = Catalog::sceneCombo($data);
        $adjacent  = Catalog::adjacentScenes($data);
        $keyProduct = ! empty($data['key_param_product']) ? Catalog::product($data['key_param_product']) : null;
        $faqs      = Pages::faqs('scene_faqs', $scene);

        // 上一/下一相邻场景
        $prev = $adjacent[0] ?? null;
        $next = $adjacent[1] ?? null;

        $sceneUrl = PublicUrl::solution($scene);
        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.solutions'), 'url' => PublicUrl::url('solutions/')],
            ['name' => $data['name'], 'url' => $sceneUrl],
        ];

        $schemas = [
            $schema->organization(),
            $schema->breadcrumb($crumbs),
            $schema->webPage($sceneUrl, $data['name'], $data['desc'] ?? '', 'WebPage'),
        ];
        if (! empty($faqs)) {
            $schemas[] = $schema->faqPageFromList($faqs, $sceneUrl);
        }

        return view('site.solutions.show', [
            'scene'      => $data,
            'combo'      => $combo,
            'keyProduct' => $keyProduct,
            'prev'       => $prev,
            'next'       => $next,
            'faqs'       => $faqs,
            'crumbs'     => array_slice($crumbs, 1),
            'schemas'    => array_values(array_filter($schemas)),
            'seo' => [
                'title'       => LocaleContext::current() === 'en'
                    ? $data['name']
                    : ($data['title_q'] ?? $data['name']),
                'description' => LocaleContext::current() === 'en'
                    ? $data['desc']
                    : __('seo.solution_show_desc', ['desc' => $data['desc'], 'reason' => $data['combo_reason'] ?? '']),
                'canonical'   => $sceneUrl,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
