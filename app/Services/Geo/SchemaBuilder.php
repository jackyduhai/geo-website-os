<?php

namespace App\Services\Geo;

use App\Models\Category;
use App\Models\Content;
use App\Models\Fact;
use App\Models\Setting;
use App\Support\Facts;
use Illuminate\Support\Str;

/**
 * JSON-LD 结构化数据生成器
 *
 * 铁律：结构化数据必须由模板生成，禁止手写。
 * 理由：一个引号错误会让整段 schema 作废，而它又不可见，很难发现。
 *
 * 一致性要求：本类输出的每个字段都必须能在页面上找到对应的可见内容，
 * 不允许出现「schema 里有、页面上没有」的字段。
 */
class SchemaBuilder
{
    protected array $facts;
    protected array $settings;

    public function __construct()
    {
        $this->facts = Fact::publicMap();
        $this->settings = Setting::allCached();
    }

    protected function fact(string $key, string $default = ''): string
    {
        return (string) ($this->facts[$key] ?? $default);
    }

    protected function setting(string $key, string $default = ''): string
    {
        return (string) ($this->settings[$key] ?? $default);
    }

    protected function orgName(): string
    {
        return $this->setting('geo_org_name') ?: ($this->fact('FACT-COMPANY-001') ?: 'Example Food Co., Ltd.');
    }

    protected function baseUrl(): string
    {
        return rtrim(config('app.url'), '/');
    }

    // ---------------------------------------------------------------
    // 全局：Organization + WebSite
    // ---------------------------------------------------------------

    public function organization(): array
    {
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
        }

        if ($addr = $this->setting('contact_address')) {
            $data['address'] = [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $addr,
                'addressLocality' => 'Sample City市',
                'addressRegion'   => 'Sample Province省',
                'addressCountry'  => 'CN',
            ];
        }

        if ($founded = $this->fact('FACT-COMPANY-003')) {
            $data['foundingDate'] = $this->toIsoDate($founded);
        }

        if ($desc = $this->setting('site_description')) {
            $data['description'] = $desc;
        }

        // ---- 以下用 Facts（facts.yaml 权威数据源）补全 / 兜底，保证实体信息完整 ----
        $company = Facts::company();

        $data['legalName'] = $company['name'];
        if (empty($data['telephone']) && ! empty($company['phone'])) {
            $data['telephone'] = $company['phone'];
        }
        if (empty($data['address']) && ! empty($company['address']['full'])) {
            $data['address'] = [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $company['address']['street'] ?? $company['address']['full'],
                'addressLocality' => $company['address']['city'] ?? 'Sample City市',
                'addressRegion'   => $company['address']['province'] ?? 'Sample Province省',
                'addressCountry'  => $company['address']['country'] ?? 'CN',
            ];
        }
        // 成立时间（ISO 年-月），Facts 为权威口径
        if (! empty($company['founded'])) {
            $data['foundingDate'] = $company['founded'];
        }
        // 服务区域：全国七大销售区域
        $regions = Facts::salesRegions();
        if ($regions) {
            $data['areaServed'] = array_map(fn ($r) => ['@type' => 'Place', 'name' => $r . '地区'], $regions);
        }
        // 联系点（与可见电话一致）
        if (! empty($company['phone'])) {
            $data['contactPoint'] = [[
                '@type'       => 'ContactPoint',
                'telephone'   => $company['phone_tel'] ?? $company['phone'],
                'contactType' => 'customer service',
                'areaServed'  => 'CN',
                'availableLanguage' => ['zh-CN'],
            ]];
        }

        // knowsAbout：让 AI 知道这个实体的专业领域
        $data['knowsAbout'] = ['中式Sample SnackSample Marinade', '鸡架Sample Marinade', 'Sample Breading撒料', '调理鸡肉制品', 'OEM/ODM 代工'];

        return $data;
    }

    public function website(): array
    {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            '@id'             => $this->baseUrl() . '/#website',
            'name'            => $this->setting('site_name', 'Example Food'),
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

    public function article(Content $c): array
    {
        $data = [
            '@context'         => 'https://schema.org',
            '@type'            => $c->type === 'product' ? 'Product' : 'Article',
            '@id'              => $c->url() . '#main',
            'headline'         => $c->title,
            'name'             => $c->title,
            'description'      => $c->metaDescription(),
            'inLanguage'       => 'zh-CN',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $c->canonicalUrl(),
            ],
            'publisher'        => ['@id' => $this->baseUrl() . '/#organization'],
        ];

        if ($c->published_at) {
            $data['datePublished'] = $c->published_at->toIso8601String();
        }
        $data['dateModified'] = ($c->updated_at ?? now())->toIso8601String();

        if ($c->summary) {
            $data['abstract'] = $c->summary;
        }

        // 产品页补充品牌与制造商
        if ($c->type === 'product') {
            $data['brand'] = ['@type' => 'Brand', 'name' => $this->setting('site_name', 'Example')];
            $data['manufacturer'] = ['@id' => $this->baseUrl() . '/#organization'];
            $data['category'] = $c->category?->name;
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

    /** 把「2017 年 3 月」这类中文日期转成 ISO 格式，失败则返回空 */
    protected function toIsoDate(string $text): string
    {
        if (preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/', $text, $m)) {
            return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/(\d{4})/', $text, $m)) {
            return $m[1] . '-01-01';
        }
        return '';
    }
}
