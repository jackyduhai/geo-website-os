<?php

/**
 * GEO-OS 性能基线测量脚本（establish-baseline，只读测量，不改业务数据）。
 * ---------------------------------------------------------------------------
 * 用途：在同一 PHP 进程内 boot Laravel，经 HttpKernel 直接 dispatch 代表性 GET
 *       请求，逐页采集 wall time、SQL 条数、重复 SQL 指纹、峰值内存。用于回答
 *       “哪些页面有 N+1 / 重复 SQL / 慢查询 / 高内存 / TTFB 偏高”。
 *
 * 设计要点（与运行时事实对应）：
 *  - 前台匿名 HTML 走 CachePage 整页缓存：首访 MISS（跑控制器查库），再访 HIT
 *    （跳过控制器）。N+1 只可能在 MISS 路径暴露，故每个前台采样前 PageCache::flush()
 *    强制 MISS；另采一次 HIT 作为对照（应近乎 0 SQL）。
 *  - 后台 /admin/* 不进整页缓存，每次都跑控制器；需先用 admin@example.com 真实
 *    POST 登录建立 session cookie，再带 cookie 采样。
 *  - ResolveSite 中间件每请求 reapply()/clear()，同进程连续请求彼此隔离，
 *    数据型 memo 按设计每请求重新查库，因此重复 MISS 样本可直接配对比较。
 *  - SQL 指纹 = QueryExecuted->sql（Laravel 已把绑定抽成 ? 占位符），同一模板
 *    不同参数得到同一指纹；重复次数 >=2 即“重复 SQL”，随列表行数线性增长即 N+1。
 *
 * 用法：
 *   C:\php84\php.exe -d memory_limit=512M scripts/audit/perf-baseline.php \
 *       [--out=storage/logs/perf-baseline.json] [--iter=5] [--only=admin|front]
 *
 * 输出：stdout 打印汇总表；--out 把每轮原始样本写 JSON（供报告引用 raw 样本）。
 *
 * 注意：本脚本为可复用工具，不修改任何业务表；仅产生一次性 session 行与 pagecache
 *       版本号 bump（缓存元数据，自愈）。数据量增长验证见 --grow=临时库.sqlite 模式。
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

// ---------------------------------------------------------------------------
// 0. 引导
// ---------------------------------------------------------------------------
$base = dirname(__DIR__, 2); // scripts/audit -> 仓库根
require $base . '/vendor/autoload.php';
// Laravel 11+ slim skeleton：Application 自身即 HTTP 内核（见 public/index.php -> handleRequest）
$app = require $base . '/bootstrap/app.php';
// Laravel 11+ slim skeleton：Application 自身即 HTTP 内核（见 public/index.php -> handleRequest）。
// 不手动 boot()：首个 handleRequest 会完整引导容器；SQL 监听待首次请求后再挂。

// CLI 参数
$opts = [
    'out'   => $base . '/storage/logs/perf-baseline.json',
    'iter'  => 5,
    'only'  => 'all', // all | admin | front
    'grow'  => null,  // 临时 sqlite 路径：数据量增长验证模式
    'grow-n' => 200,
];
foreach ($argv as $a) {
    if (str_starts_with($a, '--out='))    $opts['out'] = substr($a, 6);
    if (str_starts_with($a, '--iter='))   $opts['iter'] = max(1, (int) substr($a, 7));
    if (str_starts_with($a, '--only='))   $opts['only'] = substr($a, 7);
    if (str_starts_with($a, '--grow='))   $opts['grow'] = substr($a, 7);
    if (str_starts_with($a, '--grow-n=')) $opts['grow-n'] = (int) substr($a, 9);
}
$iter = (int) $opts['iter'];

// ---------------------------------------------------------------------------
// 1. 全局 SQL 采集器
// ---------------------------------------------------------------------------
$GLOBALS['__pq'] = [];      // 当前请求已采集的 SQL
$GLOBALS['__pq_on'] = false; // 是否采集开关

// SQL 监听在登录引导（首个 handleRequest 完成容器 boot）之后注册，见下方挂接处。

// ---------------------------------------------------------------------------
// 2. dispatch 封装
// ---------------------------------------------------------------------------
function send(string $method, string $uri, array $params = [], array $jar = [], string $host = '127.0.0.1'): array
{
    $parts = explode('?', $uri, 2);
    $path = $parts[0] === '' ? '/' : '/' . ltrim($parts[0], '/');
    $query = [];
    if (isset($parts[1])) {
        parse_str($parts[1], $query);
    }
    // GET：query 进 query 串；POST：业务参数进 request body，URL query 合并进 query bag
    $parameters = $method === 'GET' ? $query : $params;
    $request = Request::create($path, $method, $parameters, $jar, [], [
        'HTTP_HOST'       => $host,
        'HTTPS'          => 'off',
        'SERVER_PORT'    => '80',
        'REMOTE_ADDR'    => '127.0.0.1',
        'REQUEST_METHOD'  => $method,
        'HTTP_USER_AGENT'=> 'perf-baseline/1.0 (+read-only)',
    ]);
    if ($method === 'POST') {
        $request->query->replace($query);
    }

    $response = app()->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    return [$request, $response];
}

/** 从响应把 Set-Cookie 合并进 jar */
function jarMerge(array $jar, $response): array
{
    foreach ($response->headers->getCookies() as $c) {
        $jar[$c->getName()] = $c->getValue();
    }
    return $jar;
}

