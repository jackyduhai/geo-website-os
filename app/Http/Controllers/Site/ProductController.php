<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Providers\AppServiceProvider;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\Narrative;
use App\Support\Pages;
use App\Support\PublicUrl;
use App\Support\Render\CompositionRenderer;
use App\Support\Render\EntityRenderContext;

/**
 * 产品中心（当前站点 Catalog 站点隔离读模型驱动，Catalog 由 Entity 投影，Example 种子源自 config/facts）
 *   index  产品总览：按产品线分组 + 锚点（空目录站 404）
 *   line   单系列页：仅当该系列含产品时才独立成页，否则 301 到总览锚点
 *   show   核心产品详情（仅标记 core 的产品有独立详情页，八区块）
 */
class ProductController extends Controller
{
    public function index(SchemaBuilder $schema)
    {
        // D.2 多站目录隔离：空目录站（无 organization Entity）不渲染产品总览，
        // 与 factory / cooperation / about / contact 及产品详情页的 404 行为保持一致。
        abort_if(empty(Catalog::company()), 404);

        $lines = collect(Catalog::productLines())->map(function ($line) {
            $line['products'] = Catalog::productsByLine($line['slug']);
            // 系列导语可运营（总览分组说明与独立系列页共用同一覆盖）
            $line['desc'] = Narrative::lead('products.line.' . $line['slug'], $line['desc'] ?? '');
            return $line;
        })->all();

        // Generic fallback (P-STEP 17B): when the organization defines no product
        // lines, render every product in a single flat group instead of an empty page.
        $flatMode = false;
        if (empty($lines) && ! empty(Catalog::products())) {
            $flatMode = true;
            $lines = [[
                'slug'     => null,
                'name'     => __('nav.all_products'),
                'desc'     => '',
                'products' => Catalog::products(),
            ]];
        }

        $lead = Narrative::lead('products.index.lead', Pages::narrative('products_index'));

        $company   = Catalog::company();
        $isEn      = LocaleContext::current() === 'en';
        $brandName = $isEn
            ? ($company['name'] ?? '')
            : (! empty($company['brand']) ? $company['brand'] : ($company['name'] ?? ''));
        $lineNames = implode($isEn ? ', ' : '、', array_map(fn ($l) => $l['name'], $lines));

        // TD-09：进入 JSON-LD / canonical 的绝对地址统一由 PublicUrl 裁决（与
        // GeoGraph / Sitemap / canonical 同源，多站 / CLI 不出现 localhost 串站）。
        $productsIndexUrl = PublicUrl::url('products/');
        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.products'), 'url' => $productsIndexUrl],
        ];

