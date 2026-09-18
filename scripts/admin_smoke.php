<?php
/**
 * 后台全路由渲染冒烟（开发辅助）：
 * 以管理员身份逐页 GET 所有 admin GET 路由，报告非 200 与异常摘要。
 * 用法：php scripts/admin_smoke.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->first();
if (! $user) {
    fwrite(STDERR, "no user\n");
    exit(1);
}

$narrativeKey = null;
foreach (App\Support\Narrative::registry() as $g) {
    if (! empty($g['items'][0]['key'])) {
        $narrativeKey = $g['items'][0]['key'];
        break;
    }
}

$routes = Illuminate\Support\Facades\Route::getRoutes();
$bad = 0;
$ok = 0;
$skip = 0;

foreach ($routes as $route) {
    $name = $route->getName() ?? '';
    if (! str_starts_with($name, 'admin.') || ! in_array('GET', $route->methods(), true)) {
        continue;
    }
    $uri = $route->uri();
    if (str_ends_with($uri, 'login')) {
        continue;
    }

    $params = [];
    $missing = false;
    foreach ($route->parameterNames() as $pn) {
        $val = match ($pn) {
            'content'  => App\Models\Content::query()->first(),
            'category' => App\Models\Category::query()->first(),
            'block'    => App\Models\PageBlock::query()->first(),
            'media'    => App\Models\Media::query()->first(),
            'fact'     => App\Models\Fact::query()->first(),
            'inquiry'  => App\Models\Inquiry::query()->first(),
            'menu'     => App\Models\Menu::query()->first(),
            'redirect' => App\Models\Redirect::query()->first(),
            'group'    => 'general',
            'kind'     => 'sitemap',
            'type'     => 'article',
            'tab'      => 'all',
            'key'      => str_contains($uri, 'narrative') ? $narrativeKey : 'products',
            default    => null,
        };
        if (is_object($val)) {
            $val = $val->getRouteKey();
        }
        if ($val === null) {
            $missing = true;
            break;
        }
        $params[$pn] = $val;
    }
    if ($missing) {
        echo "SKIP  $name  $uri\n";
        $skip++;
        continue;
    }

    $url = route($name, $params, false);
    try {
        Illuminate\Support\Facades\Auth::login($user);
        $request = Illuminate\Http\Request::create($url, 'GET');
        $request->setUserResolver(fn () => $user);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        if ($status === 200) {
            echo "OK    $status  $name\n";
            $ok++;
        } else {
            echo "BAD   $status  $name  $url\n";
            $bad++;
        }
    } catch (\Throwable $e) {
        echo "ERR   500  $name  $url\n      " . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $bad++;
    }
}

echo "\n== admin smoke: ok=$ok bad=$bad skip=$skip ==\n";
exit($bad > 0 ? 1 : 0);
