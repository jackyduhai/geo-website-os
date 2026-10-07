<?php

namespace App\Support\Templates;

use App\Models\Site;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\BlankHomepageSeeder;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SiteStructureSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 模板预览站（RC-11 G）。
 *
 * 为什么需要：模板包是**声明式结构**，激活后 recipe 才会落地成真实区块。
 * 不写库就无法在浏览器里看到「这个模板跑起来是什么样」——
 * 这也是 `preview/*.webp` 只能由模板作者随包提供截图的原因。
 *
 * 为什么必须是**独立站**：
 *   `bootstrap()` 会改Setting / Menu / SeoMeta，**无自动回滚**。
 *   在正在使用的站点上「激活 → 看 → 回滚」等于把生产数据当实验田。
 *   预览站用自己的 site_id，settings / menus / seo / template 全部独立，
 *   用完整体删除即可，**真实站点零风险**。
 *
 * 这同时是多站架构能力的一次真实应用：预览站本身就是「一个站点」。
 */
final class TemplatePreviewSite
{
    /** 预览站 slug 固定前缀，便于识别与批量清理 */
    public const SLUG_PREFIX = 'tpl-preview-';

    /**
     * 当前请求正在预览的 pack（由预览 URL 的查询参数携带）。
     *
     * 预览站不常驻，靠 ?pack= 告诉前台「这个站是为哪个包建的」。
     */
    public static function currentPack(): ?string
    {
        $site = SiteContext::currentSite();
        if ($site === null || ! self::isPreviewSlug((string) $site->slug)) {
            return null;
        }

        $pack = substr((string) $site->slug, strlen(self::SLUG_PREFIX));

        return TemplatePackageManager::exists($pack) ? $pack : null;
    }

    /**
     * 预览站的访问 URL。
     *
     * 前台站点解析走 domain，但模板预览站不绑域名（部署侧无法为每个 pack 配 DNS），
     * 因此复用 query slug 通道 —— `SiteResolver` 只认`tpl-preview-` 前缀，
     * 不会因此开放任意切站。
     */
    public static function url(string $pack): string
    {
        return url('/?site_slug=' . rawurlencode(self::SLUG_PREFIX . $pack));
    }

    /**
     * 该slug 是否为预览站。
     */
    public static function isPreviewSlug(string $slug): bool
    {
        return str_starts_with($slug, self::SLUG_PREFIX);
    }

    /**
     * 为某个模板包创建（或复用）一个预览站。
     *
     * 幂等：同一 pack 重复调用返回既有站点，不重复建站。
     */
    public static function ensure(string $pack): ?Site
    {
        if (! TemplatePackageManager::exists($pack)) {
            return null;
        }

        $slug = self::SLUG_PREFIX . $pack;

        $existing = Site::withoutGlobalScopes()->where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($pack, $slug): Site {
            $site = Site::create([
                'name'       => '〔预览〕' . self::packLabel($pack),
                'slug'       => $slug,
                'domain'     => null,          // 预览站只经slug 访问，不绑域名
                'status'     => Site::STATUS_ACTIVE,
                'is_default' => false,          // 绝不能成为默认站
                'description'=> '模板「' . self::packLabel($pack) . '」的隔离预览站，可随时删除。',
            ]);

            // 与 SiteController::store() 同一套骨架，保证预览站与真实站结构一致
            SiteContext::withSite($site, function () use ($site): void {
                (new DefaultSettingSeeder())->run();
                (new DefaultFormSeeder())->run();
                (new BlankHomepageSeeder($site))->run();
                (new SystemPageSeeder($site))->run();
                (new SiteStructureSeeder())->run();
            });

            // 把模板默认值装到这个预览站（只写该站，不碰其它站）
            SiteContext::withSite($site, static function () use ($pack): void {
                TemplateDefaultsInstaller::bootstrap(SiteContext::currentSite(), $pack, false);
            });

            return $site;
        });
    }

    /**
     * 回收预览站：连同该站全部数据一起删除。
     *
     * 与 SiteController::destroy() 的区别：后者**拒绝**删除有数据的站点
     * （业务表必须先清空），而预览站天生带一整套骨架数据，
     * 所以这里必须自己级联清理 —— 这是预览站可回收的前提。
     *
     * 只回收带预览前缀的站，绝不误删真实站。
     */
    public static function discard(Site $site): void
    {
        if (! self::isPreviewSlug((string) $site->slug)) {
            return;                 // 保险：非预览站一律不碰
        }

        DB::transaction(function () use ($site): void {
            // 动态发现「带 site_id 的表」而不是硬编码表名 ——
            // 与 SiteController::resourceCounts() 同一口径，
            // 将来新增业务表无需改动这里。
            foreach (Schema::getTableListing() as $listed) {
                $table = Str::afterLast($listed, '.');

                if ($table === 'sites' || $table === 'settings') {
                    continue;       // settings 随站点最后处理（FK RESTRICT）
                }
                if (! Schema::hasColumn($table, 'site_id')) {
                    continue;
                }

                DB::table($table)->where('site_id', $site->id)->delete();
            }

            DB::table('settings')->where('site_id', $site->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
        });

        \App\Models\Setting::flush();
        PageCache::flush();
    }

    /**
     * 模板的可读名（manifest.name 优先）。
     */
    public static function packLabel(string $pack): string
    {
        $all = TemplatePackageManager::all();

        return (string) ($all[$pack]['name'] ?? $pack);
    }
}
