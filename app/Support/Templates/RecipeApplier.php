<?php

namespace App\Support\Templates;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Localization\LocaleRegistry;
use App\Support\PageCache;
use App\Support\Render\SystemPageRenderContext;
use App\Support\SiteContext;
use Illuminate\Support\Collection;

/**
 * Recipe Applier（P-STEP 18L-3a）。
 * --------------------------------------------------
 * 把模板包配方（recipes/*.json）幂等地落地为站点的 Page + PageBlock：
 *
 *   - 为站点每个「已启用语言」解析 / 创建目标 Page（默认语言权威行优先，
 *     各语言行共享 translation_group）；
 *   - 静态文案按 locale map 解析为当前语言，内部 URL 按语言加前缀；
 *   - 配方区块注入 _recipe / _recipe_index 标记，槽位级幂等同步（updateOrCreate
 *     语义），不覆盖管理员手动区块、不复制业务事实；
 *   - 尊重系统页固定槽（main 的 sys_* 不可替换），只写可组合槽。
 *
 * 渲染仍走统一 Composition 管线；本类只做「声明 → 持久化」，不另造渲染 / SEO。
 */
class RecipeApplier
{
    /**
     * 应用单个配方到站点。
     *
     * @return array{pages:int,created:int,updated:int,deleted:int,locales:array<int,string>}
     */
    public static function apply(Site $site, string $packId, array $recipe): array
    {
        return SiteContext::withSite($site, function () use ($site, $packId, $recipe) {
            $report = ['pages' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'locales' => []];
            $marker = $packId . '.' . $recipe['key'];
            $groups = [];

            $locales = self::localesFor();
            usort(
                $locales,
                static fn ($a, $b) => $a === LocaleRegistry::default()
                    ? -1
                    : ($b === LocaleRegistry::default() ? 1 : 0)
            );

            foreach ($locales as $locale) {
                $targetId = self::targetId($recipe['target']);

                $page = self::resolvePage($site, $recipe, $locale, $groups);
                if (! $page->exists) {
                    $page->save();
                    $report['pages']++;
                }
                $groups[$targetId] = $page->translation_group;

                self::syncBlocks($site, $page, $recipe, $locale, $marker, $report);
                $report['locales'][] = $locale;
            }

            PageCache::flush();

            return $report;
        });
    }

    /** 站点已启用语言（缺省仅默认语言）。 */
    private static function localesFor(): array
    {
        $raw = (array) Setting::get('site_supported_locales', [LocaleRegistry::default()]);
        $locales = array_values(array_unique(array_filter(
            $raw,
            static fn ($l) => LocaleRegistry::supports((string) $l)
        )));

        return $locales !== [] ? $locales : [LocaleRegistry::default()];
    }

    /** 目标唯一标识（跨语言共享 translation group 用）。 */
    private static function targetId(array $target): string
    {
        return $target['type'] . ':' . ($target['key'] ?? $target['slug'] ?? '');
    }

    /** 解析 / 构造目标语言行 Page（不保存）。 */
    private static function resolvePage(Site $site, array $recipe, string $locale, array $groups): Page
    {
        $target = $recipe['target'];
        $targetId = self::targetId($target);

        $existing = self::findPage($site, $target, $locale);
        if ($existing !== null) {
            return $existing;
        }

        $data = self::pageDefaults($target, $recipe, $locale);
        if (isset($groups[$targetId])) {
            $data['translation_group'] = $groups[$targetId];
        }

        $page = new Page($data);
        $page->site_id = $site->id;
        $page->locale = $locale;

        return $page;
    }

    /** 按 target 查找现有语言行 Page。 */
    private static function findPage(Site $site, array $target, string $locale): ?Page
    {
        $query = Page::query()->where('site_id', $site->id)->where('locale', $locale);

        return match ($target['type']) {
            'home' => $query->where('is_home', true)->first(),
            'system' => $query->where('is_system', true)
                ->where('system_key', $target['key'])->first(),
            'page' => $query->where('is_system', false)
                ->where('slug', $target['slug'])->first(),
            default => null,
        };
    }

    /** 新 Page 默认属性。 */
    private static function pageDefaults(array $target, array $recipe, string $locale): array
    {
        if ($target['type'] === 'system') {
            $key = $target['key'];
            [$template, $slugPath, $titleKey] = SystemPageRenderContext::DEFINITIONS[$key];

            return [
                'template'   => $template,
                'slug'       => $slugPath,
                'is_home'    => false,
                'is_system'  => true,
                'system_key' => $key,
                'status'     => Page::STATUS_PUBLISHED,
                'title'      => self::transKey($titleKey, $locale),
            ];
        }

        if ($target['type'] === 'home') {
            return [
                'template'   => (string) ($recipe['template'] ?? 'home'),
                'slug'       => null,
                'is_home'    => true,
                'is_system'  => false,
                'system_key' => null,
                'status'     => Page::STATUS_PUBLISHED,
                'title'      => '',
            ];
        }

        return [
            'template'   => (string) ($recipe['template'] ?? 'landing'),
            'slug'       => $target['slug'],
            'is_home'    => false,
            'is_system'  => false,
            'system_key' => null,
            'status'     => Page::STATUS_PUBLISHED,
            'title'      => self::resolveValue($target['title'] ?? '', $locale),
        ];
    }

    /** 目标允许写入的槽位（固定槽保护）。 */
    private static function allowedSlots(array $target): array
    {
        if ($target['type'] === 'system') {
            return match ($target['key']) {
                'contact' => ['header', 'main'],
                'products', 'solutions', 'knowledge' => ['sidebar'],
                default => ['related'],
            };
        }

        return ['main'];
    }

