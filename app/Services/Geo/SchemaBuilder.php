<?php

namespace App\Services\Geo;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Setting;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoResult;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\Support\Str;

/**
 * JSON-LD 结构化数据生成器（STEP 05 统一 Schema 输出层）
 *
 * 铁律：结构化数据必须由模板生成，禁止手写。
 * 理由：一个引号错误会让整段 schema 作废，而它又不可见，很难发现。
 *
 * 一致性要求：本类输出的每个字段都必须能在页面上找到对应的可见内容，
 * 不允许出现「schema 里有、页面上没有」的字段。
 *
 * 数据源边界（冻结）：
 *   - 主体字段：Entity / Content / Category / Site（正式数据模型）
 *   - SEO 字段：SeoMetaResolver → SeoResult（description / canonical / og:image）
 *   - 站点配置：Setting（后台可运营项，如 geo_org_logo / contact_phone）
 *   - 站点扩展：Site.metadata['organization' / 'web_site']（通用 JSON 扩展，
 *     承载 legalName / address / knowsAbout 等无正式字段的组织属性；
 *     业务值由 Seeder / 后台写入，Core 不含任何业务专属数据或兜底文案）
 *   - 禁止：直读业务事实库（Facts / facts 配置）作为 Schema 数据源
 */
class SchemaBuilder
{
    protected array $settings;

    public function __construct()
    {
        $this->settings = Setting::allCached();
    }

    protected function setting(string $key, string $default = ''): string
    {
        return (string) ($this->settings[$key] ?? $default);
    }

    protected function orgName(): string
    {
        $site = SiteContext::currentSite();

        return $this->setting('geo_org_name') ?: (string) ($site?->name ?? '');
    }

    protected function siteName(): string
    {
        $site = SiteContext::currentSite();

        return $this->setting('site_name') ?: (string) ($site?->name ?? '');
    }

    protected function baseUrl(): string
    {
        // 与 PublicUrl / canonical 同源：真实 HTTP 用请求 origin（多站 / 反代可达），
        // CLI / 队列回退当前站点 domain（https），再回退 app.url。
        return PublicUrl::base();
    }

    /** Site.metadata 通用扩展读取 */
    protected function siteExtension(string $namespace): array
    {
        $metadata = SiteContext::currentSite()?->metadata ?? [];

        return is_array($metadata[$namespace] ?? null) ? $metadata[$namespace] : [];
    }

    // ---------------------------------------------------------------
    // 全局：Organization + WebSite
    // ---------------------------------------------------------------

    public function organization(): array
    {
        $ext = $this->siteExtension('organization');

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => $this->baseUrl() . '/#organization',
            'name'     => $this->orgName(),
            'url'      => $this->baseUrl() . '/',
        ];

        if ($en = $this->setting('geo_org_en_name')) {
            $data['alternateName'] = $en;
        }

        if ($logo = $this->setting('geo_org_logo')) {
            $data['logo'] = $this->absolute($logo);
            $data['image'] = $this->absolute($logo);
        }

        if ($tel = $this->setting('contact_phone')) {
            $data['telephone'] = $tel;
        } elseif (! empty($ext['telephone'])) {
            $data['telephone'] = (string) $ext['telephone'];
        }

        $data += $this->organizationAddress($ext);

        if (! empty($ext['legal_name'])) {
            $data['legalName'] = (string) $ext['legal_name'];
        }

        if (! empty($ext['founding_date'])) {
            $data['foundingDate'] = (string) $ext['founding_date'];
        }

        if (! empty($ext['area_served']) && is_array($ext['area_served'])) {
            $data['areaServed'] = array_map(
                fn ($r) => ['@type' => 'Place', 'name' => (string) $r],
                array_values($ext['area_served'])
            );
        }

        // 联系点（与可见电话一致）
        $contactTel = (string) ($data['telephone'] ?? '');
        if ($contactTel !== '') {
            $data['contactPoint'] = [[
                '@type'       => 'ContactPoint',
                'telephone'   => $contactTel,
                'contactType' => 'customer service',
                'areaServed'  => 'CN',
                'availableLanguage' => ['zh-CN'],
            ]];
        }

