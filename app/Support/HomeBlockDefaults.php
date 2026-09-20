<?php

namespace App\Support;

/**
 * 首页「条目型」装修区块的缺省内容（唯一来源）。
 *
 * 前台 HomeController 与后台「首页装修」共用本类：
 * 区块未自定义条目时，前台按此渲染、后台按此预填以便直接编辑；一旦在后台保存条目，以区块 content.items 为准。
 * 信任数字 / 产品参数仍只从 Facts 派生，这里不产生任何新数字。
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
        foreach (Facts::scenes() as $sc) {
            $tags = [];
            foreach (($sc['combo'] ?? []) as $pslug) {
                $p = Facts::product($pslug);
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
        }, Facts::cooperation()['types'] ?? []);
    }

    /** S07 匿名合作剪影。 */
    public static function cases(): array
    {
        $out = [];
        foreach (Facts::cases() as $case) {
            $tags = [];
            foreach (($case['combo'] ?? []) as $slug) {
                $p = Facts::product($slug);
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
        }, Facts::workshops());
    }

    /** S06 合作流程。 */
    public static function steps(): array
    {
        return array_map(fn ($s) => ['title' => $s['name'], 'text' => $s['desc']], Facts::cooperation()['process'] ?? []);
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
