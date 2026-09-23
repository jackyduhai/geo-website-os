<?php

namespace App\Support;

/**
 * 事实源统一访问层
 * ------------------------------------------------------------------
 * 全站结构化事实（公司 / 产品体系 / 产品 / 场景 / 生产 / 合作 / 案例 / 合规）
 * 只从 config('facts') 读取，该配置由 scripts/compile_facts.php 从交付包
 * facts.yaml 编译生成。页面、Schema、feeds 都经本类取数，禁止各处自行硬编码。
 *
 * 约定：
 *   - 产品、体系、场景一律以 slug 为键（URL 也用 slug）
 *   - 空值（null / 空数组）表示「待补」，模板侧整块隐藏，不输出占位符
 *
 * 过渡层说明（legacy）：
 *   本类读取的 config('facts') 是文件型事实源，仅用于当前前台 / Schema / feeds 的
 *   兼容渲染。权威领域数据已迁移到 Entity / EntityRelation / Content（见 5.5）。
 *   后续前台展示层切换到 Entity / Content 数据源后，本类与 config('facts') 一并退场。
 *   新代码不应依赖本类，应使用 EntityRepository 与 Content 模型。
 */
class Facts
{
    /**
     * 核心产品 slug 列表（拥有独立详情页，进入路由白名单 / sitemap / llms）。
     *
     * 由产品数据中的 core 标志驱动（见 config/facts.php 每个产品的 core 字段），
     * 不在代码中写死任何具体产品 slug，以便不同站点替换事实数据后自动生效。
     */
    public static function coreProductSlugs(): array
    {
        return array_values(array_map(
            fn ($p) => $p['slug'],
            array_filter(self::products(), fn ($p) => ! empty($p['core']))
        ));
    }

    private static ?array $productBySlug = null;
    private static ?array $sceneBySlug = null;

    // ---------------------------------------------------------------
    // 公司 / 品牌
    // ---------------------------------------------------------------

    public static function company(): array
    {
        return config('facts.company', []);
    }

    public static function brandLanguage(): array
    {
        return config('facts.brand_language', []);
    }

    // ---------------------------------------------------------------
    // 产品体系
    // ---------------------------------------------------------------

    /** @return array<int,array> */
    public static function productLines(): array
    {
        $lines = config('facts.product_lines', []);
        usort($lines, static fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
        return $lines;
    }

    public static function line(?string $slug): ?array
    {
        foreach (config('facts.product_lines', []) as $line) {
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
        if (self::$productBySlug === null) {
            $map = [];
            foreach (config('facts.products', []) as $p) {
                $map[$p['slug']] = $p;
            }
            self::$productBySlug = $map;
        }
        return self::$productBySlug;
    }

    /** @return array<int,array> */
    public static function products(): array
    {
        return array_values(self::productMap());
    }

    public static function product(?string $slug): ?array
    {
        return self::productMap()[$slug] ?? null;
    }

    public static function isCoreProduct(?string $slug): bool
    {
        if (! $slug) {
            return false;
        }
        $product = self::product($slug);

        return $product !== null && ! empty($product['core']);
    }

    /** 某体系下全部产品（保持 facts 中的出现顺序） */
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
        if (self::$sceneBySlug === null) {
            $map = [];
            foreach (config('facts.scenes', []) as $s) {
                $map[$s['slug']] = $s;
            }
            self::$sceneBySlug = $map;
        }
        return self::$sceneBySlug;
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
        return self::sceneMap()[$slug] ?? null;
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

    /** 相邻场景（2 个） */
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
        return config('facts.production.workshops', []);
    }

    public static function salesRegions(): array
    {
        return config('facts.production.sales_regions', []);
    }

    public static function salesRegionsEnglish(): array
    {
        return config('facts.production.sales_regions_en', []);
    }

    public static function certifications(): array
    {
        return config('facts.production.certifications', []);
    }

    /** 资质硬阻塞：SC 与执行标准号任一缺失，资质与标准区块整体隐藏 */
    public static function certificationsReady(): bool
    {
        $c = self::certifications();
        return ! empty($c['sc_license']) && ! empty($c['standard_code']);
    }

    // ---------------------------------------------------------------
    // 合作 / 案例
    // ---------------------------------------------------------------

    public static function cooperation(): array
    {
        return config('facts.cooperation', []);
    }

    public static function cases(): array
    {
        return config('facts.cases', []);
    }

    // ---------------------------------------------------------------
    // 合规
    // ---------------------------------------------------------------

    public static function compliance(): array
    {
        return config('facts.compliance', []);
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
