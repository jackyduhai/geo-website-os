<?php

namespace App\Support;

use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Support\Facades\Schema;

/**
 * Site-aware Cache Key 统一抽象
 *
 * 所有 Site-scoped 缓存必须通过此类生成 key，禁止业务代码自行拼接。
 * 当前单站点模式下自动使用 default site id；多站点阶段由 SiteContext 注入。
 *
 * 原则：
 * - Site A 和 Site B 永远不会产生相同的 Site-scoped Cache Key
 * - System-scoped 缓存（如 PageCache file store 按 URL）不经过此类
 * - 禁止使用企业名称（如 example）作为 Cache Key 的一部分
 */
class SiteCacheKey
{
    /**
     * 记忆化仅用于「无显式上下文时的 default 回退」（该值进程内不变）；
     * 显式 SiteContext 直取其 id——同进程切站（admin/CLI/测试）必须立即生效
     * （P-STEP 10 修复：原实现缓存首个解析结果，切站后 key 串站）。
     */
    private static ?int $defaultIdMemo = null;

    /**
     * 获取当前站点 ID（单站点模式下为 default site）
     */
    public static function currentSiteId(): int
    {
        $site = SiteContext::currentSite();
        if ($site !== null) {
            return $site->id;
        }

        if (self::$defaultIdMemo !== null) {
            return self::$defaultIdMemo;
        }

        $id = 1;
        if (Schema::hasTable('sites')) {
            $id = Site::defaultId() ?? 1;
        }

        return self::$defaultIdMemo = $id;
    }

    /** 请求级记忆化复位（AppServiceProvider::boot 调用） */
    public static function resetRequestMemo(): void
    {
        self::$defaultIdMemo = null;
    }

    /**
     * 生成 Site-scoped Cache Key
     *
     * @param string $namespace 缓存命名空间（settings/nav/menu/footer/redirects/pagecache）
     * @param string $suffix 可选后缀
     * @param int|null $siteId 可选指定站点 ID（默认使用当前站点）
     */
    public static function make(string $namespace, string $suffix = '', ?int $siteId = null): string
    {
        $siteId = $siteId ?? self::currentSiteId();
        $key = "site:{$siteId}:{$namespace}";
        if ($suffix !== '') {
            $key .= ":{$suffix}";
        }
        return $key;
    }

    /** Setting 全量缓存 */
    public static function settings(): string
    {
        return self::make('settings');
    }

    /** 导航树 */
    public static function navTree(): string
    {
        return self::make('nav', 'tree');
    }

    /** 主导航蓝图 */
    public static function mainMenuBlueprint(): string
    {
        return self::make('menu', 'main.blueprint');
    }

    /** 主导航 */
    public static function mainMenu(): string
    {
        return self::make('menu', 'main');
    }

    /** 页脚蓝图 */
    public static function footerBlueprint(): string
    {
        return self::make('footer', 'blueprint');
    }

    /** 页脚菜单 */
    public static function footerMenu(): string
    {
        return self::make('footer', 'menu');
    }

    /** 页脚附加信息 */
    public static function footerExtra(): string
    {
        return self::make('footer', 'extra');
    }

    /** 激活的重定向规则 */
    public static function redirectsActive(): string
    {
        return self::make('redirects', 'active');
    }

    /** PageCache 版本号 */
    public static function pagecacheVersion(): string
    {
        return self::make('pagecache', 'version');
    }

    /**
     * 获取旧版全局 Key 列表（用于兼容性清理）
     * 这些 Key 在迁移后不再使用，需要主动 forget
     */
    public static function legacyKeys(): array
    {
        return [
            'example.settings',
            'nav.tree',
            'main.menu.blueprint',
            'main.menu',
            'footer.blueprint',
            'footer.menu',
            'footer.extra',
            'redirects.active',
            'pagecache:version',
        ];
    }
}
