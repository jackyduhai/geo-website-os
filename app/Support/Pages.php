<?php

namespace App\Support;

use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;

/**
 * 页面级成稿 locale 读取层（P-STEP 18F）
 * ------------------------------------------------------------------
 * config/pages.php 内的 narrative / FAQ / about / factory_steps 出厂只有默认语言；
 * 平行的 *_en 键提供英文成稿。本类是这些成稿的唯一读取入口：
 *   - 当前请求为 en 且对应 _en 数据存在时返回英文；
 *   - 否则回退默认语言成稿（UI 成稿允许回退，公共内容缺失策略由路由层保证）。
 *
 * 结构化硬数据（配比 / 参数 / 资质 / 数字）仍走 Catalog，本类只取页面成稿，
 * 不自行拼接或实现第二套事实源。
 */
class Pages
{
    public static function isEn(): bool
    {
        return LocaleContext::current() !== LocaleRegistry::default();
    }

    /** narrative 组字段（默认 lead） */
    public static function narrative(string $group, string $field = 'lead'): string
    {
        if (self::isEn()) {
            $en = config('pages.narrative_en.' . $group . '.' . $field);
            if (is_string($en) && trim($en) !== '') {
                return $en;
            }
        }
        return (string) config('pages.narrative.' . $group . '.' . $field, '');
    }

    /**
     * FAQ 列表
     *
     * @param  string       $group  cooperation_faqs / product_faqs / scene_faqs
     * @param  string|null  $slug   product_faqs / scene_faqs 的 Example slug
     * @return array<int,array{q:string,a:string}>
     */
    public static function faqs(string $group, ?string $slug = null): array
    {
        if (self::isEn()) {
            $enKey = 'pages.' . $group . '_en' . ($slug !== null ? '.' . $slug : '');
            $en = config($enKey);
            if (is_array($en) && ! empty($en)) {
                return $en;
            }
        }
        $key = 'pages.' . $group . ($slug !== null ? '.' . $slug : '');
        $data = config($key, []);
        return is_array($data) ? $data : [];
    }

    /** about 子页成稿（profile / history / culture） */
    public static function about(string $page): array
    {
        if (self::isEn()) {
            $en = config('pages.about_en.' . $page);
            if (is_array($en) && ! empty($en)) {
                return $en;
            }
        }
        $data = config('pages.about.' . $page, []);
        return is_array($data) ? $data : [];
    }

    /** factory_steps 成稿 */
    public static function factorySteps(): array
    {
        if (self::isEn()) {
            $en = config('pages.factory_steps_en');
            if (is_array($en) && ! empty($en)) {
                return $en;
            }
        }
        $data = config('pages.factory_steps', []);
        return is_array($data) ? $data : [];
    }
}
