<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

/**
 * 站点上下文（SiteContext）
 *
 * 每次请求/任务/测试运行时，系统通过 SiteContext 知道"当前是哪一个 Site"。
 * Site-scoped Model 的 Global Scope 自动从此处读取 site_id 进行查询隔离。
 *
 * 生命周期：
 * - HTTP：SiteResolverMiddleware 根据域名设置 SiteContext
 * - CLI / 迁移 / Seeder：默认绑定 default site
 * - 测试：测试基类或 setUp() 中显式设置
 * - Queue / Job：任务分发时序列化 site_id，执行时恢复
 *
 * 安全原则：
 * - 默认未设置时绑定 default site（id=1），保证单站点兼容
 * - Admin / 系统维护操作必须显式使用 withoutSiteScope()
 * - 禁止业务代码自行猜测或硬编码 site_id
 */
class SiteContext
{
    private static ?Site $currentSite = null;

    /**
     * 设置当前站点
     */
    public static function setSite(Site $site): void
    {
        self::$currentSite = $site;
    }

    /**
     * 获取当前站点，未设置时返回 default site
     */
    public static function currentSite(): ?Site
    {
        if (self::$currentSite !== null) {
            return self::$currentSite;
        }

        // 未显式设置时自动绑定 default site（单站点兼容）
        // sites 表不存在时（如早期 migration）返回 null，避免 SQL 错误
        if (Schema::hasTable('sites')) {
            $default = Site::where('slug', Site::DEFAULT_SLUG)->first();
            if ($default) {
                self::$currentSite = $default;
            }
        }

        return self::$currentSite;
    }

    /**
     * 获取当前站点 ID，未设置时返回 default site id
     */
    public static function currentSiteId(): int
    {
        $site = self::currentSite();
        return $site?->id ?? 1;
    }

    /**
     * 是否显式设置了站点
     */
    public static function hasSite(): bool
    {
        return self::$currentSite !== null;
    }

    /**
     * 清除当前站点上下文（测试间隔离、请求结束）
     */
    public static function clear(): void
    {
        self::$currentSite = null;
    }

    /**
     * 临时执行一个站点上下文下的操作（用于测试 / Admin / CLI / Job 嵌套切换站点）。
     *
     * P-STEP 14 / D.4：切换站点不仅是替换 currentSite，还必须复位所有“按当前站点解析、
     * 以 static 记忆”的请求级状态，否则同进程内会读到上一站点缓存的 Setting / Catalog /
     * SeoMeta / Theme / Plugin 等（state leakage）。进入目标站点后 reapply() 一次，
     * 退出（finally）恢复外层站点后再 reapply() 一次，保证嵌套 A{B{A}} 与 CLI / Queue /
     * Admin 内嵌场景下上下文都能正确重建。reapply 内 register 幂等且对未就绪环境兜底。
     */
    public static function withSite(Site $site, callable $callback): mixed
    {
        $previous = self::$currentSite;
        self::setSite($site);
        RequestScopedState::reapply();

        try {
            return $callback();
        } finally {
            self::$currentSite = $previous;
            RequestScopedState::reapply();
        }
    }
}
