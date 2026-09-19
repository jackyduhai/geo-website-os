<?php

namespace App\Support;

use App\Models\User;

/**
 * SystemAuthorization — 系统级操作的权限边界
 *
 * 定义谁可以使用 withoutSiteScope() 等跨站访问能力。
 *
 * 安全原则：
 * - 普通业务代码不能绕过 Site Scope
 * - 只有 Admin / System 角色才能 bypass
 * - 不允许通过环境变量/全局开关关闭 Scope
 */
class SystemAuthorization
{
    /**
     * 判断当前用户是否有权限跨站访问
     */
    public static function canCrossSite(?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (!$user) {
            return false;
        }

        // Admin 角色允许跨站
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        // 超级管理员允许跨站
        if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return true;
        }

        // 安装器（geo:install）创建的首个管理员：显式超管标记（P-STEP 03）。
        // 取代原「按业务种子邮箱硬编码判定」——超管身份属于用户数据，不属于代码。
        if (! empty($user->is_super_admin)) {
            return true;
        }

        return false;
    }

    /**
     * 判断当前是否处于系统上下文（CLI / Queue / 安装器）
     */
    public static function isSystemContext(): bool
    {
        // CLI 环境
        if (app()->runningInConsole()) {
            return true;
        }

        // Queue 环境
        if (app()->runningQueue()) {
            return true;
        }

        return false;
    }

    /**
     * 判断当前是否允许使用 withoutSiteScope()
     */
    public static function canUseSiteScopeBypass(): bool
    {
        // 系统上下文自动允许
        if (self::isSystemContext()) {
            return true;
        }

        // Web 请求：只有 Admin 允许
        return self::canCrossSite();
    }
}
