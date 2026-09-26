<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
use App\Support\Render\CaseRenderContext;
use App\Support\Render\CompositionRenderer;
use App\Support\Render\ListingRenderContext;

/**
 * 客户案例中心（P-STEP 18R-2b）。
 *   index  /cases：已发布 case_study 卡片网格（无案例 404，不输出空壳）
 *   show   /cases/{slug}：案例详情（challenge/solution/result + 关联产品 + 客户组织）
 *
 * 数据全部来自站点隔离的 Entity（type=case_study），关联经 EntityRelation；
 * 渲染统一走 CompositionRenderer（块经 BlockRegistry），SEO 走 SeoMetaResolver。
 */
class CaseController extends Controller
{
    public function index(SchemaBuilder $schema)
    {
        $rows = Entity::published()
            ->forLocale(LocaleContext::current())
            ->ofType(Entity::TYPE_CASE_STUDY)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // 无已发布案例 → 404（与空目录站 products/solutions 行为一致，不输出空壳）
        abort_if($rows->isEmpty(), 404);

        $cases = $rows->map(function (Entity $c): array {
            $meta = is_array($c->metadata) ? $c->metadata : [];

            return [
                'url'      => PublicUrl::caseStudy($c->slug),
                'name'     => $c->name,
                'summary'  => $c->summary ?: $c->description,
                'industry' => (string) ($meta['industry'] ?? ''),
                'scenario' => (string) ($meta['scenario'] ?? ''),
            ];
        })->all();

        $casesUrl = PublicUrl::url('cases/');
        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.cases'), 'url' => $casesUrl],
        ];

        $itemList = [
            '@context'         => 'https://schema.org',
            '@type'            => 'ItemList',
            '@id'              => $casesUrl . '#itemlist',
            'name'             => __('nav.cases'),
            'itemListElement'  => array_values(array_map(
                fn (array $c, int $i) => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $c['name'],
                    'url'      => $c['url'],
                ],
                $cases,
                array_keys($cases)
            )),
        ];

        $collectionPage = $schema->webPage(
            $casesUrl,
            __('nav.cases'),
            __('seo.cases_index_desc'),
            'CollectionPage',
            $casesUrl . '#itemlist'
        );

        $resource = [
            'cases'        => $cases,
            'system_block' => 'case_list',
            'canonical'    => $casesUrl,
            'crumbs'       => $crumbs,
            'schemas'      => array_values(array_filter([$collectionPage, $itemList])),
            'seo_default'  => [
                'title'       => __('nav.cases'),
                'description' => __('seo.cases_index_desc'),
            ],
        ];

        return app(CompositionRenderer::class)->render(
            new ListingRenderContext('cases', $resource)
        );
    }

    public function show(string $slug)
    {
        $entity = Entity::published()
            ->forLocale(LocaleContext::current())
            ->ofType(Entity::TYPE_CASE_STUDY)
            ->where('slug', $slug)
            ->first();
        abort_if($entity === null, 404);

        return app(CompositionRenderer::class)->render(
            new CaseRenderContext($entity, $this->assembleCase($entity))
        );
    }

    /**
     * 装配案例详情资源：metadata 五要素 + 关联产品（related_to → product）+
     * 客户组织（related_to → organization，metadata.role=customer）。
     */
    private function assembleCase(Entity $case): array
    {
        $meta = is_array($case->metadata) ? $case->metadata : [];
        $locale = LocaleContext::current();

        $relations = EntityRelation::where('from_entity_id', $case->id)
            ->where('relation_type', EntityRelation::TYPE_RELATED_TO)
            ->get();

        $productIds = [];
        $customerId = null;
        foreach ($relations as $rel) {
            $to = Entity::withoutSiteScope()->find($rel->to_entity_id);
            if ($to === null) {
                continue;
            }
            if ($to->type === Entity::TYPE_PRODUCT) {
                $productIds[] = $to->id;
            } elseif ($to->type === Entity::TYPE_ORGANIZATION
                && (($rel->metadata['role'] ?? null) === 'customer')) {
                $customerId = $to->id;
            }
        }

        $products = [];
        if ($productIds !== []) {
            $products = Entity::published()->forLocale($locale)
                ->whereIn('id', $productIds)->get()
                ->map(function (Entity $p): array {
                    $url = Catalog::isCoreProduct($p->slug)
                        ? PublicUrl::product($p->slug)
                        : PublicUrl::url('products/');

                    return ['name' => $p->name, 'url' => $url];
                })->all();
        }

        $customer = null;
        if ($customerId !== null) {
            $org = Entity::published()->forLocale($locale)->where('id', $customerId)->first();
            if ($org !== null) {
                $customer = ['name' => $org->name];
            }
        }

        return [
            'industry'  => (string) ($meta['industry'] ?? ''),
            'scenario' => (string) ($meta['scenario'] ?? ''),
            'challenge' => (string) ($meta['challenge'] ?? ''),
            'solution'  => (string) ($meta['solution'] ?? ''),
            'result'    => (string) ($meta['result'] ?? ''),
            'products'  => $products,
            'customer'  => $customer,
        ];
    }
}