        if (! empty($ext['knows_about']) && is_array($ext['knows_about'])) {
            $data['knowsAbout'] = array_values(array_map('strval', $ext['knows_about']));
        }

        if (! empty($ext['same_as']) && is_array($ext['same_as'])) {
            $data['sameAs'] = array_values(array_map('strval', $ext['same_as']));
        }

        if ($desc = $this->setting('site_description')) {
            $data['description'] = $desc;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /** 组织地址：Setting 街道 + metadata 补充行政区的通用组合，不含任何业务硬编码 */
    protected function organizationAddress(array $ext): array
    {
        $extAddress = is_array($ext['address'] ?? null) ? $ext['address'] : [];

        $street = $this->setting('contact_address') ?: (string) ($extAddress['street'] ?? '');
        if ($street === '' && $extAddress === []) {
            return [];
        }

        $address = ['@type' => 'PostalAddress'];
        if ($street !== '') {
            $address['streetAddress'] = $street;
        }
        foreach (['locality' => 'addressLocality', 'region' => 'addressRegion', 'country' => 'addressCountry'] as $k => $schemaKey) {
            if (! empty($extAddress[$k])) {
                $address[$schemaKey] = (string) $extAddress[$k];
            }
        }

        return ['address' => $address];
    }

    public function website(): array
    {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            '@id'             => $this->baseUrl() . '/#website',
            'name'            => $this->siteName(),
            'url'             => $this->baseUrl() . '/',
            'inLanguage'      => 'zh-CN',
            'publisher'       => ['@id' => $this->baseUrl() . '/#organization'],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $this->baseUrl() . '/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // 内容页
    // ---------------------------------------------------------------

    public function article(Content $c, ?SeoResult $seo = null): array
    {
        $seo ??= app(SeoMetaResolver::class)->resolveContent($c);

        $data = [
            '@context'         => 'https://schema.org',
            '@type'            => $c->type === 'product' ? 'Product' : 'Article',
            '@id'              => PublicUrl::content($c) . '#main',
            'headline'         => $seo->title,
            'name'             => $seo->title,
            'description'      => $seo->description,
            'inLanguage'       => 'zh-CN',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => PublicUrl::content($c),
            ],
            'publisher'        => ['@id' => $this->baseUrl() . '/#organization'],
        ];

        if ($seo->ogImage) {
            $data['image'] = $this->absolute($seo->ogImage);
        }

        if ($c->published_at) {
            $data['datePublished'] = $c->published_at->toIso8601String();
        }
        $data['dateModified'] = ($c->updated_at ?? now())->toIso8601String();

        if ($c->summary) {
            $data['abstract'] = $c->summary;
        }

        // 产品页补充品牌与制造商
        if ($c->type === 'product') {
            $data['brand'] = ['@type' => 'Brand', 'name' => $this->siteName()];
            $data['manufacturer'] = ['@id' => $this->baseUrl() . '/#organization'];
            $data['category'] = $c->category?->name;
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    // ---------------------------------------------------------------
    // Entity 通用 Schema（Entity Type 冻结枚举 → schema.org 类型）
    // ---------------------------------------------------------------

    private const ENTITY_SCHEMA_TYPES = [
        Entity::TYPE_ORGANIZATION => 'Organization',
        Entity::TYPE_PERSON       => 'Person',
        Entity::TYPE_PRODUCT      => 'Product',
        Entity::TYPE_SERVICE      => 'Service',
        Entity::TYPE_LOCATION     => 'Place',
        Entity::TYPE_TOPIC        => 'WebPage',
    ];

    /**
     * 按资源类型生成通用实体 Schema。
     * 数据源：Entity 正式字段 + SeoMetaResolver::resolveEntity（description /
     * canonical / og:image）+ Entity.metadata 通用扩展（sameAs / address 等）。
     */
    public function entity(Entity $e, ?SeoResult $seo = null): ?array
    {
        $schemaType = self::ENTITY_SCHEMA_TYPES[$e->type] ?? null;
        if ($schemaType === null) {
            return null;
        }

        $seo ??= app(SeoMetaResolver::class)->resolveEntity($e);
        $metadata = is_array($e->metadata) ? $e->metadata : [];

        $publicUrl = PublicUrl::entity($e);

        $data = [
            '@context'   => 'https://schema.org',
            '@type'      => $schemaType,
            '@id'        => ($publicUrl ?? PublicUrl::home()) . '#' . $e->type . '-' . $e->slug,
            'name'       => $seo->title,
            'description' => $seo->description,
            'inLanguage' => 'zh-CN',
        ];

        // 仅当实体有真实前台落地页时才输出 url；组织 / 人物 / 地点 / 主题、非核心
        // 产品、无场景服务无独立页，不得输出会 404 的地址（Public Render Contract）。
        if ($publicUrl !== null) {
            $data['url'] = $publicUrl;
        }

        if ($seo->ogImage) {
            $data['image'] = $this->absolute($seo->ogImage);
        }

        if (! empty($metadata['same_as']) && is_array($metadata['same_as'])) {
            $data['sameAs'] = array_values(array_map('strval', $metadata['same_as']));
        }

        if (! empty($metadata['address']) && is_array($metadata['address'])) {
            $a = $metadata['address'];
            $data['address'] = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $a['street'] ?? null,
                'addressLocality' => $a['locality'] ?? null,
                'addressRegion'   => $a['region'] ?? null,
                'addressCountry'  => $a['country'] ?? null,
            ]);
        }

        if (! empty($metadata['geo']) && is_array($metadata['geo'])
            && isset($metadata['geo']['lat'], $metadata['geo']['lng'])) {
            $data['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $metadata['geo']['lat'],
                'longitude' => $metadata['geo']['lng'],
            ];
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    public function faqPage(Content $c): ?array
    {
        $faqs = $c->faqList();
        if (count($faqs) < 1) {
            return null;
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            '@id'        => $c->url() . '#faq',
            'mainEntity' => array_map(function ($f) {
                return [
                    '@type'          => 'Question',
                    'name'           => $f['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $f['a'],
                    ],
                ];
            }, $faqs),
        ];
    }

    /**
     * 从原生 Q/A 数组生成 FAQPage（合作页、首页 FAQ 等非 Content 场景复用）。
     * $faqs: [['q' => '问题', 'a' => '答案'], ...]
     */
    public function faqPageFromList(array $faqs, string $url): ?array
    {
        $faqs = array_values(array_filter($faqs, fn ($f) => ! empty($f['q']) && ! empty($f['a'])));
        if (count($faqs) < 1) {
            return null;
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            '@id'        => $url . '#faq',
            'mainEntity' => array_map(function ($f) {
                return [
                    '@type'          => 'Question',
                    'name'           => $f['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $f['a'],
                    ],
                ];
            }, $faqs),
        ];
    }

    public function breadcrumb(array $crumbs): ?array
    {
        if (count($crumbs) < 2) {
            return null;
        }

        $items = [];
        foreach (array_values($crumbs) as $i => $c) {
            $item = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $c['name'],
            ];
            if (! empty($c['url'])) {
                $item['item'] = $c['url'];
            }
            $items[] = $item;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    public function collectionPage(Category $cat, string $url): array
    {
        return array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            '@id'         => $url . '#collection',
            'name'        => $cat->seo_title ?: $cat->name,
            'description' => $cat->description,
            'url'         => $url,
            'inLanguage'  => 'zh-CN',
        ]);
    }

    // ---------------------------------------------------------------
    // 输出
    // ---------------------------------------------------------------

    /**
     * 把若干 schema 片段输出为 <script> 标签。
     * 每个片段独立校验 JSON，任何一段失败都不影响其他段。
     */
    public function render(array $schemas): string
    {
        $out = [];
        foreach ($schemas as $s) {
            if (empty($s)) {
                continue;
            }
            $json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                continue;
            }
            $out[] = '<script type="application/ld+json">' . $json . '</script>';
        }
        return implode("\n", $out);
    }

    // ---------------------------------------------------------------
    // 工具
    // ---------------------------------------------------------------

    protected function absolute(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }
}
