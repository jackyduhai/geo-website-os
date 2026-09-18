<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * CliSiteContext — CLI 命令的站点上下文管理
 *
 * CLI 安全原则：
 * - 不允许隐式使用 default site（防止串站）
 * - 必须显式指定 --site 或 --site-id
 * - 未指定时如果有 default site 则使用，但会输出警告
 * - 不允许静默切站
 */
class CliSiteContext
{
    /**
     * 为 CLI 命令设置站点上下文
     */
    public static function resolve(Command $command): ?Site
    {
        // 1. 显式 --site 参数（slug）
        if ($command->option('site')) {
            $site = SiteResolver::findBySlug($command->option('site'));
            if ($site) {
                SiteContext::setSite($site);
                return $site;
            }
            $command->error("Site not found: " . $command->option('site'));
            return null;
        }

        // 2. 显式 --site-id 参数（id）
        if ($command->option('site-id')) {
            $site = self::findById((int) $command->option('site-id'));
            if ($site) {
                SiteContext::setSite($site);
                return $site;
            }
            $command->error("Site not found with id: " . $command->option('site-id'));
            return null;
        }

        // 3. 未指定：使用 default site，但输出警告
        $default = self::default();
        if ($default) {
            SiteContext::setSite($default);
            $command->warn("No --site specified, using default site: {$default->slug} (id={$default->id})");
            return $default;
        }

        $command->error("No default site configured. Use --site or --site-id to specify.");
        return null;
    }

    /**
     * 按 ID 查找站点
     */
    protected static function findById(int $id): ?Site
    {
        if (!Schema::hasTable('sites')) {
            return null;
        }

        return Site::where('id', $id)
            ->where('status', 'active')
            ->first();
    }

    /**
     * 获取 default site
     */
    protected static function default(): ?Site
    {
        if (!Schema::hasTable('sites')) {
            return null;
        }

        return Site::where('is_default', true)
            ->where('status', 'active')
            ->first();
    }
}
