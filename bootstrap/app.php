<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // 后台路由：统一前缀 admin，独立命名空间
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            // GEOFlow 对接 API：统一前缀 api/v1
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));
        },
    )
    // 自动注册 app/Console/Commands（如 page-cache:clear）
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\EnsureAdmin::class,
            'geoflow.token' => \App\Http\Middleware\VerifyGeoflowToken::class,
        ]);

        // 后台鉴权先于路由模型绑定：未登录访问不存在的后台资源 ID 时返回 302 登录，
        // 而不是先暴露 404（避免未授权者枚举后台资源 ID 是否存在）。
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureAdmin::class,
        );

        // SecurityHeaders 必须最外层（prepend）：无论内层是鉴权 302、旧链 301，
        // 还是 CachePage 命中(HIT)/未命中(MISS)/304/BYPASS，响应回程都统一补安全头；
        // 鉴权先于模型绑定后，未登录访问后台的 302 跳转同样必须带 CSP。
        $middleware->web(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // 其余自定义中间件按洋葱圈顺序追加：旧链 301/302 先跳转（不入静态化、
        // 不做尾斜杠规范化），归因再写入会话，CanonicalizeSlash 规范化 URL，
        // CachePage 在最内层做整页静态化。
        $middleware->web(append: [
            \App\Http\Middleware\HandleRedirects::class,
            \App\Http\Middleware\CaptureAttribution::class,
            \App\Http\Middleware\CanonicalizeSlash::class,
            \App\Http\Middleware\CachePage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
