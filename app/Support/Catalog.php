<?php

namespace App\Support;

use App\Models\Entity;

/**
 * 站点隔离的目录（Catalog）读模型 —— Runtime 唯一的产品 / 场景 / 公司目录数据源。
 * ------------------------------------------------------------------
 * P-STEP 14 / D.2：旧的 {@see Facts} 直接读取全局文件 config('facts')，不经过
 * SiteScope，导致任意站点（含空站）都能渲染同一份全局产品 / 场景 / Schema / Sitemap。
 *
 * Catalog 把同一套读取契约改为按当前 {@see SiteContext} 从正式领域模型 Entity /
 * EntityRelation 投影：数据由 CatalogSeeder（或后台 / API）写入各站点自己的
 * organization / product / service Entity，查询经 BelongsToSite 的 SiteScope 自动
 * 加 where site_id，天然多站隔离；没有目录 Entity 的空站，所有方法返回空，业务页
 * 404、Sitemap / llms 不输出该站不存在的目录。
 *
 * 方法签名与返回结构与 Facts 保持一致（产品 / 体系 / 场景仍以 slug 为键），
 * 以便前台 / Sitemap / llms 消费者零结构改动切换数据源。
 *
 * 进程内按站点 memo，随 RequestScopedState::flushAll()（含 SiteContext::withSite
 * 进出、ResolveSite、队列恢复）复位，禁止跨请求 / 跨站残留。
 */
class Catalog
{
    /** @var array<int,array> siteId => 与 config('facts') 同构的数据集 */
    private static array $datasetsBySite = [];

    /** @var array<int,array<string,array>> siteId => slug => product */
    private static array $productMapBySite = [];

    /** @var array<int,array<string,array>> siteId => slug => scene */
    private static array $sceneMapBySite = [];

    /** 清空全部站点的进程内 memo（请求 / 进程 / 嵌套切站复位时调用）。 */
    public static function flush(): void
    {
        self::$datasetsBySite = [];
        self::$productMapBySite = [];
        self::$sceneMapBySite = [];
    }

    private static function siteId(): int
    {
        return (int) SiteContext::currentSiteId();
    }

    /**
     * 当前站点的目录数据集（与 config('facts') 同构）。
     * 无 organization Entity（空站）时返回 []，所有派生方法随之返回空。
     */
    private static function dataset(): array
    {
        $siteId = self::siteId();
        if (! array_key_exists($siteId, self::$datasetsBySite)) {
            self::$datasetsBySite[$siteId] = self::buildDataset();
        }
        return self::$datasetsBySite[$siteId];
    }