        // ItemList：产品系列（与可见分组一致）
        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            '@id'             => $productsIndexUrl . '#itemlist',
            'name'            => __('seo.products_itemlist_name', ['brand' => $brandName]),
            'itemListElement' => array_values(array_map(function ($line, $i) use ($productsIndexUrl) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $line['name'],
                    'url'      => $productsIndexUrl . '#' . $line['slug'],
                ];
            }, $lines, array_keys($lines))),
        ];

        if ($flatMode) {
            $itemList['itemListElement'] = array_values(array_map(function ($p, $i) use ($productsIndexUrl) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $p['name'],
                    'url'      => Catalog::isCoreProduct($p['slug']) ? PublicUrl::product($p['slug']) : $productsIndexUrl,
                ];
            }, $lines[0]['products'], array_keys($lines[0]['products'])));
        }

        return view('site.products.index', [
            'flatMode' => $flatMode,
            'lines'   => $lines,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'subnav'  => $this->subnav(),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $schema->webPage($productsIndexUrl, __('seo.products_index_title'), __('seo.products_index_desc', ['brand' => $brandName, 'lines' => $lineNames]), 'CollectionPage', $productsIndexUrl . '#itemlist'),
                $itemList,
            ])),
            'seo' => [
                'title'       => __('seo.products_index_title'),
                'description' => $flatMode
                    ? (trim($brandName) !== ''
                        ? __('seo.products_index_flat_with_brand', ['brand' => $brandName])
                        : __('seo.products_index_flat'))
                    : __('seo.products_index_desc', ['brand' => $brandName, 'lines' => $lineNames]),
                'canonical'   => $productsIndexUrl,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    /**
     * 产品中心统一入口（路由中性化，P-STEP 14 / D.2）：/products/{param}
     *
     * 按「当前站点」Catalog 判定 param 是产品系列还是核心产品，否则 404。
     * 不再依赖全局 Facts 在路由加载期生成的 slug 白名单（那会让空站 / 其他站
     * 也能命中本站不存在的产品 URL）。数据全部来自站点隔离的 Catalog。
     */
    public function resolve(string $param, SchemaBuilder $schema)
    {
        if (Catalog::line($param) !== null) {
            return $this->line($param, $schema);
        }
        if (Catalog::isCoreProduct($param)) {
            return $this->show($param);
        }
        abort(404);
    }

    public function line(string $line, SchemaBuilder $schema)
    {
        $data = Catalog::line($line);
        abort_if(! $data, 404);
        // 系列导语可运营：独立系列页页头与 SEO 描述、总览分组说明共用同一覆盖
        $data['desc'] = Narrative::lead('products.line.' . $line, $data['desc'] ?? '');
        $products = Catalog::productsByLine($line);

        $productsIndexUrl = PublicUrl::url('products/');
        $lineUrl = PublicUrl::productLine($line);

        // 该系列没有产品则不建独立分类页，回到总览对应锚点（规范硬规则，数据驱动）
        if (count($products) < 1) {
            return redirect($productsIndexUrl . '#' . $line, 301);
        }

        $crumbs = [
            ['name' => __('nav.home'), 'url' => PublicUrl::home()],
            ['name' => __('nav.products'), 'url' => $productsIndexUrl],
            ['name' => $data['name'], 'url' => $lineUrl],
        ];

        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            '@id'             => $lineUrl . '#itemlist',
            'name'            => $data['name'],
            'itemListElement' => array_values(array_map(function ($p, $i) use ($productsIndexUrl) {
                $u = Catalog::isCoreProduct($p['slug'])
                    ? PublicUrl::product($p['slug'])
                    : $productsIndexUrl . '#' . $p['line'];
                return ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['name'], 'url' => $u];
            }, $products, array_keys($products))),
        ];

        return view('site.products.line', [
            'line'     => $data,
            'products' => $products,
            'crumbs'   => array_slice($crumbs, 1),
            'subnav'   => $this->subnav($line),
            'schemas'  => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $schema->webPage($lineUrl, $data['name'], $data['desc'] ?? '', 'CollectionPage', $lineUrl . '#itemlist'),
                $itemList,
            ])),
            'seo' => [
                'title'       => __('seo.product_line_title', ['name' => $data['name']]),
                'description' => $data['desc'] ?? '',
                'canonical'   => $lineUrl,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    public function show(string $slug)
    {
        // P-STEP 18G-2a：详情统一 Render Context 管线。Entity 取当前语言行，Catalog
        // 按当前 locale 投影；非 core / 不存在由 context 与 abort 双重 404。
        $entity = Entity::published()
            ->forLocale(LocaleContext::current())
            ->ofType(Entity::TYPE_PRODUCT)
            ->where('slug', $slug)
            ->first();
        abort_if($entity === null, 404);

        $context = EntityRenderContext::forEntity($entity);
        abort_if($context === null, 404);

        return app(CompositionRenderer::class)->render($context);
    }

    /**
     * 产品体系 SubNav（产品类页面统一）。
     * 与顶部导航下拉同源：固定产品系列 + 后台挂接到「产品中心」的自定义二级项，
     * 按导航统一排序输出；在导航中被隐藏的体系同样不出现在 Tab 条。
     */
    private function subnav(?string $active = null): array
    {
        $cur = trim(request()->path(), '/');
        // 自定义外链 / 站内链接 Tab 的当前态：路径精确或子路径命中
        $customOn = function (string $url, bool $external) use ($cur) {
            if ($external) {
                return false;
            }
            $p = trim((string) parse_url($url, PHP_URL_PATH), '/');
            return $p !== '' && ($cur === $p || str_starts_with($cur, $p . '/'));
        };

        // 固定体系按规范化 URL 映射回 slug（供详情 / 系列页高亮）
        $slugByUrl = [];
        foreach (Catalog::productLines() as $l) {
            $slugByUrl[trim((string) url('/products/' . $l['slug'] . '/'), '/')] = $l['slug'];
        }

        $items = [[
            'name' => __('nav.all_products'), 'slug' => null, 'url' => url('/products/'),
            'on'   => $cur === 'products',
        ]];
        foreach (AppServiceProvider::mergedMenuChildren('products') as $ch) {
            $norm = trim((string) $ch['url'], '/');
            $slug = $slugByUrl[$norm] ?? null;
            $items[] = [
                'name'     => $ch['name'],
                'slug'     => $slug,
                'url'      => $ch['url'],
                'external' => (bool) ($ch['external'] ?? false),
                'on'       => $slug !== null ? $slug === $active : $customOn($ch['url'], (bool) ($ch['external'] ?? false)),
            ];
        }

        return ['items' => $items, 'active' => $active];
    }
}
