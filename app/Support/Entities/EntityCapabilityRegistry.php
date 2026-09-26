<?php

namespace App\Support\Entities;

/**
 * Entity Capability Registry（P-STEP 18R-2a）——实体类型能力的唯一事实源。
 *
 * 消费方（SchemaBuilder / GeoGraphBuilder / PublicUrl / EntityRenderContext /
 * SearchIndexBuilder / SitemapBuilder / Admin EntityController）一律从这里读取
 * 某类型的能力声明，禁止再散点硬编码 `if ($type === 'product')` 能力判断。
 *
 * 数据源：config/entities.php（惰性加载，进程内静态缓存；测试用 flush() 重载）。
 *
 * 未知类型一律按「最弱能力」处理：无 schema / 无公开页 / 不可搜索 / 不进 sitemap，
 * 但 geo=true 的类型仍作为图谱节点保留（download_asset 即此模式）。
 */
class EntityCapabilityRegistry
{
    /** @var array<string,array>|null 惰性加载的类型配置缓存 */
    private static ?array $cache = null;

    /** 全部类型配置（type key => capability array）。 */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = config('entities.types', []);
        }

        return self::$cache;
    }

    /** 全部已注册 type key 列表。 */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    /** 类型是否已注册。 */
    public static function has(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    /**
     * 取某类型完整配置；未注册返回 null。
     *
     * @return array{label:string,schema:?string,public:bool,searchable:bool,geo:bool,sitemap:bool,relations:array,metadata:array}|null
     */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /** 后台展示名；未注册回退返回 type 原文。 */
    public static function label(string $type): string
    {
        return (string) (self::all()[$type]['label'] ?? $type);
    }

    /** [type => label]，供 Admin tab / 下拉。 */
    public static function labels(): array
    {
        return array_map(
            fn (array $c): string => (string) $c['label'],
            self::all()
        );
    }

    /** schema.org @type；不产出 JSON-LD 的类型（download_asset）返回 null。 */
    public static function schemaType(string $type): ?string
    {
        return self::all()[$type]['schema'] ?? null;
    }

    /** 是否拥有独立前台落地页（PublicUrl / EntityRenderContext 据此裁决）。 */
    public static function isPublic(string $type): bool
    {
        return (bool) (self::all()[$type]['public'] ?? false);
    }

    /** 是否进入站内搜索索引。 */
    public static function isSearchable(string $type): bool
    {
        return (bool) (self::all()[$type]['searchable'] ?? false);
    }

    /** 是否进入 /geo.json 知识图谱节点。 */
    public static function isGeo(string $type): bool
    {
        return (bool) (self::all()[$type]['geo'] ?? false);
    }

    /** 是否进入 sitemap.xml 收录。 */
    public static function isSitemap(string $type): bool
    {
        return (bool) (self::all()[$type]['sitemap'] ?? false);
    }

    /** 该类型 metadata JSON 允许承载的业务键白名单。 */
    public static function metadataKeys(string $type): array
    {
        return array_values(self::all()[$type]['metadata'] ?? []);
    }

    /** 该类型允许关联的目标类型声明（2a 仅声明，不强制校验）。 */
    public static function relations(string $type): array
    {
        return array_values(self::all()[$type]['relations'] ?? []);
    }

    /** 清空进程内缓存（测试 / config 重载后调用）。 */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