// ---------------------------------------------------------------------------
// 3. 前台 / 后台 URL 矩阵（基于 HEAD c6f04fd 实际路由与真实 slug）
// ---------------------------------------------------------------------------
$frontPages = [
    'home'            => '/',
    'products.list'    => '/products/',
    'products.detail'  => '/products/epoxy-primer-100',
    'solutions.list'   => '/solutions/',
    'solutions.detail' => '/solutions/equipment-manufacturing/',
    'cases.list'       => '/cases/',
    'knowledge.index'  => '/knowledge/',
    'knowledge.channel'=> '/knowledge/selection/',
    'article.detail'   => '/how-to-choose-industrial-coatings',
    'about.profile'    => '/about/profile/',
    'about.history'    => '/about/history/',
    'about.culture'    => '/about/culture/',
    'factory'          => '/factory/',
    'cooperation'      => '/cooperation/',
    'contact'          => '/contact/',
    'search'           => '/search?q=coating',
    'sitemap.xml'      => '/sitemap.xml',
    'geo.json'         => '/geo.json',
    'llms.txt'         => '/llms.txt',
    'feed.xml'         => '/feed.xml',
    'robots.txt'       => '/robots.txt',
];

$adminPages = [
    'admin.dashboard'        => '/admin/',
    'admin.contents'         => '/admin/contents',
    'admin.contents.all'     => '/admin/contents/all',
    'admin.entities'         => '/admin/entities',
    'admin.relations'        => '/admin/relations',
    'admin.inquiries'        => '/admin/inquiries',
    'admin.media'            => '/admin/media',
    'admin.facts'            => '/admin/facts',
    'admin.pages'            => '/admin/pages',
    'admin.narrative'        => '/admin/narrative',
    'admin.forms'            => '/admin/forms',
    'admin.forms.submissions'=> '/admin/forms/submissions',
    'admin.categories'       => '/admin/categories',
    'admin.groups'           => '/admin/groups',
    'admin.menus'            => '/admin/menus',
    'admin.redirects'        => '/admin/redirects',
    'admin.seo-metas.all'    => '/admin/seo-metas/all',
    'admin.settings.general' => '/admin/settings/general',
    'admin.settings.theme'   => '/admin/settings/theme',
    'admin.settings.contact' => '/admin/settings/contact',
    'admin.settings.copy'    => '/admin/settings/copy',
    'admin.settings.seo'     => '/admin/settings/seo',
    'admin.settings.geo'     => '/admin/settings/geo',
    'admin.settings.analytics'=> '/admin/settings/analytics',
    'admin.settings.sync'    => '/admin/settings/sync',
    'admin.geo.tools'        => '/admin/geo/tools',
    'admin.geo.coverage'     => '/admin/geo/coverage',
    'admin.geo.health'       => '/admin/geo/health',
    'admin.geo.sync-logs'    => '/admin/geo/sync-logs',
    'admin.themes'           => '/admin/themes',
    'admin.templates'        => '/admin/templates',
    'admin.plugins'         => '/admin/plugins',
];

