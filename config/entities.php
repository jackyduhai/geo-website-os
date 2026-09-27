<?php

/**
 * Entity Capability Registry（P-STEP 18R-2a）
 * ------------------------------------------------------------------
 * 实体类型能力的唯一事实源（Single Source of Truth）。
 *
 * 历史问题：Entity 类型 → schema.org 映射（SchemaBuilder::ENTITY_SCHEMA_TYPES）、
 * 后台白名单（EntityController::TYPES）、是否有前台落地页（PublicUrl）、是否进入
 * GEO / 搜索 / sitemap 等能力散落在多处硬编码数组里，新增一个类型要同时改 4~5 处。
 *
 * 本配置把每种 Entity 类型的「能力声明」收敛到一处：
 *
 *   - label       后台展示名（Admin tab / 下拉）
 *   - schema      产出 JSON-LD 的 schema.org @type；null 表示不产出结构化数据
 *                 （download_asset 是内部资料引用，不是 schema.org 实体）
 *   - public      是否拥有独立前台落地页（PublicUrl::entity / EntityRenderContext
 *                 据此裁决；false 仍是 GEO 图谱节点，但不输出会 404 的 url）
 *   - searchable  是否进入站内搜索索引（SearchIndexBuilder）
 *   - geo         是否进入 /geo.json 知识图谱节点（GeoGraphBuilder）
 *   - sitemap     是否进入 sitemap.xml 收录（SitemapBuilder）
 *   - relations   本类型允许关联的目标类型（声明式，2a 不强制校验；
 *                 case_study→product/organization/scenario，product→download_asset）
 *   - metadata    该类型 metadata JSON 允许承载的业务键（契约白名单；
 *                 客户公司经 EntityRelation 关联 Organization，metadata 禁放 CRM 字段）
 *
 * 新增类型只需在此登记 + 在 Registry 生效，消费方（Controller / SchemaBuilder /
 * GeoGraphBuilder / PublicUrl / SearchIndexBuilder / SitemapBuilder）一律从
 * {@see \App\Support\Entities\EntityCapabilityRegistry} 读取，禁止再散点硬编码
 * `if ($type === 'product')` 能力判断。
 */

return [
    'types' => [

        'organization' => [
            'label'      => '组织',
            'schema'     => 'Organization',
            'public'     => false,
            'searchable' => false,
            'geo'        => true,
            'sitemap'    => false,
            // Coverage 标记：纯字符串 = 建议（recommended）；['type'=>X,'required'=>true] = 必备。
            // 旧扁平写法完全兼容，默认 recommended，不改变 relations 的"允许目标/声明式"语义。
            'relations'  => [
                ['type' => 'product', 'required' => true],
                'person',
                'location',
            ],
            'metadata'   => ['brand', 'industry', 'phone', 'email', 'address', 'is_site_organization'],
        ],

        'product' => [
            'label'      => '产品',
            'schema'     => 'Product',
            'public'     => true,
            'searchable' => true,
            'geo'        => true,
            'sitemap'    => true,
            // 必备：连接 ≥1 服务/场景（uses→service）；建议：被组织 produces、提供资料。
            'relations'  => [
                ['type' => 'service', 'required' => true],
                'organization',
                'download_asset',
            ],
            'metadata'   => ['core', 'line', 'tagline', 'key_params', 'params', 'og_image', 'card_image'],
        ],

        'service' => [
            'label'      => '服务/场景',
            'schema'     => 'Service',
            'public'     => true,
            'searchable' => true,
            'geo'        => true,
            'sitemap'    => true,
            // 必备：组合 ≥1 产品（uses→product）。
            'relations'  => [
                ['type' => 'product', 'required' => true],
            ],
            'metadata'   => ['scope', 'title_q', 'og_image', 'card_image'],
        ],

        'person' => [
            'label'      => '人物',
            'schema'     => 'Person',
            'public'     => false,
            'searchable' => false,
            'geo'        => true,
            'sitemap'    => false,
            'relations'  => ['organization'],
            'metadata'   => [],
        ],

        'location' => [
            'label'      => '地点',
            'schema'     => 'Place',
            'public'     => false,
            'searchable' => false,
            'geo'        => true,
            'sitemap'    => false,
            'relations'  => ['organization'],
            'metadata'   => ['address', 'geo'],
        ],

        'topic' => [
            'label'      => '主题',
            'schema'     => 'WebPage',
            'public'     => false,
            'searchable' => false,
            'geo'        => true,
            'sitemap'    => false,
            'relations'  => [],
            'metadata'   => [],
        ],

        // 客户案例（P-STEP 18R-2a 新增）。schema 按产品要求声明为 CaseStudy
        // （schema.org 无标准 CaseStudy 类型，这里作为自定义 @type 输出）。
        // public/searchable/sitemap=true，但 2a 不建 /cases 路由与前台块，
        // 故 PublicUrl 在 2a 仍返回 null（2b 接通路由后自动生效）。
        // metadata 禁放 customer_name/contact/sales_owner/contract/amount——
        // 客户公司经 EntityRelation(related_to, role=customer) 关联 Organization。
        'case_study' => [
            'label'      => '客户案例',
            'schema'     => 'CaseStudy',
            'public'     => true,
            'searchable' => true,
            'geo'        => true,
            'sitemap'    => true,
            // 必备：关联 ≥1 产品（related_to→product）；建议：客户组织、场景。
            'relations'  => [
                ['type' => 'product', 'required' => true],
                'organization',
                'scenario',
            ],
            // metadata 必备三要素（与发布门禁 TD-156 口径一致）；industry/scenario 建议。
            'metadata'   => [
                'industry', 'scenario',
                ['key' => 'challenge', 'required' => true],
                ['key' => 'solution', 'required' => true],
                ['key' => 'result', 'required' => true],
            ],
        ],

        // 下载资料（P-STEP 18R-2a 新增）。geo=true 让 AI 可在图谱中理解「某产品
        // 提供哪些资料」；但 public=false（无独立 /downloads/{slug} 页）、
        // schema=null（不产出 JSON-LD）、searchable/sitemap=false。
        // 资料本体是 Media，经 metadata.media_id 引用；Product --offers--> DownloadAsset。
        'download_asset' => [
            'label'      => '下载资料',
            'schema'     => null,
            'public'     => false,
            'searchable' => false,
            'geo'        => true,
            'sitemap'    => false,
            // 必备：被某产品 offers 指向；必备 metadata.media_id（指向 Media）。
            'relations'  => [
                ['type' => 'product', 'required' => true],
                'media',
            ],
            'metadata'   => [
                ['key' => 'media_id', 'required' => true],
                'type', 'language', 'version',
            ],
        ],

    ],
];
