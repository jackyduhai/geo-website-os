<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use App\Services\Geo\SchemaBuilder;
use App\Support\Catalog;
use App\Support\Narrative;

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

        $lead = Narrative::lead('products.index.lead', config('pages.narrative.products_index.lead', ''));

        $company   = Catalog::company();
        $brandName = ! empty($company['brand']) ? $company['brand'] : ($company['name'] ?? '');
        $lineNames = implode('、', array_map(fn ($l) => $l['name'], $lines));

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '产品中心', 'url' => url('/products/')],
        ];

        // ItemList：产品系列（与可见分组一致）
        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $brandName . '产品体系',
            'itemListElement' => array_values(array_map(function ($line, $i) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $line['name'],
                    'url'      => url('/products/#' . $line['slug']),
                ];
            }, $lines, array_keys($lines))),
        ];

        return view('site.products.index', [
            'lines'   => $lines,
            'lead'    => $lead,
            'crumbs'  => array_slice($crumbs, 1),
            'subnav'  => $this->subnav(),
            'schemas' => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
                $itemList,
            ])),
            'seo' => [
                'title'       => '产品中心',
                'description' => $brandName . '产品体系涵盖' . $lineNames . '，附配比与施工工艺参数，支持配方定制研发与 OEM / ODM 代工。',
                'canonical'   => url('/products/'),
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
            return $this->show($param, $schema);
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

        // 该系列没有产品则不建独立分类页，回到总览对应锚点（规范硬规则，数据驱动）
        if (count($products) < 1) {
            return redirect(url('/products/#' . $line), 301);
        }

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '产品中心', 'url' => url('/products/')],
            ['name' => $data['name'], 'url' => url('/products/' . $line . '/')],
        ];

        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $data['name'],
            'itemListElement' => array_values(array_map(function ($p, $i) {
                $url = Catalog::isCoreProduct($p['slug'])
                    ? url('/products/' . $p['slug'])
                    : url('/products/#' . $p['line']);
                return ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['name'], 'url' => $url];
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
                $itemList,
            ])),
            'seo' => [
                'title'       => $data['name'] . '产品系列与配比参数',
                'description' => $data['desc'] ?? '',
                'canonical'   => url('/products/' . $line . '/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    public function show(string $slug, SchemaBuilder $schema)
    {
        $product = Catalog::product($slug);
        abort_if(! $product || ! Catalog::isCoreProduct($slug), 404);
        // 产品导语可运营：页头、SEO 描述、Product 结构化数据三处共用同一覆盖
        $product['tagline'] = Narrative::lead('products.detail.' . $slug, $product['tagline'] ?? '');

        $line     = Catalog::line($product['line']);
        $related  = Catalog::relatedProducts($product, 5);
        $scenes   = Catalog::scenesOfProduct($product);
        $faqs     = config('pages.product_faqs.' . $slug, []);

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '产品中心', 'url' => url('/products/')],
            ['name' => $line['name'] ?? '产品', 'url' => url('/products/' . $product['line'] . '/')],
            ['name' => $product['name'], 'url' => url('/products/' . $slug)],
        ];

        $schemas = [
            $schema->organization(),
            $schema->breadcrumb($crumbs),
            $this->productSchema($product, $line),
        ];
        // HowTo：有分步 params 时输出
        if (! empty($product['params'])) {
            $schemas[] = $this->howTo($product);
        }
        if (! empty($faqs)) {
            $schemas[] = $schema->faqPageFromList($faqs, url('/products/' . $slug));
        }

        return view('site.products.show', [
            'product' => $product,
            'line'    => $line,
            'related' => $related,
            'scenes'  => $scenes,
            'faqs'    => $faqs,
            'crumbs'  => array_slice($crumbs, 1),
            'subnav'  => $this->subnav($product['line']),
            'schemas' => array_values(array_filter($schemas)),
            'seo' => [
                'title'       => $product['name'] . '配比用量与工艺参数',
                'description' => $product['tagline'],
                'canonical'   => url('/products/' . $slug),
                'noindex'     => false,
                'type'        => 'product',
            ],
        ]);
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
            'name' => '全部产品', 'slug' => null, 'url' => url('/products/'),
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

    private function productSchema(array $product, ?array $line): array
    {
        $company   = Catalog::company();
        $brandName = ! empty($company['brand']) ? $company['brand'] : ($company['name'] ?? '');

        $props = [];
        foreach (($product['key_params'] ?? []) as $kp) {
            $props[] = ['@type' => 'PropertyValue', 'name' => $kp['label'], 'value' => $kp['value']];
        }
        // 禁 offers / aggregateRating / review（无真实交易与评价数据）
        return array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            '@id'         => url('/products/' . $product['slug']) . '#product',
            'name'        => $product['name'],
            'description' => $product['tagline'],
            'category'    => $line['name'] ?? null,
            'brand'       => $brandName !== '' ? ['@type' => 'Brand', 'name' => $brandName] : null,
            'manufacturer' => ['@id' => rtrim(config('app.url'), '/') . '/#organization'],
            'additionalProperty' => $props,
        ]);
    }

    private function howTo(array $product): array
    {
        $steps = [];
        foreach (($product['params'] ?? []) as $i => $p) {
            $text = $p['value'];
            if (! empty($p['note'])) {
                $text .= '（' . $p['note'] . '）';
            }
            $steps[] = [
                '@type'           => 'HowToStep',
                'position'        => $i + 1,
                'name'            => $p['step'],
                'text'            => $text,
            ];
        }
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            '@id'      => url('/products/' . $product['slug']) . '#howto',
            'name'     => $product['name'] . '使用工艺',
            'step'     => $steps,
        ];
    }
}
