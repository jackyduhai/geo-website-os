<?php

namespace Plugin\Hello\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 示例插件服务提供者（P-STEP 06 扩展契约验证对象；P-STEP 14 / D.3 调整）。
 *
 * 边界：
 *   - 路由不再在本 Provider 注册，改由 plugins/hello/routes/web.php 声明，Core 在
 *     路由加载期统一发现并挂 per-site EnsurePluginEnabled 守卫（注册 / 授权分离）；
 *   - Provider 仅用于注册插件自有的视图 / 配置 / 迁移等“非路由”资源；示例插件无需；
 *   - 不修改 Core、不写 Core 的 config/routes 文件。
 */
class HelloServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 非路由资源的注册位（示例插件 intentionally 留空）。
    }
}
