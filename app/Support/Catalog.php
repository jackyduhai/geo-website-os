<?php

namespace App\Support;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use Illuminate\Support\Facades\Schema;

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

    private static function locale(): string
    {
        return LocaleContext::current() ?: LocaleRegistry::default();
    }

    /** memo 键：站点 + 语言（同一站点不同语言数据集不同）。 */
    private static function memoKey(): string
    {
        return self::siteId().':'.self::locale();
    }

    /**
     * 当前站点 + 语言的目录数据集（与 config('facts') 同构）。
     * 无 organization Entity（空站 / 当前语言无译行）时返回 []，所有派生方法随之返回空。
     */
    private static function dataset(): array
    {
        $key = self::memoKey();
        if (! array_key_exists($key, self::$datasetsBySite)) {
            self::$datasetsBySite[$key] = self::buildDataset();
        }
        return self::$datasetsBySite[$key];
    }

    private static function buildDataset(): array
    {
        // TD-09 缺表安全降级：fresh install 极早期 / 异常库（entities 尚未迁移）时，
        // Catalog 一律返回空数据集（业务页 404、feed 不输出），绝不抛 SQL 白屏。
        if (! Schema::hasTable((new Entity())->getTable())) {
            return [];
        }

        $locale = self::locale();
        $defaultLocale = LocaleRegistry::default();

        // 18F：关系权威边的基础语言。默认系统语言 zh-CN；若站点无该语言实体
        // （en-only 站点）回退到站点默认语言（site_default_locale），关系边建立
        // 在该语言行，避免硬依赖 zh-CN 行导致 Catalog 崩溃。
        $baseLocale = $defaultLocale;
        $hasBaseOrg = Entity::published()->forLocale($baseLocale)
            ->ofType(Entity::TYPE_ORGANIZATION)->exists();
        if (! $hasBaseOrg) {
            $siteDefaultLocale = (string) \App\Models\Setting::get('site_default_locale', $defaultLocale);
            if ($siteDefaultLocale !== $baseLocale && Entity::published()->forLocale($siteDefaultLocale)
                ->ofType(Entity::TYPE_ORGANIZATION)->exists()) {
                $baseLocale = $siteDefaultLocale;
            }
        }

        // 查询经 BelongsToSite::SiteScope 自动限定当前站点；forLocale 限定语言行。
        $organization = Entity::published()
            ->forLocale($locale)
            ->ofType(Entity::TYPE_ORGANIZATION)
            ->orderBy('sort_order')
            ->first();

        if (! $organization) {
            return [];
        }

        // 产品 / 场景（应用服务）实体（当前语言行，用于展示）
        $productEntities = Entity::published()
            ->forLocale($locale)
            ->ofType(Entity::TYPE_PRODUCT)
            ->orderBy('sort_order')
            ->get();
        $sceneEntities = Entity::published()
            ->forLocale($locale)
            ->ofType(Entity::TYPE_SERVICE)
            ->orderBy('sort_order')
            ->get();

        // 关系权威边始终建在默认语言（zh-CN）行之间：当前语言非默认时额外取 zh 行
        // 匹配边，再按 translation_group 把关系映射回当前语言行。
        if ($locale === $baseLocale) {
            $zhOrg = $organization;
            $zhProductEntities = $productEntities;
            $zhSceneEntities = $sceneEntities;
        } else {
            $zhOrg = Entity::published()->forLocale($baseLocale)
                ->ofType(Entity::TYPE_ORGANIZATION)->first();
            $zhProductEntities = Entity::published()->forLocale($baseLocale)
                ->ofType(Entity::TYPE_PRODUCT)->orderBy('sort_order')->get();
            $zhSceneEntities = Entity::published()->forLocale($baseLocale)
                ->ofType(Entity::TYPE_SERVICE)->orderBy('sort_order')->get();
        }

        $meta = $organization->metadata ?? [];

        // P-STEP 18A / #114：实体间关系（适用场景 / 组合产品 / 相关产品 / 相邻场景 /
        // 关键参数产品）统一由权威边表 EntityRelation 单向派生；产品线 line /
        // product_lines 是组织 metadata 的配置分组（非实体关系），仍读 metadata。
        $relationMap = self::relationMap(
            $zhOrg, $zhProductEntities, $zhSceneEntities,
            $productEntities, $sceneEntities, $locale === $baseLocale
        );

        $products = $productEntities
            ->map(function (Entity $e) use ($relationMap) {
                $data = self::normalizeProduct(array_merge(
                    is_array($e->metadata) ? $e->metadata : [],
                    [
                        'slug'        => $e->slug,
                        'name'        => $e->name,
                        'summary'     => $e->summary,
                        'tagline'     => $e->summary,
                        'description' => $e->description,
                    ]
                ));
                // 关系字段以 EntityRelation 为唯一权威，覆盖 metadata 透传值。
                $data['scenes']  = $relationMap['product_scenes'][$data['slug']] ?? [];
                $data['related'] = $relationMap['product_related'][$data['slug']] ?? [];

                return $data;
            })
            ->values()
            ->all();

        $scenes = $sceneEntities
            ->map(function (Entity $e) use ($relationMap) {
                $data = self::normalizeScene(array_merge(
                    is_array($e->metadata) ? $e->metadata : [],
                    [
                        'slug'        => $e->slug,
                        'name'        => $e->name,
                        'summary'     => $e->summary,
                        'desc'        => $e->summary,
                        'description' => $e->description,
                    ]
                ));
                // 组合产品 / 相邻场景 / 关键参数产品均以 EntityRelation 为唯一权威。
                $data['combo']    = $relationMap['scene_combo'][$data['slug']] ?? [];
                $data['adjacent'] = $relationMap['scene_adjacent'][$data['slug']] ?? [];
                $keyParam         = $relationMap['scene_key_param'][$data['slug']] ?? null;
                $data['key_param_product'] = $keyParam['slug'] ?? null;
                if ($keyParam !== null) {
                    // 关键参数展示文案优先取关系 metadata，缺省回退场景自身文案。
                    $data['key_param_display'] = $keyParam['display'] !== ''
                        ? $keyParam['display']
                        : ($data['key_param_display'] ?? '');
                }

                return $data;
            })
            ->values()
            ->all();

        $productLines = array_map(
            fn ($l) => self::normalizeProductLine(is_array($l) ? $l : []),
            is_array($meta['product_lines'] ?? null) ? $meta['product_lines'] : []
        );

        return [
            'company'        => self::normalizeCompany(is_array($meta['company'] ?? null) ? $meta['company'] : [], $organization),
            'brand_language' => self::normalizeBrandLanguage(is_array($meta['brand_language'] ?? null) ? $meta['brand_language'] : []),
            'product_lines'  => $productLines,
            'products'       => $products,
            'scenes'         => $scenes,
            'production'     => self::normalizeProduction(is_array($meta['production'] ?? null) ? $meta['production'] : []),
            'cooperation'    => self::normalizeCooperation(is_array($meta['cooperation'] ?? null) ? $meta['cooperation'] : []),
            'cases'          => self::normalizeCases(is_array($meta['cases'] ?? null) ? $meta['cases'] : []),
            'compliance'     => is_array($meta['compliance'] ?? null) ? $meta['compliance'] : [],
        ];
    }

    /**
     * P-STEP 18A / #114 + 18F：从权威边表 EntityRelation 单向派生 Catalog 关系读模型。
     *
     * 关系权威边始终指向默认语言（zh-CN）行 id：$zh* 用于匹配边，$products/$scenes
     * 为当前语言行，按 translation_group 把关系映射为当前语言 slug 输出。任一端在当前
     * 语言无翻译行时该关系项省略（无译→不展示，不产生指向不存在页面的关系）。
     *
     * 方向性：uses 是有向边，不做对称推断；产品「适用场景」与场景「组合产品」是两条
     * 独立边，必须各自显式存在。组织 produces / offers 边不进入前台目录关系区块。
     */
    private static function relationMap(
        ?Entity $zhOrganization,
        $zhProductEntities,
        $zhSceneEntities,
        $productEntities,
        $sceneEntities,
        bool $isBaseLocale
    ): array {
        $map = [
            'product_scenes'  => [],
            'scene_combo'     => [],
            'product_related' => [],
            'scene_adjacent'  => [],
            'scene_key_param' => [],
        ];

        if (! Schema::hasTable((new EntityRelation())->getTable()) || ! $zhOrganization) {
            return $map;
        }

        // zh 行 id => [type, translation_group]
        $zhById = [];
        foreach ($zhProductEntities as $e) {
            $zhById[(int) $e->id] = [Entity::TYPE_PRODUCT, $e->translation_group];
        }
        foreach ($zhSceneEntities as $e) {
            $zhById[(int) $e->id] = [Entity::TYPE_SERVICE, $e->translation_group];
        }
        $zhById[(int) $zhOrganization->id] = [Entity::TYPE_ORGANIZATION, $zhOrganization->translation_group];

        // translation_group => 当前语言 slug
        $currentSlugByGroup = [
            Entity::TYPE_PRODUCT => [],
            Entity::TYPE_SERVICE => [],
        ];
        foreach ($productEntities as $e) {
            $currentSlugByGroup[Entity::TYPE_PRODUCT][$e->translation_group] = $e->slug;
        }
        foreach ($sceneEntities as $e) {
            $currentSlugByGroup[Entity::TYPE_SERVICE][$e->translation_group] = $e->slug;
        }

        $relations = EntityRelation::query()
            ->where('site_id', $zhOrganization->site_id)
            ->orderBy('sort_order')
            ->get();

        foreach ($relations as $relation) {
            $from = $zhById[(int) $relation->from_entity_id] ?? null;
            $to   = $zhById[(int) $relation->to_entity_id] ?? null;
            if (! $from || ! $to) {
                continue; // 端未发布 / 非本站目录类型
            }

            [$fromType, $fromGroup] = $from;
            [$toType, $toGroup]     = $to;

            // 映射当前语言 slug；org 端或当前语言无译行 → 跳过该关系
            $fromSlug = $currentSlugByGroup[$fromType][$fromGroup] ?? null;
            $toSlug   = $currentSlugByGroup[$toType][$toGroup] ?? null;
            if ($fromSlug === null || $toSlug === null) {
                continue;
            }

            if ($relation->relation_type === EntityRelation::TYPE_USES) {
                if ($fromType === Entity::TYPE_PRODUCT && $toType === Entity::TYPE_SERVICE) {
                    $map['product_scenes'][$fromSlug][] = $toSlug;
                } elseif ($fromType === Entity::TYPE_SERVICE && $toType === Entity::TYPE_PRODUCT) {
                    $map['scene_combo'][$fromSlug][] = $toSlug;
                    $relMeta = is_array($relation->metadata) ? $relation->metadata : [];
                    if (($relMeta['role'] ?? null) === 'key_param') {
                        // 当前语言即关系写入(base)语言时沿用手填 display；跨语言不沿用，
                        // 置空让下游回退当前语言字段，避免把 base 语言文案泄漏到其他语言。
                        $display = $isBaseLocale ? trim((string) ($relMeta['display'] ?? '')) : '';
                        $map['scene_key_param'][$fromSlug] = ['slug' => $toSlug, 'display' => $display];
                    }
                }
            } elseif ($relation->relation_type === EntityRelation::TYPE_RELATED_TO) {
                if ($fromType === Entity::TYPE_PRODUCT && $toType === Entity::TYPE_PRODUCT) {
                    $map['product_related'][$fromSlug][] = $toSlug;
                } elseif ($fromType === Entity::TYPE_SERVICE && $toType === Entity::TYPE_SERVICE) {
                    $map['scene_adjacent'][$fromSlug][] = $toSlug;
                }
            }
        }

        return $map;
    }

    /**
     * 投影出口形状归一化（P-STEP 17G / Public Render Contract）。
     *
     * Catalog 是 Runtime 唯一目录读模型，但其原始数据来自后台可任意填写的
     * Entity.metadata：管理员新建「最小字段」实体（如只填公司名的 organization、
     * 只填名称的 service）时，metadata 缺键会被下游 Blade / Controller 原样裸访问，
     * 在 PHP 8 下以 `Undefined array key` ErrorException 直接白屏（HTTP 500）。
     *
     * 因此在「投影出口」单点补齐全集可选键的安全默认，保证下游永远读到形状稳定的
     * 数组，而不是给几十个视图逐个加 `??`。demo 数据字段齐全，归一化对其零影响。
     */
    private static function normalizeCompany(array $c, Entity $organization): array
    {
        $address = is_array($c['address'] ?? null) ? $c['address'] : [];
        $isEn = self::locale() !== LocaleRegistry::default();

        return array_replace([
            'name'                            => $organization->name,
            'name_en'                         => '',
            'summary'                         => '',
            'brand'                           => '',
            'brand_en'                        => '',
            'short_name'                      => '',
            'founded'                         => '',
            'founded_display'                 => '',
            'established_production'          => '',
            'established_production_display'  => '',
            'address'                         => [
                'full'     => '',
                'country'  => '',
                'province' => '',
                'city'     => '',
                'district' => '',
                'street'   => '',
                'lat'      => null,
                'lng'      => null,
            ],
            'area_sqm'                        => 0,
            'area_display'                    => '',
            'annual_capacity_tons'            => 0,
            'annual_capacity_display'         => '',
            'total_investment_wan'            => 0,
            'total_investment_display'        => '',
            'tech_experience_years'           => 0,
            'tech_experience_display'         => '',
            'phone'                           => '',
            'phone_tel'                       => '',
            'email'                           => '',
            'website'                         => '',
            'domain'                          => '',
            'industry'                        => '',
            'served_stores'                   => null,
            'served_stores_display'           => '',
            'business_model'                  => [],
            'target_customers'                => [],
        ], $c, [
            // address 必须整体补键，不能让 array_replace 用缺失的子数组裸透传
            'address' => array_replace([
                'full'     => '',
                'country'  => '',
                'province' => '',
                'city'     => '',
                'district' => '',
                'street'   => '',
                'lat'      => null,
                'lng'      => null,
            ], $address, $isEn && ! empty($c['address_en']) ? ['full' => $c['address_en']] : []),
            // 名称以当前语言实体为权威（翻译字段），不读共享 metadata 内的中文公司名
            'name' => $organization->name,
            // 当前语言主体摘要（en 为英文行业描述）
            'summary' => $organization->summary ?: $organization->description,
            'business_model' => $isEn
                ? (is_array($c['business_model_en'] ?? null) ? $c['business_model_en']
                    : (is_array($c['business_model'] ?? null) ? $c['business_model'] : []))
                : (is_array($c['business_model'] ?? null) ? $c['business_model'] : []),
            'target_customers' => $isEn
                ? (is_array($c['target_customers_en'] ?? null) ? $c['target_customers_en']
                    : (is_array($c['target_customers'] ?? null) ? $c['target_customers'] : []))
                : (is_array($c['target_customers'] ?? null) ? $c['target_customers'] : []),
        ], $isEn ? array_filter([
            'brand' => $c['brand_en'] ?? '',
            'industry' => $c['industry_en'] ?? '',
            'served_stores_display' => $c['served_stores_display_en'] ?? '',
            'founded_display' => $c['founded_display_en'] ?? '',
            'established_production_display' => $c['established_production_display_en'] ?? '',
            'area_display' => $c['area_display_en'] ?? '',
            'annual_capacity_display' => $c['annual_capacity_display_en'] ?? '',
            'total_investment_display' => $c['total_investment_display_en'] ?? '',
            'tech_experience_display' => $c['tech_experience_display_en'] ?? '',
        ], static fn ($v) => $v !== '') : []);
    }

    private static function normalizeProduct(array $p): array
    {
        $n = array_replace([
            'id'         => null,
            'short_name' => '',
            'line'       => null,
            'core'       => false,
            'tag'        => '',
            'tagline'    => '',
            'desc'       => '',
            'icon'       => null,
            'image'      => null,
            'mains'      => [],
            'key_params' => [],
            'params'     => [],
            'scenes'     => [],
            'related'    => [],
            'net_weight' => null,
            'packaging'  => null,
            'shelf_life' => null,
            'storage'    => null,
            'moq'        => null,
        ], $p, [
            'slug' => $p['slug'],
            'name' => $p['name'],
            'core' => (bool) ($p['core'] ?? false),
            'mains' => is_array($p['mains'] ?? null) ? $p['mains'] : [],
            'key_params' => is_array($p['key_params'] ?? null) ? $p['key_params'] : [],
            'params' => is_array($p['params'] ?? null) ? $p['params'] : [],
            'scenes' => is_array($p['scenes'] ?? null) ? $p['scenes'] : [],
            'related' => is_array($p['related'] ?? null) ? $p['related'] : [],
        ]);

        // 导语/描述归一化：后台最小字段实体（仅 summary、无 tagline/desc）时回退实体摘要，
        // 避免默认空串占位后下游 `?? summary` 回退失效，造成详情页导语与 SEO description 空白
        if (trim((string) ($n['tagline'] ?? '')) === '') {
            $n['tagline'] = (string) ($n['summary'] ?? '');
        }
        if (trim((string) ($n['desc'] ?? '')) === '') {
            $n['desc'] = (string) ($n['description'] ?? $n['summary'] ?? '');
        }

        return $n;
    }

    private static function normalizeScene(array $s): array
    {
        $isEn = self::locale() !== LocaleRegistry::default();

        $painPoints = is_array($s['pain_points'] ?? null) ? $s['pain_points'] : [];
        if ($isEn && is_array($s['pain_points_en'] ?? null)) {
            $painPoints = $s['pain_points_en'];
        }

        $n = array_replace([
            'id'                 => null,
            'title_q'            => '',
            'desc'               => '',
            'pain_points'        => [],
            'combo'              => [],
            'combo_reason'       => '',
            'key_param_product'  => null,
            'key_param_display'  => '',
            'hover_reveal'       => '',
            'adjacent'           => [],
            'param_note'         => [],
            'order'              => 99,
            'priority'           => 'P0',
        ], $s, [
            'slug' => $s['slug'],
            'name' => $s['name'],
            'pain_points' => $painPoints,
            'combo' => is_array($s['combo'] ?? null) ? $s['combo'] : [],
            'adjacent' => is_array($s['adjacent'] ?? null) ? $s['adjacent'] : [],
            'order' => (int) ($s['order'] ?? 99),
        ]);

        if ($isEn) {
            foreach (['title_q' => 'title_q_en', 'combo_reason' => 'combo_reason_en',
                      'key_param_display' => 'key_param_display_en', 'hover_reveal' => 'hover_reveal_en'] as $k => $enK) {
                if (! empty($n[$enK])) { $n[$k] = $n[$enK]; }
            }
            if (! empty($s['desc_en'])) { $n['desc'] = $s['desc_en']; }
        }

        // 场景描述同产品：缺省回退实体摘要，避免最小字段场景页 SEO 描述空白
        if (trim((string) ($n['desc'] ?? '')) === '') {
            $n['desc'] = (string) ($n['description'] ?? $n['summary'] ?? '');
        }

        return $n;
    }

    private static function normalizeCases(array $cases): array
    {
        if (self::locale() === LocaleRegistry::default()) { return $cases; }
        return array_map(static function ($case) {
            if (! is_array($case)) { return $case; }
            return array_replace($case, array_filter([
                'title' => $case['title_en'] ?? '',
                'region_label' => $case['region_label_en'] ?? '',
                'quote' => $case['quote_en'] ?? '',
            ], static fn ($v) => $v !== ''));
        }, $cases);
    }

    private static function normalizeBrandLanguage(array $bl): array
    {
        if (self::locale() === LocaleRegistry::default()) { return $bl; }
        return array_replace($bl, array_filter([
            'slogan' => $bl['slogan_en'] ?? '',
            'mission' => $bl['mission_en'] ?? '',
            'values' => $bl['values_en'] ?? '',
            'vision' => $bl['vision_en'] ?? '',
        ], static fn ($v) => $v !== ''));
    }

    private static function normalizeProductLine(array $l): array
    {
        $isEn = self::locale() !== LocaleRegistry::default();
        return array_replace([
            'id'       => null,
            'name'     => '',
            'name_en'  => '',
            'slug'     => '',
            'desc'     => '',
            'desc_en'  => '',
            'order'    => 99,
            'featured' => false,
        ], $l, [
            'name' => $isEn && ! empty($l['name_en']) ? $l['name_en'] : ($l['name'] ?? ''),
            'desc' => $isEn && ! empty($l['desc_en']) ? $l['desc_en'] : ($l['desc'] ?? ''),
            'order' => (int) ($l['order'] ?? 99),
            'featured' => (bool) ($l['featured'] ?? false),
        ]);
    }

    private static function normalizeProduction(array $p): array
    {
        $isEn = self::locale() !== LocaleRegistry::default();
        $workshops = array_map(static function ($w) use ($isEn) {
            $w = is_array($w) ? $w : ['name' => (string) $w];
            $n = array_replace([
                'id'           => null,
                'name'         => '',
                'name_en'      => '',
                'desc'         => '',
                'desc_en'      => '',
                'image'        => null,
                'image_alt'    => '',
                'image_alt_en' => '',
            ], $w);
            if ($isEn) {
                if ($n['name_en'] !== '') { $n['name'] = $n['name_en']; }
                if ($n['desc_en'] !== '') { $n['desc'] = $n['desc_en']; }
                if ($n['image_alt_en'] !== '') { $n['image_alt'] = $n['image_alt_en']; }
            }
            return $n;
        }, is_array($p['workshops'] ?? null) ? $p['workshops'] : []);

        $certs = is_array($p['certifications'] ?? null) ? $p['certifications'] : [];

        return [
            'workshops'     => $workshops,
            'sales_regions' => $isEn
                ? (is_array($p['sales_regions_en'] ?? null) ? $p['sales_regions_en']
                    : (is_array($p['sales_regions'] ?? null) ? $p['sales_regions'] : []))
                : (is_array($p['sales_regions'] ?? null) ? $p['sales_regions'] : []),
            'certifications' => [
                'sc_license'      => $certs['sc_license'] ?? null,
                'standard_code'   => $certs['standard_code'] ?? null,
                'business_license' => $certs['business_license'] ?? null,
                'icp'             => $certs['icp'] ?? null,
            ],
        ];
    }

    /**
     * 合作方式仅在确实存在合作类型 / 流程时才是可渲染页面；空数组保持空（empty 为真），
     * 与 CooperationController 的 404 判定、Sitemap / llms 收录准入严格对齐。
     */
    private static function normalizeCooperation(array $c): array
    {
        $isEn = self::locale() !== LocaleRegistry::default();

        $types = array_map(static function ($t) use ($isEn) {
            $t = is_array($t) ? $t : ['name' => (string) $t];
            return array_replace([
                'id'       => null,
                'name'     => '',
                'fit'      => '',
                'includes' => [],
                'cta'      => '',
            ], $t, [
                'name'     => $isEn && ! empty($t['name_en']) ? $t['name_en'] : ($t['name'] ?? ''),
                'fit'      => $isEn && ! empty($t['fit_en']) ? $t['fit_en'] : ($t['fit'] ?? ''),
                'cta'      => $isEn && ! empty($t['cta_en']) ? $t['cta_en'] : ($t['cta'] ?? ''),
                'includes' => $isEn && is_array($t['includes_en'] ?? null) && $t['includes_en'] !== []
                    ? $t['includes_en']
                    : (is_array($t['includes'] ?? null) ? $t['includes'] : []),
            ]);
        }, is_array($c['types'] ?? null) ? $c['types'] : []);

        $process = array_map(static function ($s) use ($isEn) {
            $s = is_array($s) ? $s : ['name' => (string) $s];
            return array_replace([
                'step' => null,
                'name' => '',
                'desc' => '',
            ], $s, [
                'name' => $isEn && ! empty($s['name_en']) ? $s['name_en'] : ($s['name'] ?? ''),
                'desc' => $isEn && ! empty($s['desc_en']) ? $s['desc_en'] : ($s['desc'] ?? ''),
            ]);
        }, is_array($c['process'] ?? null) ? $c['process'] : []);

        if ($types === [] && $process === []) {
            return [];
        }

        return [
            'types'            => $types,
            'process'          => $process,
            'moq'              => $c['moq'] ?? null,
            'sample_lead_time' => $c['sample_lead_time'] ?? null,
            'delivery_lead_time' => $c['delivery_lead_time'] ?? null,
        ];
    }

    /**
     * 工厂页是否有「生产实质」可渲染：至少有车间、厂区面积或年产能任一非空。
     * 只有公司名（最小 organization）时 /factory/ 必须 404，而不是渲染全 0 空壳或 500。
     */
    public static function hasProduction(): bool
    {
        $company = self::company();

        return ! empty(self::workshops())
            || (int) ($company['area_sqm'] ?? 0) > 0
            || (int) ($company['annual_capacity_tons'] ?? 0) > 0;
    }

    /** 合作方式页是否有实质内容（与 CooperationController 的 empty 判定一致）。 */
    public static function hasCooperation(): bool
    {
        return ! empty(self::cooperation());
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
        $key = self::memoKey();
        if (! array_key_exists($key, self::$productMapBySite)) {
            $map = [];
            foreach ((self::dataset()['products'] ?? []) as $p) {
                $map[$p['slug']] = $p;
            }
            self::$productMapBySite[$key] = $map;
        }
        return self::$productMapBySite[$key];
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
        $key = self::memoKey();
        if (! array_key_exists($key, self::$sceneMapBySite)) {
            $map = [];
            foreach ((self::dataset()['scenes'] ?? []) as $s) {
                $map[$s['slug']] = $s;
            }
            self::$sceneMapBySite[$key] = $map;
        }
        return self::$sceneMapBySite[$key];
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