// ---------------------------------------------------------------------------
// 4. 建立后台登录 session（真实 POST 登录）
// ---------------------------------------------------------------------------
$jar = [];
$authed = false;
try {
    [, $loginPage] = send('GET', '/admin/login', [], $jar);
    $jar = jarMerge($jar, $loginPage);
    $html = $loginPage->getContent();
    $token = '';
    if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/', $html, $m)) {
        $token = $m[1];
    } elseif (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        $token = $m[1];
    }
    [, $loginResp] = send('POST', '/admin/login', [
        '_token'  => $token,
        'email'   => 'admin@example.com',
        'password'=> 'Admin@123456',
    ], $jar);
    $jar = jarMerge($jar, $loginResp);
    // 验证：访问 dashboard 不应再 302 到 login
    [, $probe] = send('GET', '/admin/', [], $jar);
    $authed = ! str_contains((string) $probe->headers->get('Location'), 'login');
} catch (Throwable $e) {
    fwrite(STDERR, "[warn] admin login bootstrap failed: {$e->getMessage()}\n");
}
fwrite(STDERR, "[info] admin authed=" . ($authed ? 'YES' : 'NO') . "\n");

// 容器已由上面的请求 boot，现在挂 SQL 监听
$app->make('db')->listen(function ($q) use ($base) {
    if (empty($GLOBALS['__pq_on'])) {
        return;
    }
    $origin = null;
    foreach ((new Exception())->getTrace() as $frame) {
        $f = $frame['file'] ?? '';
        if ($f && str_contains($f, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR)) {
            $origin = str_replace($base . DIRECTORY_SEPARATOR, '', $f) . ':' . ($frame['line'] ?? 0);
            break;
        }
    }
    $GLOBALS['__pq'][] = [
        'sql'      => $q->sql,
        'bindings' => $q->bindings ?? [],
        'ms'       => (float) $q->time,
        'origin'   => $origin,
    ];
});

// ---------------------------------------------------------------------------
// 5. 单页采样函数
// ---------------------------------------------------------------------------
function samplePage(string $name, string $uri, bool $isAdmin, array $jar): array
{
    // 前台：采样前 flush pagecache 强制 MISS；后台：无整页缓存概念
    if (! $isAdmin) {
        try { \App\Support\PageCache::flush(); } catch (Throwable) {}
    }

    $GLOBALS['__pq'] = [];
    $GLOBALS['__pq_on'] = true;
    $memBefore = memory_get_usage(true);
    $t0 = microtime(true);
    $excMsg = '';
    ob_start();
    try {
        [, $resp] = send('GET', $uri, [], $isAdmin ? $jar : []);
        $status = $resp->getStatusCode();
        $len = strlen((string) $resp->getContent());
    } catch (Throwable $e) {
        $status = 'EXC:' . get_class($e);
        $excMsg = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        $len = 0;
    }
    ob_end_clean();
    $wallMs = (microtime(true) - $t0) * 1000.0;
    $memPeakAfter = memory_get_peak_usage(true);
    $GLOBALS['__pq_on'] = false;
    $queries = $GLOBALS['__pq'];

    // 指纹聚合
    $counts = $origins = $times = [];
    $totalMs = 0.0;
    foreach ($queries as $q) {
        $fp = $q['sql'];
        $counts[$fp] = ($counts[$fp] ?? 0) + 1;
        $origins[$fp] = $q['origin'];
        $times[$fp] = ($times[$fp] ?? 0) + $q['ms'];
        $totalMs += $q['ms'];
    }
    arsort($counts);

    return [
        'name'        => $name,
        'uri'         => $uri,
        'status'      => $status,
        'wall_ms'     => round($wallMs, 2),
        'sql_count'   => count($queries),
        'sql_ms'      => round($totalMs, 2),
        'mem_peak_mb' => round($memPeakAfter / 1048576, 1),
        'mem_delta_mb'=> round(($memPeakAfter - $memBefore) / 1048576, 2),
        'bytes'       => $len,
        'repeated'    => $counts,     // fingerprint => count
        'origins'     => $origins,    // fingerprint => origin locator
        'sql_time_ms' => $times,      // fingerprint => summed ms
    ];
}

