<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Content;
use App\Models\Page;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;

/**
 * WP-6 菜单内部目标解析器：判定一个内部菜单链接能否解析为真实当前路由。
 * ------------------------------------------------------------------
 * 与前台 PageController::dispatch 同一口径（内容 → 栏目 → 单段落地页），
 * 并覆盖 Catalog 目录域（products / solutions / ...）。
 *
 * 只做"只读判定"，不写库、不修改菜单。
 */
class MenuTargetResolver
{
    public const VALID   = 'VALID';   // 内部目标可解析
    public const INVALID = 'INVALID'; // 内部目标无法解析（悬空）
    public const EXTERNAL = 'EXTERNAL'; // 外部绝对链接（不做实时可达性校验）
    public const NON_NAV = 'NON_NAV'; // tel / mailto / 锚点 / 空

    /** 对一条存储链接做分类。 */
    public function classify(string $link): string
    {
        $link = trim($link);
        if ($link === '' || $link === '#') {
            return self::NON_NAV;
        }
        if (preg_match('~^https?://~i', $link)) {
            $host = (string) parse_url($link, PHP_URL_HOST);
            $internal = $host !== '' && str_contains((string) config('app.url'), $host);
            if (! $internal) {
                return self::EXTERNAL;
            }
            $path = (string) parse_url($link, PHP_URL_PATH);

            return $this->pathResolves($path) ? self::VALID : self::INVALID;
        }
        if (preg_match('~^(tel:|mailto:)~i', $link)) {
            return self::NON_NAV;
        }

        return $this->pathResolves($link) ? self::VALID : self::INVALID;
    }

    /** 内部路径是否解析为真实当前目标。 */
    public function pathResolves(string $rawPath): bool
    {
        $path = trim((string) preg_replace('~[#?].*$~', '', $rawPath));
        $segments = array_values(array_filter(explode('/', ltrim($path, '/'))));
        if ($segments === []) {
            return false;
        }

        $locale = LocaleContext::current() ?: LocaleRegistry::default();
        $slug = (string) end($segments);

        // 1) Catalog 目录域（products / solutions / factory / cooperation / about / contact）
        if (in_array($segments[0], ['products', 'solutions', 'factory', 'cooperation', 'about', 'contact'], true)) {
            return $this->catalogPathResolves($segments);
        }

        // 2) 内容（published + slug + 当前语言；slug+locale 全站唯一）
        if (Content::published()->where('slug', $slug)->where('locale', $locale)->exists()) {
            return true;
        }

        // 3) 栏目（active 链）
        if ($this->categoryResolves($segments)) {
            return true;
        }

        // 4) 单段组合落地页（published + slug + 当前语言）
        if (count($segments) === 1
            && Page::published()->where('slug', $slug)->where('locale', $locale)->exists()) {
            return true;
        }

        return false;
    }

    private function catalogPathResolves(array $segments): bool
    {
        $top = $segments[0];

        // 空目录站（无 organization Entity）整体不存在
        if (empty(Catalog::company())) {
            return false;
        }

        $sub = $segments[1] ?? '';
        if ($top === 'products') {
            if ($sub === '') {
                return true; // 产品总览
            }
            return Catalog::line($sub) !== null || Catalog::product($sub) !== null;
        }
        if ($top === 'solutions') {
            if ($sub === '') {
                return true; // 场景总览
            }
            return Catalog::scene($sub) !== null;
        }

        // factory / cooperation / about / contact：站点存在目录即成立
        return true;
    }

    private function categoryResolves(array $segments): bool
    {
        $parent = null;
        foreach ($segments as $slug) {
            $q = Category::where('slug', $slug)->where('is_active', true);
            $q = $parent ? $q->where('parent_id', $parent->id) : $q->whereNull('parent_id');
            $category = $q->first();
            if (! $category) {
                return false;
            }
            $parent = $category;
        }

        return true;
    }
}
