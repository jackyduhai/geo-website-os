<?php

namespace App\Support;

/**
 * 首页「条目型」装修区块的缺省内容（唯一来源）。
 *
 * 前台 HomeController 与后台「首页装修」共用本类：
 * 区块未自定义条目时，前台按此渲染、后台按此预填以便直接编辑；一旦在后台保存条目，以区块 content.items 为准。
 * 信任数字 / 产品参数仍只从站点隔离的 Catalog 派生，这里不产生任何新数字。
 *
 * 过渡层说明（legacy / Example 演示层）：
 *   本类与 config('facts') 及 data migration 播种的首页区块配套，服务于内置 Example
 *   演示站的默认装修，条目文案随 Example 行业数据集，不是行业中立的核心服务。
 *   待前台展示层切换到 Entity / Content 数据源后，首页装修缺省改由通用区块模板与
 *   各站点数据驱动，本类随之退场（见架构审计中的前台数据源切换项 / Issue B）。
 */
class HomeBlockDefaults
{
    public static function for(string $type): array
    {
        return match ($type) {
            'scenes'          => self::scenes(),
            'cooperation'     => self::cooperation(),
            'cases'           => self::cases(),
            'workshops'       => self::workshops(),
            'steps'           => self::steps(),
            'faqs'            => self::faqs(),
            'capabilities'    => self::capabilities(),
            default           => [],
        };
    }

    /** S02 应用场景（含标签与揭示参数，后台可改文案/换图/改链接）。 */
    public static function scenes(): array
    {
        $out = [];
        foreach (Catalog::scenes() as $sc) {
            $tags = [];
            foreach (($sc['combo'] ?? []) as $pslug) {
                $p = Catalog::product($pslug);
                if ($p) {
                    $tags[] = $p['short_name'] ?? $p['name'];
                }
            }
            $out[] = [
                'icon'   => null,
                'title'  => $sc['name'],
                'text'   => $sc['desc'],
                'link'   => url('/solutions/' . $sc['slug'] . '/'),
                'tags'   => $tags,
                'reveal' => $sc['hover_reveal'] ?? ($sc['key_param_display'] ?? ''),
            ];
        }
        return $out;
    }

    /** S06 合作方式。 */
    public static function cooperation(): array
    {
        return array_map(function ($m) {
            return [
                'icon'   => 'check',
                'title'  => $m['name'] ?? ($m['title'] ?? ''),
                'text'   => $m['fit'] ?? ($m['desc'] ?? ''),
                'points' => $m['includes'] ?? ($m['points'] ?? []),
                'cta'    => $m['cta'] ?? '了解合作方式',
                'link'   => url('/cooperation/'),
            ];
        }, Catalog::cooperation()['types'] ?? []);
    }

    /** S07 匿名合作剪影。 */
    public static function cases(): array
    {
        $out = [];
        foreach (Catalog::cases() as $case) {
            $tags = [];
            foreach (($case['combo'] ?? []) as $slug) {
                $p = Catalog::product($slug);
                if ($p) {
                    $tags[] = $p['short_name'] ?? $p['name'];
                }
            }
            $out[] = [
                'icon'  => null,
                'title' => $case['title'],
                'sub'   => $case['region_label'] ?? '',
                'tags'  => $tags,
                'text'  => $tags ? '使用组合：' . implode('、', $tags) : '',
            ];
        }
        return $out;
    }

    /** S05 生产车间（配默认线性图标，数量随 facts 数据）。 */
    public static function workshops(): array
    {
        $icons = ['package', 'sliders', 'gear', 'shield'];
        $i = 0;
        return array_map(function ($w) use (&$i, $icons) {
            return ['icon' => $icons[$i++] ?? 'factory', 'title' => $w['name'], 'text' => $w['desc']];
        }, Catalog::workshops());
    }

    /** S06 合作流程。 */
    public static function steps(): array
    {
        return array_map(fn ($s) => ['title' => $s['name'], 'text' => $s['desc']], Catalog::cooperation()['process'] ?? []);
    }

    /** S09 首页 FAQ。 */
    public static function faqs(): array
    {
        return array_map(fn ($f) => ['title' => $f['q'], 'text' => $f['a']], (array) config('pages.home_faqs', []));
    }

    /** S03 能力点。 */
    public static function capabilities(): array
    {
        return [
            ['icon' => 'sliders', 'title' => '配方定制', 'text' => '按客户性能要求定向研发，非标定制'],
            ['icon' => 'factory', 'title' => 'OEM / ODM 代工', 'text' => '从原料到成品的完整代工链路'],
            ['icon' => 'repeat', 'title' => '打样到量产', 'text' => '需求对接 → 配方打样 → 试样确认 → 批量交付'],
        ];
    }
}