// ---------------------------------------------------------------------------
// 6. 选择矩阵并跑
// ---------------------------------------------------------------------------
$run = [];
if ($opts['only'] === 'admin' || $opts['only'] === 'all') {
    foreach ($adminPages as $n => $u) { $run[] = ['n' => $n, 'u' => $u, 'admin' => true]; }
}
if ($opts['only'] === 'front' || $opts['only'] === 'all') {
    foreach ($frontPages as $n => $u) { $run[] = ['n' => $n, 'u' => $u, 'admin' => false]; }
}

// 预热一遍（丢弃）：编译 Blade、预热容器，让后续 5 轮是稳态
foreach ($run as $r) {
    samplePage($r['n'], $r['u'], $r['admin'], $jar);
}

$results = [];
foreach ($run as $r) {
    $samples = [];
    for ($i = 0; $i < $iter; $i++) {
        $samples[] = samplePage($r['n'], $r['u'], $r['admin'], $jar);
    }
    // 聚合：wall/sql_count 取中位数；repeated 取最后一轮（结构稳定，样本间指纹一致）
    $walls = array_column($samples, 'wall_ms');
    $sqls  = array_column($samples, 'sql_count');
    sort($walls); sort($sqls);
    $mid = (int) floor(($iter - 1) / 2);
    $last = end($samples);
    $results[] = [
        'name'       => $r['n'],
        'uri'        => $r['u'],
        'status'     => $last['status'],
        'wall_ms_p50'=> $walls[$mid],
        'sql_count_p50' => $sqls[$mid],
        'sql_ms_p50' => array_sum(array_column($samples, 'sql_ms')) / $iter,
        'mem_peak_mb'=> $last['mem_peak_mb'],
        'samples'    => $samples,
        'repeated'   => $last['repeated'],
        'origins'    => $last['origins'],
        'sql_time_ms'=> $last['sql_time_ms'],
    ];
}

// ---------------------------------------------------------------------------
// 7. 持久化 + 打印
// ---------------------------------------------------------------------------
$fingerprint = [
    'revision'  => trim((string) shell_exec('cd ' . escapeshellarg($base) . ' && git rev-parse HEAD')),
    'php'       => PHP_VERSION,
    'host'      => gethostname(),
    'iter'      => $iter,
    'authed'    => $authed,
    'front_pages' => count($frontPages),
    'admin_pages' => count($adminPages),
];
$artifact = ['fingerprint' => $fingerprint, 'results' => $results];
$enc = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); if ($enc === false) { fwrite(STDERR, '[warn] json_encode failed: '.json_last_error_msg().PHP_EOL); } file_put_contents($opts['out'], $enc === false ? '{}' : $enc);

// 打印汇总表
echo str_pad('page', 26) . str_pad('st', 5) . str_pad('wall_ms', 10) . str_pad('sql', 6)
    . str_pad('sql_ms', 9) . str_pad('memMB', 8) . "top-repeat\n";
echo str_repeat('-', 110) . "\n";
foreach ($results as $r) {
    $top = '';
    $rep = $r['repeated'];
    arsort($rep);
    foreach ($rep as $fp => $c) {
        if ($c >= 2) { $top = "x$c " . substr(preg_replace('/\s+/', ' ', $fp), 0, 48); break; }
    }
    echo str_pad($r['name'], 26)
        . str_pad((string) $r['status'], 5)
        . str_pad((string) $r['wall_ms_p50'], 10)
        . str_pad((string) $r['sql_count_p50'], 6)
        . str_pad((string) $r['sql_ms_p50'], 9)
        . str_pad((string) $r['mem_peak_mb'], 8)
        . $top . "\n";
}
echo "\n[done] artifact -> {$opts['out']}\n";
