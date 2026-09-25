<?php

namespace App\Support;

use App\Models\Fact;
use App\Models\Group;
use App\Models\Setting;
use App\Models\Scopes\SiteScope;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Plugins\PluginManager;
use App\Support\Templates\TemplatePackageManager;
use App\Support\Theme\ThemeManager;

/**
 * 请求级状态复位器（RequestScopedState）
 *
 * 集中管理所有「按当前站点解析、且以 static 属性做进程内记忆」的请求级状态，
 * 提供唯一的复位 / 重放入口，供三处复用：
 *   - AppServiceProvider::boot()：进程启动、测试每个用例重建应用时；
 *   - ResolveSite 中间件：HTTP 请求解析出真实站点之后（关键，boot 阶段站点可能仍是 default）；
 *   - QueuedBySite：常驻 queue worker 恢复 Job 站点上下文之后。
 *
 * 必要性：PHP-FPM 每请求重建进程时这些 static 天然归零；但 boot 阶段（中间件之前）
 * 主题初始化会按 default 站预热 Setting 等 memo，且 Octane / 同进程连续请求 /
 * 常驻 worker 会复用 static 属性。若不在「站点确定后」统一复位，就会出现：
 * default 站或 A 站的设置 / 导航 / 事实 / 主题快照被 B 站请求短路读到（跨站脏读）。
 *
 * 注意：插件 ServiceProvider 的注册在 Laravel 中不可逆（无法在请求间卸载已 boot 的 provider），
 * reapply() 只能保证「当前站启用的插件被注册、isEnabled() 按当前站判断」；
 * 上一站已注册的 provider 路由在常驻进程内仍会保留，这是常驻运行时的已知限制，
 * PHP-FPM（每请求重建进程）下不存在该问题。
 */
class RequestScopedState
{
    /**
     * 额外的复位回调（如 AppServiceProvider 的导航 / 页脚视图记忆）。
     * 回调必须幂等；进程内只注册一次，flushAll() 每次执行。
     *
     * @var callable[]
     */
    private static array $resetCallbacks = [];

    /** 注册一个在每次复位时执行的回调（幂等注册由调用方保证） */
    public static function onReset(callable $callback): void
    {
        self::$resetCallbacks[] = $callback;
    }

    /**
     * 清空全部请求级 static 记忆（不重放主题 / 插件注册）。
     */
    public static function flushAll(): void
    {
        // —— 数据型（按当前站点解析）——
        Fact::flushMemo();
        Setting::resetRequestMemo();
        Group::flushKnowledgeMemo();
        Narrative::flush();
        Copy::flush();
        Catalog::flush();

        // —— 结构 / 解析型记忆 ——
        SiteScope::resetRequestMemo();
        SiteCacheKey::resetRequestMemo();
        SeoMetaResolver::resetRequestMemo();

        // —— 主题 / 插件激活态（register 不可逆，仅清记忆，重放交由 reapply）——
        ThemeManager::resetRequestMemo();
        PluginManager::resetRequestMemo();
        TemplatePackageManager::resetRequestMemo();

        foreach (self::$resetCallbacks as $callback) {
            $callback();
        }
    }

    /**
     * 按「当前已解析站点」复位全部请求级记忆，并重放主题 / 插件注册。
     * 必须在 SiteContext 已设置为目标站点之后调用。
     */
    public static function reapply(): void
    {
        self::flushAll();

        // register() 均幂等、可重入，且内部对环境未就绪做了安全兜底。
        ThemeManager::register();
        PluginManager::register();
        TemplatePackageManager::register();
    }
}