    private static function buildDataset(): array
    {
        // 查询经 BelongsToSite::SiteScope 自动限定当前站点。
        $organization = Entity::published()
            ->ofType(Entity::TYPE_ORGANIZATION)
            ->orderBy('sort_order')
            ->first();

        if (! $organization) {
            return [];
        }

        $meta = $organization->metadata ?? [];

        $products = Entity::published()
            ->ofType(Entity::TYPE_PRODUCT)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Entity $e) => array_merge(
                is_array($e->metadata) ? $e->metadata : [],
                ['slug' => $e->slug, 'name' => $e->name]
            ))
            ->values()
            ->all();

        $scenes = Entity::published()
            ->ofType(Entity::TYPE_SERVICE)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Entity $e) => array_merge(
                is_array($e->metadata) ? $e->metadata : [],
                ['slug' => $e->slug, 'name' => $e->name]
            ))
            ->values()
            ->all();

        return [
            'company'       => $meta['company'] ?? [],
            'brand_language' => $meta['brand_language'] ?? [],
            'product_lines' => $meta['product_lines'] ?? [],
            'products'      => $products,
            'scenes'        => $scenes,
            'production'    => $meta['production'] ?? [],
            'cooperation'   => $meta['cooperation'] ?? [],
            'cases'         => $meta['cases'] ?? [],
            'compliance'    => $meta['compliance'] ?? [],
        ];
    }

    /** 核心产品 slug 列表（拥有独立详情页 / 进入 sitemap / llms），由 core 标志驱动。 */
    public static function coreProductSlugs(): array
    {
        return array_values(array_map(
            fn ($p) => $p['slug'],
            array_filter(self::products(), fn ($p) => ! empty($p['core']))
        ));
    }

    // ---------------------------------------------------------------
    // 公司 / 品牌
    // ---------------------------------------------------------------

    public static function company(): array
    {
        return self::dataset()['company'] ?? [];
    }

    public static function brandLanguage(): array
    {
        return self::dataset()['brand_language'] ?? [];
    }

    // ---------------------------------------------------------------
    // 产品体系
    // ---------------------------------------------------------------

    /** @return array<int,array> */
    public static function productLines(): array
    {
        $lines = self::dataset()['product_lines'] ?? [];
        usort($lines, static fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
        return $lines;
    }

    public static function line(?string $slug): ?array
    {
        foreach ((self::dataset()['product_lines'] ?? []) as $line) {
            if (($line['slug'] ?? null) === $slug) {
                return $line;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------
    // 产品
    // ---------------------------------------------------------------

    /** @return array<string,array> slug => product */
    public static function productMap(): array
    {
        $siteId = self::siteId();
        if (! array_key_exists($siteId, self::$productMapBySite)) {
            $map = [];
            foreach ((self::dataset()['products'] ?? []) as $p) {
                $map[$p['slug']] = $p;
            }
            self::$productMapBySite[$siteId] = $map;
        }
        return self::$productMapBySite[$siteId];
    }

    /** @return array<int,array> */
    public static function products(): array
    {
        return array_values(self::productMap());
    }

    public static function product(?string $slug): ?array
    {
        return $slug !== null ? (self::productMap()[$slug] ?? null) : null;
    }

    public static function isCoreProduct(?string $slug): bool
    {
        if (! $slug) {
            return false;
        }
        $product = self::product($slug);

        return $product !== null && ! empty($product['core']);
    }

    /** 某体系下全部产品（保持目录顺序） */
    public static function productsByLine(string $lineSlug): array
    {
        return array_values(array_filter(
            self::products(),
            static fn ($p) => ($p['line'] ?? null) === $lineSlug
        ));
    }

    /** 解析产品的相关产品（slug → 实体），只返回存在的，最多 5 个 */
    public static function relatedProducts(array $product, int $limit = 5): array
    {
        $out = [];
        foreach (($product['related'] ?? []) as $slug) {
            if ($p = self::product($slug)) {
                $out[] = $p;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** 反查：产品适用的场景实体列表 */
    public static function scenesOfProduct(array $product): array
    {
        $out = [];
        foreach (($product['scenes'] ?? []) as $slug) {
            if ($s = self::scene($slug)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // 场景
    // ---------------------------------------------------------------

    /** @return array<string,array> slug => scene */
    public static function sceneMap(): array
    {
        $siteId = self::siteId();
        if (! array_key_exists($siteId, self::$sceneMapBySite)) {
            $map = [];
            foreach ((self::dataset()['scenes'] ?? []) as $s) {
                $map[$s['slug']] = $s;
            }
            self::$sceneMapBySite[$siteId] = $map;
        }
        return self::$sceneMapBySite[$siteId];
    }

    /** 场景按 order 排序 */
    public static function scenes(): array
    {
        $scenes = array_values(self::sceneMap());
        usort($scenes, static fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
        return $scenes;
    }

    public static function scene(?string $slug): ?array
    {
        return $slug !== null ? (self::sceneMap()[$slug] ?? null) : null;
    }

    /** 场景组合：slug 列表 → 产品实体（保留组合顺序） */
    public static function sceneCombo(array $scene): array
    {
        $out = [];
        foreach (($scene['combo'] ?? []) as $slug) {
            if ($p = self::product($slug)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** 相邻场景 */
    public static function adjacentScenes(array $scene): array
    {
        $out = [];
        foreach (($scene['adjacent'] ?? []) as $slug) {
            if ($s = self::scene($slug)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // 生产 / 资质
    // ---------------------------------------------------------------

    public static function workshops(): array
    {
        return self::dataset()['production']['workshops'] ?? [];
    }

    public static function salesRegions(): array
    {
        return self::dataset()['production']['sales_regions'] ?? [];
    }

    public static function certifications(): array
    {
        return self::dataset()['production']['certifications'] ?? [];
    }

    /** 资质硬阻塞：SC 与执行标准号任一缺失，资质与标准区块整体隐藏 */
    public static function certificationsReady(): bool
    {
        $c = self::certifications();
        return ! empty($c['sc_license']) && ! empty($c['standard_code']);
    }

    // ---------------------------------------------------------------
    // 合作 / 案例 / 合规
    // ---------------------------------------------------------------

    public static function cooperation(): array
    {
        return self::dataset()['cooperation'] ?? [];
    }

    public static function cases(): array
    {
        return self::dataset()['cases'] ?? [];
    }

    public static function compliance(): array
    {
        return self::dataset()['compliance'] ?? [];
    }

    /** @return string[] */
    public static function bannedTerms(): array
    {
        return self::compliance()['banned_terms'] ?? [];
    }

    /** @return string[] */
    public static function bannedComparisons(): array
    {
        return self::compliance()['banned_comparisons'] ?? [];
    }
}
