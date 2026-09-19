<?php

namespace Plugin\Hello\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 示例插件服务提供者（P-STEP 06 扩展契约验证对象）。
 *
 * 边界：只在自身路由前缀 /plugins/hello 下注册路由；不修改 Core、
 * 不写 Core 的 config/routes 文件；停用后路由不再注册，Core 不受影响。
 */
class HelloServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('plugins/hello')->group(function () {
            Route::get('ping', function () {
                return response()->json([
                    'plugin' => 'hello',
                    'message' => 'pong from example plugin',
                    'time' => now()->toIso8601String(),
                ]);
            })->name('plugin.hello.ping');
        });
    }
}
