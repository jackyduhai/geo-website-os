<?php

namespace App\Http\View;

use App\Services\Seo\SeoMetaResolver;
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
        $suffix = trim((string) ($settings['seo_title_suffix'] ?? $settings['site_name'] ?? ''));
        $titleFull = $seo['title_full']
            ?? (($title !== '' ? $title . ' - ' : '') . $suffix);

        // ---- description：Controller → Resolver（非空才接管）→ 遗留设置 ----
        $description = $seo['description'] ?? null;
        if ($description === null) {
            $siteDesc = $siteResult->description ?? null;
            $description = (is_string($siteDesc) && trim($siteDesc) !== '')
                ? $siteDesc
                : ($settings['seo_default_desc'] ?? '');
        }

        // ---- canonical：Controller → Resolver；不再回退 url()->current() ----
        $canonical = $seo['canonical']
            ?? ($siteResult->canonical ?? url()->current());

        // ---- og:image：Controller → Resolver → 遗留设置 → 静态展示默认 ----
        $ogImage = $seo['image']
            ?? ($siteResult->ogImage ?? null)
            ?? ($settings['seo_og_image'] ?? null);
        $ogImage = $ogImage ?: asset('img/og-default.png');

        // ---- og / twitter ----
        $ogTitle = $seo['title_full']
            ?? ($seo['title'] ?? ($settings['site_name'] ?? $title));
        $ogSiteName = $settings['site_name']
            ?? ($siteResult->title ?? '');
        $twitterCard = $seo['twitter_card']
            ?? ($siteResult->twitterCard ?? 'summary_large_image');

        $view->with('seo', [
            'title'          => (string) $title,
            'title_full'     => (string) $titleFull,
            'description'    => (string) $description,
            'canonical'      => (string) $canonical,
            'noindex'        => (bool) ($seo['noindex'] ?? ($siteResult->noindex ?? false)),
            'type'           => (string) ($seo['type'] ?? ($siteResult->ogType ?? 'website')),
            'image'          => (string) $ogImage,
            'og_title'       => (string) $ogTitle,
            'og_description' => (string) $description,
            'og_site_name'   => (string) $ogSiteName,
            'og_image'       => (string) $ogImage,
            'twitter_card'   => (string) $twitterCard,
            'published'      => $seo['published'] ?? null,
            'modified'       => $seo['modified'] ?? null,
        ]);
    }
}
