<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use App\Services\Geo\SchemaBuilder;
use App\Support\Facts;
use App\Support\Narrative;

/**
 * 产品中心（config/facts 单一事实源驱动）
 *   index  产品总览：五大体系分组 + 锚点
 *   line   单体系页：仅当该体系产品数 ≥ 4 才独立成页，否则 301 到总览锚点
 *   show   核心产品详情（仅 6 个有完整参数的核心产品，八区块）
 */
class ProductController extends Controller
{
    public function index(SchemaBuilder $schema)
    {
        $lines = collect(Facts::productLines())->map(function ($line) {
            $line['products'] = Facts::productsByLine($line['slug']);
            // 系列导语可运营（总览分组说明与独立系列页共用同一覆盖）
            $line['desc'] = Narrative::lead('products.line.' . $line['slug'], $line['desc'] ?? '');
            return $line;
        })->all();

        $lead = Narrative::lead('products.index.lead', config('pages.narrative.products_index.lead', ''));

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '产品中心', 'url' => url('/products/')],
        ];

        // ItemList：五体系（与可见分组一致）
        $itemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => 'Example五大产品体系',
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
                'title'       => 'Sample SnackSample Marinade、Sample Breading撒料、调理鸡肉产品中心',
                'description' => 'Example五大产品体系：Sample SnackSample Marinade、鸡肉半成品、调味香精、Sample SnackSample Breading、Sample Spice，覆盖中式、韩式、西式、特色风味，附真实配比与工艺参数，支持 OEM/ODM 代工。',
                'canonical'   => url('/products/'),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }

    public function line(string $line, SchemaBuilder $schema)
    {
        $data = Facts::line($line);
        abort_if(! $data, 404);
        // 系列导语可运营：独立系列页页头与 SEO 描述、总览分组说明共用同一覆盖
        $data['desc'] = Narrative::lead('products.line.' . $line, $data['desc'] ?? '');
        $products = Facts::productsByLine($line);

        // 不足 4 个产品不建独立分类页，回到总览对应锚点（规范硬规则，数据驱动）
        if (count($products) < 4) {
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
                $url = Facts::isCoreProduct($p['slug'])
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
        $product = Facts::product($slug);
        abort_if(! $product || ! Facts::isCoreProduct($slug), 404);
        // 产品导语可运营：页头、SEO 描述、Product 结构化数据三处共用同一覆盖
        $product['tagline'] = Narrative::lead('products.detail.' . $slug, $product['tagline'] ?? '');

        $line     = Facts::line($product['line']);
        $related  = Facts::relatedProducts($product, 5);
        $scenes   = Facts::scenesOfProduct($product);
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
     * 与顶部导航下拉同源：固定五大体系 + 后台挂接到「产品中心」的自定义二级项，
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
        foreach (Facts::productLines() as $l) {
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
            'brand'       => ['@type' => 'Brand', 'name' => 'Example'],
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
