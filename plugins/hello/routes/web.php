<?php

/*
 * Hello 示例插件路由（P-STEP 14 / D.3：注册 / 授权分离）
 *
 * 本文件只“声明”路由（限定在插件自身前缀 plugins/hello 内），不挂任何启用判断、
 * 不感知站点、不修改 Core。Core 在路由加载期统一发现本文件并：
 *     1) 套入 web 中间件组（含 ResolveSite，先解析当前站点）；
 *   2) 追加 per-site EnsurePluginEnabled:{slug} 守卫，由当前站点启用态决定可访问性。
 * 因此插件作者只需声明路由，启用 / 停用与多站隔离由 Core 保证。
 */

use Illuminate\Support\Facades\Route;

Route::prefix('plugins/hello')->group(function () {
    Route::get('ping', function () {
        return response()->json([
            'plugin' => 'hello',
            'message' => 'pong from example plugin',
            'time' => now()->toIso8601String(),
        ]);
    })->name('plugin.hello.ping');
});
