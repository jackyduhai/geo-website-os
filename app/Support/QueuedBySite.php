<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

/**
 * QueuedSiteContext — 队列任务的站点上下文恢复
 *
 * Queue 安全原则：
 * - Job 分发时序列化 site_id
 * - 执行时恢复 SiteContext
 * - 执行结束（包括异常）清理 SiteContext
 * - 不允许 Job 静默使用 default site
 */
trait QueuedBySite
{
    /**
     * 分发前记录当前 site_id
     */
    public function __construct()
    {
        $this->siteId = SiteContext::currentSiteId();
    }

    /**
     * 执行前恢复 SiteContext
     */
    public function restoreSiteContext(): void
    {
        if ($this->siteId && Schema::hasTable('sites')) {
            $site = Site::find($this->siteId);
            if ($site && $site->status === 'active') {
                SiteContext::setSite($site);
                // 常驻 queue worker 会连续处理不同站点的 Job：切站后必须复位请求级记忆并重放
                // 主题 / 插件，否则上一个 Job 站点的设置 / 导航 / 主题 static 快照会被本站短路读到。
                RequestScopedState::reapply();
                return;
            }
        }

        // 无 site_id 或站点不存在：使用 default，但记录警告
        $default = Site::where('is_default', true)->where('status', 'active')->first();
        if ($default) {
            SiteContext::setSite($default);
            RequestScopedState::reapply();
            logger()->warning('Job executing without site context, falling back to default site', [
                'job' => static::class,
                'site_id' => $this->siteId ?? null,
            ]);
        }
    }

    /**
     * 执行后清理 SiteContext
     */
    public function clearSiteContext(): void
    {
        SiteContext::clear();
    }
}
