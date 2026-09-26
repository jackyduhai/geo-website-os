<?php

namespace App\Support\Templates;

use App\Models\Menu;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Localization\LocaleRegistry;
use App\Support\PageCache;
use App\Support\SiteContext;

/**
 * Template Defaults Installer（P-STEP 18L-3b）。
 * ------------------------------------------------------------------
 * 对应生命周期命令 **template:bootstrap**：把模板包 defaults/ 下的「建议默认值」
 * 显式、幂等地落地到站点，让模板包成为真正的「安装包」。
 *
 *   defaults/settings.json → Setting
 *   defaults/menus.json    → 主导航 / 页脚 Menu
 *   defaults/seo.json      → site-level SeoMeta（content/entity/page 关联全 null）
 *
 * 硬边界（用户裁定）：
 *   - defaults 是建议默认值，默认只在「当前为空」时落地，已有值不覆盖；
 *     force=true（显式 --force）才覆盖。
 *   - 全部写入正式模型，由现有 Resolver 消费，不产生第二事实源。
 *   - locale map 在安装期按默认语言解析（菜单 / site SEO 为中性默认）。
 *
 * 渲染仍走统一管线；本类只做「声明 → 持久化」。
 */
class TemplateDefaultsInstaller
{
    /**
     * 落地模板包 defaults。
     *
     * @param  bool  $force  覆盖已有值（显式）
     * @return array{settings:array,menus:array,seo:array}
     */
    public static function bootstrap(Site $site, string $packId, bool $force = false): array
    {
        return SiteContext::withSite($site, function () use ($site, $packId, $force) {
            $base = TemplatePackageManager::basePath() . '/' . $packId . '/defaults';
            $report = [
                'settings' => ['applied' => [], 'skipped' => []],
                'menus'    => ['applied' => [], 'skipped' => []],
                'seo'      => ['applied' => [], 'skipped' => []],
            ];

            self::applySettings($base, $force, $report);
            self::applyMenus($base, $force, $report);
            self::applySeo($base, $force, $report);

            PageCache::flush();

            return $report;
        });
    }

    /** settings.json → Setting（空值才写，除非 force）。 */
    private static function applySettings(string $base, bool $force, array &$report): void
    {
        $file = $base . '/settings.json';
        if (! is_file($file)) {
            return;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return;
        }

        foreach ($data as $key => $suggested) {
            if ($key === 'comment') {
                continue;
            }

            $current = Setting::get((string) $key, null);
            if (! $force && ! self::isEmpty($current)) {
                $report['settings']['skipped'][] = (string) $key;
                continue;
            }

            Setting::set((string) $key, $suggested);
            $report['settings']['applied'][] = (string) $key;
        }
    }

    /** menus.json → Menu（目标位置已有项则跳过，除非 force）。 */
    private static function applyMenus(string $base, bool $force, array &$report): void
    {
        $file = $base . '/menus.json';
        if (! is_file($file)) {
            return;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return;
        }

        foreach (['main', 'footer'] as $position) {
            $items = (array) ($data[$position] ?? []);
            $items = array_values(array_filter($items, 'is_array'));

            $existing = Menu::at($position)->roots()->get();
            if ($items === []) {
                continue;
            }

            if (! $force && $existing->isNotEmpty()) {
                $report['menus']['skipped'][] = $position;
                continue;
            }

            foreach ($items as $i => $item) {
                Menu::create([
                    'position'  => $position,
                    'parent_id' => null,
                    'label'     => self::neutral($item['label'] ?? ''),
                    'url'       => self::neutral($item['url'] ?? '#'),
                    'target'    => ! empty($item['new_tab']) ? 1 : 0,
                    'sort'      => (int) ($item['sort'] ?? $i),
                    'is_active' => true,
                ]);
            }

            $report['menus']['applied'][] = $position;
        }
    }

    /** seo.json → site-level SeoMeta（已存在则跳过，除非 force）。 */
    private static function applySeo(string $base, bool $force, array &$report): void
    {
        $file = $base . '/seo.json';
        if (! is_file($file)) {
            return;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return;
        }

        $entries = (array) ($data['site'] ?? []);
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $locale = (string) ($entry['locale'] ?? LocaleRegistry::default());
            $existing = SeoMeta::query()
                ->whereNull('content_id')
                ->whereNull('entity_id')
                ->whereNull('page_id')
                ->where('locale', $locale)
                ->first();

            if ($existing !== null && ! $force) {
                $report['seo']['skipped'][] = $locale;
                continue;
            }

            $payload = array_merge(
                ['content_id' => null, 'entity_id' => null, 'page_id' => null, 'locale' => $locale],
                array_intersect_key($entry, array_flip([
                    'title', 'description', 'keywords', 'canonical',
                    'og_title', 'og_description', 'og_image_path', 'og_type',
                    'twitter_card', 'noindex', 'nofollow', 'robots', 'schema_type', 'metadata',
                ]))
            );

            if ($existing !== null && $force) {
                $existing->update($payload);
            } else {
                SeoMeta::create($payload);
            }

            $report['seo']['applied'][] = $locale;
        }
    }

    /** 安装期 locale map 取默认语言（中性默认）。 */
    private static function neutral(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value[LocaleRegistry::default()] ?? reset($value) ?? '');
        }

        return (string) $value;
    }

    /** 值是否为空（null / 空字符串 / 空数组）。 */
    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }
}
