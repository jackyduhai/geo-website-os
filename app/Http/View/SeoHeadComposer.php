<?php

namespace App\Http\View;

use App\Models\Content;
use App\Models\Entity;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use Illuminate\View\View;

/**
 * SEO 头部数据归一化（STEP 02：Blade SEO Single Source）。
 *
 * 职责：在 Blade 渲染前把 $seo 归一化为完整数组，使布局头部成为纯消费方——
 * Blade 不再读取 Setting / Facts / config / URL 生成器，也不再自行拼接任何
 * SEO 兜底（Blade 层原兜底逻辑由此接管，键名保持不变）。
 *
 * 归一化优先级（每个键独立）：
 *   Controller 传入的 $seo 键（核心页 = SeoResult 映射；未迁移页 = 过渡数组）
 *   → SeoMetaResolver::resolveSite()（站点级统一 Resolution）
 *   → 遗留站点设置（seo_title_suffix / seo_default_desc / seo_og_image / site_name，
 *     过渡期保留，随 STEP 08 剩余 Controller 迁移一并移除）
 *   → 静态演示默认（og-default.png，展示层兜底）
 *
 * 键名与既有布局契约保持一致（title_full / description / canonical / image /
 * noindex / type / published / modified），新增 og_title / og_description /
 * og_site_name / og_image / twitter_card。
 */
class SeoHeadComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        $seo = is_array($data['seo'] ?? null) ? $data['seo'] : [];

        $site = SiteContext::currentSite();
        $siteResult = $site ? app(SeoMetaResolver::class)->resolveSite($site) : null;

        // 遗留站点设置由全局 composer 注入；此处仅在缺失时兜底读取
        $settings = is_array($data['siteSettings'] ?? null) ? $data['siteSettings'] : [];

        // ---- title / title_full ----
        $title = $seo['title'] ?? ($siteResult->title ?? '');
        // 遗留后缀拼接（"标题 - 站名"）：展示层过渡约定，STEP 08 随站点级
        // SeoMeta.title 接管后移除；核心页传 title_full 时不叠加。
        // en：站名后缀取 geo_org_en_name（Site 聚合英文名），不能用单语 site_name，
        // 否则英文内页标题尾部仍拼中文公司名。
        if (LocaleContext::current() === 'en') {
            $suffix = trim((string) ($settings['geo_org_en_name'] ?? ''));
        } else {
            $suffix = trim((string) ($settings['seo_title_suffix'] ?? $settings['site_name'] ?? ''));
        }
        // 仅当后缀非空时才拼接 " - "，避免站点名/后缀缺失时标题尾部出现悬挂分隔符
        // （例如 fresh install 未配置 site_name 时内页标题渲染成 "文章标题 - "）。（P-STEP 14）
        $titleParts = array_filter([$title !== '' ? $title : null, $suffix !== '' ? $suffix : null]);
        $titleFull = $seo['title_full'] ?? implode(' - ', $titleParts);

        // ---- description：Controller → Resolver（非空才接管）→ 遗留设置 ----
        $description = $seo['description'] ?? null;
        // 空串（首页显式传 ''）与 null 同样回退，否则首页 description / og:description 为空。
        if (! is_string($description) || trim($description) === '') {
            $siteDesc = $siteResult->description ?? null;
            if (is_string($siteDesc) && trim($siteDesc) !== '') {
                $description = $siteDesc;
            } else {
                $defaultDescKey = LocaleContext::current() === 'en'
                    ? 'seo_default_en_desc'
                    : 'seo_default_desc';
                $description = $settings[$defaultDescKey] ?? $settings['seo_default_desc'] ?? '';
            }
        }

        // ---- canonical：Controller → Resolver；不再回退 url()->current() ----
        $canonical = $seo['canonical']
            ?? ($siteResult->canonical ?? url()->current());

        // ---- og:image：Controller → Resolver → 遗留设置 → 静态展示默认 ----
        $ogImage = $seo['image']
            ?? ($siteResult->ogImage ?? null)
            ?? ($settings['seo_og_image'] ?? null);
        // 默认 OG 图是声明性绝对 URL（社交抓取），必须经 PublicUrl 裁决到站点规范
        // host，不能用 asset() 跟随临时请求 origin（TD-09：HTTP / CLI host 不分叉）。
        $ogImage = $ogImage ?: PublicUrl::base() . '/img/og-default.png';

        // ---- og / twitter ----
        $ogTitle = $seo['og_title']
            ?? $seo['title_full']
            ?? ($seo['title'] ?? ($settings['site_name'] ?? $title));
        // og 描述优先取 Resolver 解析值（SeoMeta.og_description → 主描述回退链），
        // 不再无条件等同主描述（P-STEP 17D：打通显式社交标题 / 描述到前台 head）。
        $ogDescription = (string) ($seo['og_description'] ?? '');
        if (trim($ogDescription) === '') {
            $ogDescription = (string) $description;
        }
        // en：og:site_name 取主体英文名，不能用单语 site_name。
        $ogSiteName = LocaleContext::current() === 'en'
            ? ($settings['geo_org_en_name'] ?? ($siteResult->title ?? ''))
            : ($settings['site_name'] ?? ($siteResult->title ?? ''));
        $twitterCard = $seo['twitter_card']
            ?? ($siteResult->twitterCard ?? 'summary_large_image');

        // 资源级 hreflang：详情页（Content / Entity 产品 / 场景）只输出该资源实际
        // 已发布的语言，避免站点启用了 en 但此资源无英文时，hreflang 指向 404。
        // 非资源页（首页 / 列表 / 总览等）返回 null，布局回退站点 supported locales。
        $resourceLocales = $this->resourceLocales($data);
        $hreflang = $this->buildHreflang($settings, $resourceLocales);

        $view->with('seo', [
            'title'          => (string) $title,
            'title_full'     => (string) $titleFull,
            'description'    => (string) $description,
            'canonical'      => (string) $canonical,
            'noindex'        => (bool) ($seo['noindex'] ?? ($siteResult->noindex ?? false)),
            'type'           => (string) ($seo['type'] ?? ($siteResult->ogType ?? 'website')),
            'image'          => (string) $ogImage,
            'og_title'       => (string) $ogTitle,
            'og_description' => (string) $ogDescription,
            'og_site_name'   => (string) $ogSiteName,
            'og_image'       => (string) $ogImage,
            'twitter_card'   => (string) $twitterCard,
            'published'      => $seo['published'] ?? null,
            'modified'       => $seo['modified'] ?? null,
            'hreflang_locales' => $resourceLocales,
            'hreflang_alternates' => $hreflang['alternates'],
            'hreflang_xdefault' => $hreflang['xdefault'],
        ]);
    }

    /**
     * 推断当前详情页资源已发布的语言集合；非资源页返回 null（站点级）。
     *
     * @param  array<string,mixed>  $data
     * @return array<int,string>|null
     */
    private function resourceLocales(array $data): ?array
    {
        $siteId = SiteContext::currentSite()?->id;

        // Content 详情（PageController renderContent 传入 Content 模型）
        if (($data['content'] ?? null) instanceof Content) {
            return $data['content']->publishedLocaleCodes() ?: null;
        }

        // Entity 产品详情：视图 $product 为 Catalog 数组（含 slug）
        if (is_array($data['product'] ?? null) && ! empty($data['product']['slug'])) {
            $entity = Entity::where('site_id', $siteId)
                ->where('type', 'product')
                ->where('slug', $data['product']['slug'])
                ->where('locale', LocaleRegistry::default())
                ->first();

            return $entity?->publishedLocaleCodes() ?: null;
        }

        // 场景详情：视图 $scene 为 Catalog 数组（service Entity，含 slug）
        if (is_array($data['scene'] ?? null) && ! empty($data['scene']['slug'])) {
            $entity = Entity::where('site_id', $siteId)
                ->where('type', 'service')
                ->where('slug', $data['scene']['slug'])
                ->where('locale', LocaleRegistry::default())
                ->first();

            return $entity?->publishedLocaleCodes() ?: null;
        }

        return null;
    }

    /**
     * 构建 hreflang 规范对等链接（Blade 纯消费）：站点 supported locales
     * × 资源级已发布语言取交集，URL 不含 query（utm 等不进入 hreflang）。
     *
     * @param  array<string,mixed>  $settings
     * @param  array<int,string>|null  $resourceLocales
     * @return array{alternates: array<int,array{hreflang:string,href:string}>, xdefault: string}
     */
    private function buildHreflang(array $settings, ?array $resourceLocales): array
    {
        $supported = $settings['site_supported_locales'] ?? [LocaleRegistry::default()];
        if (! is_array($supported)) {
            $supported = array_values(array_filter(array_map('trim', explode(',', (string) $supported))));
        }
        $supported = array_values(array_filter($supported, static fn ($l) => is_string($l) && LocaleRegistry::supports($l)));
        if ($supported === []) { $supported = [LocaleRegistry::default()]; }

        $locales = $supported;
        if ($resourceLocales !== null && $resourceLocales !== []) {
            $locales = array_values(array_intersect($supported, $resourceLocales));
            $current = LocaleContext::current();
            if (! in_array($current, $locales, true)) { $locales[] = $current; }
            if ($locales === []) { $locales = $supported; }
        }
        $locales = array_values(array_unique($locales));

        $basePath = trim((string) preg_replace('#^en(/|$)#', '', (string) request()->path()), '/');

        // hreflang 必须为「每个目标 locale」生成其自身绝对 URL，不能用 url() helper：
        // GeoUrlGenerator::withLocalePrefix 会按当前请求 locale 统一给路径加前缀
        // （英文上下文里 url('/') 会被改写成 /en），导致 zh-CN 对等链接错误指向
        // /en。这里直接用请求根（scheme+host+port，不含 locale）+ 目标 locale 前缀
        // 显式拼接，绕过 UrlGenerator 的当前语言兜底。
        $root = rtrim((string) request()->root(), '/');
        $make = static function (string $locale) use ($basePath, $root) {
            $path = trim(LocaleRegistry::prefix($locale) . '/' . $basePath, '/');
            return $path === '' ? $root . '/' : $root . '/' . $path;
        };

        $alternates = [];
        foreach ($locales as $loc) {
            $alternates[] = ['hreflang' => str_replace('_', '-', $loc), 'href' => $make($loc)];
        }

        return ['alternates' => $alternates, 'xdefault' => $make(LocaleRegistry::default())];
    }
}
