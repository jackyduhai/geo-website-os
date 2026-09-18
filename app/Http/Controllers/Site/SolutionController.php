<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Geo\SchemaBuilder;
use App\Support\Facts;
use App\Support\Narrative;

/**
 * 应用场景（六类客户生意分诊，config/facts 驱动）
 * 取代旧 ScenarioController / config('scenarios')，URL 由 /scenarios 迁到 /solutions。
 */
class SolutionController extends Controller
{
    public function index(SchemaBuilder $schema)
    {
        $scenes = Facts::scenes();
        $lead = Narrative::lead('solutions.index.lead', config('pages.narrative.solutions_index.lead', ''));
        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '应用场景', 'url' => url('/solutions/')],
        ];

        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => 'Example六类应用场景',
            'itemListElement' => array_values(array_map(function ($s, $i) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $s['name'],
                    'url'      => url('/solutions/' . $s['slug'] . '/'),
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
                $itemList,
            ])),
            'seo' => [
                'title'       => '你的店属于哪一类？六类门店Sample Marinade组合方案',
                'description' => 'Sample Snack创业小店、夜市烧烤、连锁外卖、中餐食堂、轻食健身、卤味烤串六类场景，给出对应Sample MarinadeSample Breading撒料组合、为什么这么配与真实工艺参数。',
                'canonical'   => url('/solutions/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    public function show(string $scene, SchemaBuilder $schema)
    {
        $data = Facts::scene($scene);
        abort_if(! $data, 404);
        // 场景导语可运营：页头与 SEO 描述共用同一覆盖（组合理由 combo_reason 仍读 facts）
        $data['desc'] = Narrative::lead('solutions.scene.' . $scene, $data['desc'] ?? '');

        $combo     = Facts::sceneCombo($data);
        $adjacent  = Facts::adjacentScenes($data);
        $keyProduct = ! empty($data['key_param_product']) ? Facts::product($data['key_param_product']) : null;
        $faqs      = config('pages.scene_faqs.' . $scene, []);

        // 上一/下一相邻场景
        $prev = $adjacent[0] ?? null;
        $next = $adjacent[1] ?? null;

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '应用场景', 'url' => url('/solutions/')],
            ['name' => $data['name'], 'url' => url('/solutions/' . $scene . '/')],
        ];

        $schemas = [
            $schema->organization(),
            $schema->breadcrumb($crumbs),
        ];
        if (! empty($faqs)) {
            $schemas[] = $schema->faqPageFromList($faqs, url('/solutions/' . $scene . '/'));
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
                'title'       => $data['title_q'],
                'description' => $data['desc'] . '。' . ($data['combo_reason'] ?? ''),
                'canonical'   => url('/solutions/' . $scene . '/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