    /** PageBlock.page 字符串标识。 */
    private static function pageKey(array $target): string
    {
        return match ($target['type']) {
            'home' => 'home',
            'system' => $target['key'],
            default => (string) ($target['slug'] ?? 'page'),
        };
    }

    /** 槽位级幂等同步配方区块。 */
    private static function syncBlocks(
        Site $site,
        Page $page,
        array $recipe,
        string $locale,
        string $marker,
        array &$report
    ): void {
        $allowed = self::allowedSlots($recipe['target']);

        $blocks = array_values(array_filter(
            (array) ($recipe['blocks'] ?? []),
            static fn ($b) => is_array($b)
                && ! empty($b['type'])
                && in_array($b['slot'] ?? '', $allowed, true)
        ));

        $existing = $page->blocks()->get();
        $managed = $existing->filter(
            static fn (PageBlock $pb) => ($pb->cfg()['_recipe'] ?? '') === $marker
        )->values();

        foreach ($blocks as $index => $b) {
            $slot = (string) $b['slot'];
            $content = self::resolveContent((array) ($b['content'] ?? []), $locale);
            $content['_recipe'] = $marker;
            $content['_recipe_index'] = $index;

            $sort = array_key_exists('sort', $b)
                ? (int) $b['sort']
                : self::nextSort($existing, $slot, $index);

            $match = $managed->first(
                static fn (PageBlock $pb) => $pb->slot === $slot
                    && (int) ($pb->cfg()['_recipe_index'] ?? -1) === $index
            );

            $json = json_encode($content, JSON_UNESCAPED_UNICODE);

            if ($match !== null) {
                if ($match->content !== $json || (int) $match->sort !== $sort || $match->type !== $b['type']) {
                    $match->type = $b['type'];
                    $match->content = $json;
                    $match->sort = $sort;
                    $match->is_active = true;
                    $match->save();
                    $report['updated']++;
                }
                continue;
            }

            $pb = new PageBlock([
                'site_id'   => $site->id,
                'page'      => self::pageKey($recipe['target']),
                'slot'      => $slot,
                'type'      => $b['type'],
                'content'   => $json,
                'category_id' => null,
                'limit'     => 0,
                'sort'      => $sort,
                'is_active' => true,
            ]);
            $pb->page_id = $page->id;
            $pb->save();
            $report['created']++;
        }

        $validKeys = [];
        foreach ($blocks as $index => $b) {
            $validKeys[] = $b['slot'] . ':' . $index;
        }

        foreach ($managed as $pb) {
            $key = $pb->slot . ':' . (int) ($pb->cfg()['_recipe_index'] ?? -1);
            if (! in_array($key, $validKeys, true)) {
                $pb->delete();
                $report['deleted']++;
            }
        }
    }

    /** 未指定 sort 时，从该槽现有最大 sort 之后按 index 分配。 */
    private static function nextSort(Collection $existing, string $slot, int $index): int
    {
        $max = 0;
        foreach ($existing as $pb) {
            if ($pb->slot === $slot) {
                $max = max($max, (int) $pb->sort + 1);
            }
        }

        return $max + $index;
    }

    /** 递归解析整段 content（locale map + 按钮 URL 前缀）。 */
    private static function resolveContent(array $content, string $locale): array
    {
        $out = [];
        foreach ($content as $k => $v) {
            $out[$k] = self::resolveValue($v, $locale);
        }

        if (isset($out['buttons']) && is_array($out['buttons'])) {
            foreach ($out['buttons'] as $i => $btn) {
                if (is_array($btn) && array_key_exists('url', $btn)) {
                    $out['buttons'][$i]['url'] = self::localizeUrl((string) $btn['url'], $locale);
                }
            }
        }

        return $out;
    }

    /** 递归解析值：locale map 取当前语言，数组递归，标量原样。 */
    private static function resolveValue(mixed $value, string $locale): mixed
    {
        if (is_array($value)) {
            if (self::isLocaleMap($value)) {
                return $value[$locale]
                    ?? $value[LocaleRegistry::default()]
                    ?? null;
            }

            $out = [];
            foreach ($value as $k => $vv) {
                $out[$k] = self::resolveValue($vv, $locale);
            }

            return $out;
        }

        return $value;
    }

    /** 是否为 locale map（所有键均为已注册语言码）。 */
    private static function isLocaleMap(array $value): bool
    {
        $keys = array_keys($value);
        if ($keys === []) {
            return false;
        }

        foreach ($keys as $k) {
            if (! is_string($k) || ! LocaleRegistry::supports($k)) {
                return false;
            }
        }

        return true;
    }

    /** 内部 URL 按非默认语言加前缀（外部 / 绝对 / scheme URL 不动）。 */
    private static function localizeUrl(string $url, string $locale): string
    {
        if ($locale === LocaleRegistry::default() || $url === '') {
            return $url;
        }
        if (str_starts_with($url, '//')
            || str_starts_with($url, 'http')
            || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return $url;
        }

        if (! str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        $prefix = LocaleRegistry::prefix($locale);
        if ($prefix !== ''
            && ! str_starts_with($url, '/' . $prefix . '/')
            && $url !== '/' . $prefix) {
            return '/' . $prefix . $url;
        }

        return $url;
    }

    /** 在不泄漏全局 locale 的前提下取某语言文案。 */
    private static function transKey(string $key, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return (string) __($key);
        } finally {
            app()->setLocale($previous);
        }
    }
}
