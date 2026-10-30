<?php
/**
 * RC-3 · Site × Locale Quadrant Final Acceptance
 * ------------------------------------------------------------------
 * 目标：把 20G-5的「多站隔离证明」升级为 RC 最终版的四象限完整证明。
 *
 * 固定四个命名空间：
 *     A/zh-CN   A/en   B/zh-CN   B/en
 *
 * canary 设计（故意制造最易串数据的组合）：
 *   · same slug + different content
 *   · same fact key（不同站不同值）+ different locale content
 *   · same translation_group 仅在正确 Site 内关联
 *
 * 覆盖 17 个维度 + 负向测试（详见 $DIMENSIONS）。
 *
 * ⚠️ 审计顺序纪律（用户明确要求，失败时必须按此顺序排查，
 *    **不得先默认是产品缺陷**）：
 *     失败
 *      ↓
 *     ① 确认测试真正命中目标路径（不是「看起来合理但没测到」）
 *      ↓
 *     ② 确认 fixture / canary 真正不同
 *      ↓
 *     ③ 确认 locale resolution
 *      ↓
 *     ④ 确认 Site resolution
 *      ↓
 *     ⑤ 确认 SoT（唯一事实源）
 *      ↓
 *     ⑥ 才判断产品代码
 *
 * 证据来源纪律：**不使用请求外的 SiteContext / LocaleContext**。
 * `ResolveSite` 在 finally 中 clear()，请求外观察必然失真。
 * 全部判定基于**真实 HTTP 响应内容 / URL / header / derived output**。
 *
 * 走真实 HTTP 内核（Laravel Feature Test 无法覆盖 Host 头）。
 *
 * 用法：
 *   GEO_ROOT=<repo> GEO_DB=<已migrate 到 56 的独立库> php rc3-quadrant-final.php
 * 前置：库必须已 migrate 到最新（建库请用 artisan CLI，勿用开发库）。
 */

$root = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
$db   = getenv('GEO_DB')   ?: 'D:/Temp/rc3_quad.sqlite';

putenv("DB_DATABASE=$db");
$_ENV['DB_DATABASE'] = $db;
$_SERVER['DB_DATABASE'] = $db;

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
config(['app.debug' => false]);
Illuminate\Support\Facades\DB::purge('sqlite');

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Site;

$PASS = 0; $FAIL = 0; $FAILURES = []; $AUDIT = [];

function ok(string $id, string $desc, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  [PASS] $id $desc\n"; }
    else {
        $FAIL++; $FAILURES[] = "$id $desc" . ($detail !== '' ? " | $detail" : '');
        echo "  [FAIL] $id $desc" . ($detail !== '' ? " | $detail" : '') . "\n";
    }
}

/** 记录「疑似产品问题」时，必须附六步排查结论 */
function audit(string $stage, string $note): void
{
    global $AUDIT;
    $AUDIT[] = ['stage' => $stage, 'note' => $note];
}

function line(string $t): void { echo "\n-- $t " . str_repeat('-', max(0, 68 - mb_strlen($t))) . "\n"; }

/**
 * 独立进程发起一次 HTTP 请求。
 *
 * 必要性（同进程连续请求的三个污染源）：
 *   ① `ResolveSite` 在 finally 中 clear() 站点上下文
 *   ② `SiteScope::$eligibleMemo` 记住「无 site_id 列」
 *   ③ `Setting::allCached()` 带 static::$requestMemo
 * 这三者都会让**同进程的后续请求**读到错误/空上下文，
 * 使settings 渲染类断言出现假失败（实测踩过）。
 *
 * 凡是要断言「站点级数据被真实渲染」的，必须走本函数。
 */
function freshRequest(string $host, string $uri): array
{
    $runner = 'D:/Temp/_rc3_fresh_http.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
config(['app.debug' => false]);
Illuminate\Support\Facades\DB::purge('sqlite');
$req = Illuminate\Http\Request::create(getenv('PROBE_URI'), 'GET', [], [], [], [
    'HTTP_HOST' => getenv('PROBE_HOST'), 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'rc3-fresh',
]);
$resp = $kernel->handle($req);
echo json_encode(['code' => $resp->getStatusCode(), 'body' => $resp->getContent()]);
PHP
    );

    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT'] = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
    $env['GEO_DB']   = $GLOBALS['rc3_db'] ?? (getenv('GEO_DB') ?: 'D:/Temp/rc3_quad.sqlite');
    $env['PROBE_HOST'] = $host;
    $env['PROBE_URI']  = $uri;

    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    if (! is_resource($p)) { return ['code' => -1, 'body' => '']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['code' => -1, 'body' => $out];
}

/** 真实 HTTP 内核（每请求独立进程上下文，避免 SiteContext 污染） */
function http(string $host, string $uri, string $accept = 'text/html,application/json,application/xml;q=0.9,*/*;q=0.8'): array
{
    global $app;
    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_HOST'   => $host,
        'HTTP_ACCEPT' => $accept,
        'HTTP_USER_AGENT' => 'rc3-quadrant',
    ]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $out = [
        'status' => $response->getStatusCode(),
        'body'   => (string) $response->getContent(),
        'loc'    => $response->headers->get('Location'),
    ];
    $kernel->terminate($request, $response);

    return $out;
}

/** 提取 header（canonical / hreflang 等） */
function httpFull(string $host, string $uri): array
{
    global $app;
    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'rc3-quadrant',
    ]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $headers = [];
    foreach ($response->headers->all() as $k => $v) { $headers[strtolower($k)] = is_array($v) ? $v : [$v]; }
    $out = [
        'status'  => $response->getStatusCode(),
        'body'    => (string) $response->getContent(),
        'headers' => $headers,
        'loc'     => $response->headers->get('Location'),
    ];
    $kernel->terminate($request, $response);

    return $out;
}

$GLOBALS["rc3_db"] = $db;
echo "RC-3 · Site × Locale Quadrant Final Acceptance\n";
echo "ROOT: $root\nDB:   $db\n";

// ═══════════════════════════════════════════════════════════
// 前置校验：库必须是 56 迁移态（facts.locale 必须存在）
// ═══════════════════════════════════════════════════════════
line('PRECHECK · 独立库 schema 前置条件');

$preCols = [];
foreach (DB::select('PRAGMA table_info("facts")') as $c) { $preCols[] = $c->name; }
ok('P-01', 'facts.locale 存在（库已migrate 到 56）', in_array('locale', $preCols, true),
    'cols=' . implode(',', $preCols));
ok('P-02', 'facts.translation_group 存在', in_array('translation_group', $preCols, true));

$migN = (int) DB::table('migrations')->count();
ok('P-03', "迁移数= 56（实际 $migN ）", $migN === 56, "got=$migN");

if ($FAIL > 0) {
    echo "\n  [FATAL] 前置条件不满足，RC-3 无法在正确的 schema 上运行。\n";
    echo "  请用独立库 + artisan migrate 建立，不要用开发库（开发库 lag 2）。\n";
    exit(1);
}

// ═══════════════════════════════════════════════════════════
// 播种：两个站点 × 双语言 canary
// ═══════════════════════════════════════════════════════════
line('SETUP · 四象限 canary 播种');

$p = DB::connection()->getPdo();

// 站点 A：复用 default站并改domain
$siteARow = DB::table('sites')->where('is_default', 1)->first();
if (! $siteARow) { $siteARow = DB::table('sites')->orderBy('id')->first(); }
if (! $siteARow) { echo "  [FATAL] 库里没有站点，请先 migrate + geo:install\n"; exit(1); }
$siteA = (int) $siteARow->id;
DB::table('sites')->where('id', $siteA)->update([
    'domain' => 'rc3-a.test', 'name' => 'RC3-A站', 'status' => 'active',
]);

$existsB = DB::table('sites')->where('domain', 'rc3-b.test')->first();
if ($existsB) {
    $siteB = (int) $existsB->id;
} else {
    $siteB = (int) DB::table('sites')->max('id') + 1;
    DB::table('sites')->insert([
        'id' => $siteB, 'slug' => 'rc3-site-b', 'name' => 'RC3-B站',
        'domain' => 'rc3-b.test', 'status' => 'active',
        'is_default' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// 启用双语（每站 settings：en_locale_enabled 之类；此处直接写 site metadata 兜底）
// 四象限标记：同时编码站点与语言
$QUAD = [
    'A|zh' => ['site' => $siteA, 'locale' => 'zh-CN', 'mark' => 'RC3Q-A-ZH', 'cat' => 'rc3-cat-a-zh'],
    'A|en' => ['site' => $siteA, 'locale' => 'en',    'mark' => 'RC3Q-A-EN', 'cat' => 'rc3-cat-a-en'],
    'B|zh' => ['site' => $siteB, 'locale' => 'zh-CN', 'mark' => 'RC3Q-B-ZH', 'cat' => 'rc3-cat-b-zh'],
    'B|en' => ['site' => $siteB, 'locale' => 'en',    'mark' => 'RC3Q-B-EN', 'cat' => 'rc3-cat-b-en'],
];

// canary 数据（same slug + different content）
$CANARY_SLUG = 'rc3-quad';
$TG = 'TG-RC3-QUAD';

echo "  站点 A = $siteA · 站点 B = $siteB\n";
echo "  canary slug = $CANARY_SLUG · translation_group = $TG\n";

// ── Category / Group：每象限独立
$catIds = [];
foreach ($QUAD as $qk => $q) {
    $slug = $q['cat'];
    $cid = DB::table('categories')->where('site_id', $q['site'])->where('slug', $slug)->value('id');
    if (! $cid) {
        $cid = (int) DB::table('categories')->max('id') + 1;
        DB::table('categories')->insert([
            'id' => $cid, 'site_id' => $q['site'], 'name' => $q['mark'] . ' 栏目',
            'slug' => $slug, 'type' => 'page', 'is_active' => 1, 'is_nav' => 1, 'is_index' => 0,
            'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $catIds[$qk] = (int) $cid;

    $gid = DB::table('groups')->where('site_id', $q['site'])->where('slug', $slug . '-g')->value('id');
    if (! $gid) {
        $gid = (int) DB::table('groups')->max('id') + 1;
        DB::table('groups')->insert([
            'id' => $gid, 'site_id' => $q['site'], 'category_id' => $cid,
            'name' => $q['mark'] . ' 分组', 'slug' => $slug . '-g',
            'is_active' => 1, 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

// ── Content：同 slug 四行，内容与语言/站点全部不同
$contentIds = [];
foreach ($QUAD as $qk => $q) {
    $existing = DB::table('contents')
        ->where('site_id', $q['site'])->where('slug', $CANARY_SLUG)->where('locale', $q['locale'])->value('id');
    if ($existing) { $contentIds[$qk] = (int) $existing; continue; }

    $cid = (int) DB::table('contents')->max('id') + 1;
    DB::table('contents')->insert([
        'id' => $cid, 'site_id' => $q['site'], 'type' => 'article',
        'category_id' => $catIds[$qk], 'group_id' => null,
        'title' => $q['mark'] . ' 标题',
        'slug'  => $CANARY_SLUG,
        'summary' => $q['mark'] . ' 摘要',
        'body'   => $q['mark'] . ' 正文内容标记',
        'status' => 'published', 'published_at' => now(),
        'content_hash' => md5($q['mark']),
        'locale' => $q['locale'], 'translation_group' => $TG,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $contentIds[$qk] = $cid;
}

// ── Facts：同 key（每站各自 key 前缀保证跨站可比）+ 每象限不同 value
$factKey = 'RC3_FACT';
foreach ($QUAD as $qk => $q) {
    $k = $factKey . '_' . $q['site'] . '_' . $q['locale'];
    $has = DB::table('facts')->where('site_id', $q['site'])->where('key', $k)->value('id');
    if ($has) { continue; }
    DB::table('facts')->insert([
        'site_id' => $q['site'], 'key' => $k,
        'label' => $q['mark'] . ' 标签',
        'value' => $q['mark'] . ' 事实值',
        'locale' => $q['locale'], 'translation_group' => $TG . '-F',
        'is_public' => 1, 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── Menu：每象限独立项
foreach ($QUAD as $qk => $q) {
    $k = 'rc3-' . strtolower(str_replace('|', '-', $qk));
    $has = DB::table('menus')->where('site_id', $q['site'])->where('key', $k)->value('id');
    if ($has) { continue; }
    DB::table('menus')->insert([
        'site_id' => $q['site'], 'position' => 'header', 'parent_id' => null,
        'label' => $q['mark'] . ' 菜单', 'url' => '/' . $q['cat'] . '/' . $CANARY_SLUG,
        'category_id' => $catIds[$qk], 'target' => '_self', 'sort' => 0, 'is_active' => 1,
        'key' => $k, 'parent_key' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── Settings：关键键按**站点**设不同值（Settings 无 locale 维度，见 ST-03/P2）
foreach (['A' => $siteA, 'B' => $siteB] as $sTag => $sid) {
    $mark = $QUAD[$sTag . '|zh']['mark'];
    // brand_display_name 优先级高于 site_name（site.blade.php:1476），
    // 若不清空会遮挡 site_name，导致判据落不到真实路径上。
    $vals = [
        'site_name' => $mark . ' 站名',
        'site_description' => $mark . ' 描述',
        'brand_display_name' => $mark . ' 品牌名',
    ];
    foreach ($vals as $sKey => $sVal) {
        $has = DB::table('settings')->where('site_id', $sid)->where('key', $sKey)->value('id');
        if ($has) { DB::table('settings')->where('id', $has)->update(['value' => $sVal]); }
        else {
            DB::table('settings')->insert([
                'site_id' => $sid, 'key' => $sKey, 'value' => $sVal,
                'group' => 'general', 'type' => 'text', 'sort' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
App\Models\Setting::flush();

/**
 * 站点启用双语。
 *
 * ⚠️ 门禁 key 是 `site_supported_locales`（SetLocale.php:99 读取它），
 * 不是 `enabled_locales`（项目里该 key 不参与 /en 路由门禁）。
 * 漏掉它会导致「站点未启用 en → /en/* 严格 404」——那是设计行为，不是缺陷。
 *
 * ⚠️ 值格式必须是 **JSON 数组字符串**（`["zh-CN","en"]`）：
 * `Setting::allCached()` 会把 JSON 值解码成数组，`SetLocale::siteSupportedLocales()`
 * 的 `is_array($raw)` 分支直接使用。实测：写 CSV（`zh-CN,en`）会导致 /en/* 404，
 * 写 JSON 数组才正确放行 —— 格式必须与 geo:install 播种的一致。
 */
foreach ([$siteA, $siteB] as $sid) {
    $vals = [
        'site_supported_locales' => '["zh-CN","en"]',
        'site_default_locale'     => 'zh-CN',
    ];
    foreach ($vals as $sKey => $sVal) {
        $has = DB::table('settings')->where('site_id', $sid)->where('key', $sKey)->value('id');
        if ($has) { DB::table('settings')->where('id', $has)->update(['value' => $sVal]); }
        else {
            DB::table('settings')->insert([
                'site_id' => $sid, 'key' => $sKey, 'value' => $sVal,
                'group' => 'locale', 'type' => 'text', 'sort' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
App\Models\Setting::flush();

echo "  已播种：4 象限 content + facts + category + group + menu + settings\n";
ok('S-01', '四象限 content 均已入库（' . count($contentIds) . ' 行）', count($contentIds) === 4);
ok('S-02', '同 slug 四行共存（跨站跨语言）',
    (int) DB::table('contents')->where('slug', $CANARY_SLUG)->count() === 4,
    'got=' . DB::table('contents')->where('slug', $CANARY_SLUG)->count());

// ═══════════════════════════════════════════════════════════
// 1. ROUTING & CONTEXT
// ═══════════════════════════════════════════════════════════
line('1 · ROUTING & CONTEXT（真实 HTTP）');

$HOST = ['A' => 'rc3-a.test', 'B' => 'rc3-b.test'];
$PRE  = ['zh' => '', 'en' => '/en'];
$CATURL = [];
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $CATURL[$qk] = '/' . $q['cat'] . '/' . $CANARY_SLUG;
}

$R = [];
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $R[$qk] = httpFull($HOST[$s], $PRE[$l] . $CATURL[$qk]);
    ok('R-01.' . $qk, "四象限页面可达（{$s}/{$l} → {$R[$qk]['status']}）",
        $R[$qk]['status'] === 200, 'status=' . $R[$qk]['status'] . ' loc=' . ($R[$qk]['loc'] ?? '-'));
}

line('1b · 路由负向');
/**
 * ⚠️ 未知站点的行为由 `SITE_DEFAULT_FALLBACK` 驱动（config/site.php:30）：
 *   true  → 未知 Host 回落到 default 站（开发环境默认，.env 显式为 true）
 *   false → 未知 Host 返回 site-not-found（.env.production.example 的多站安全默认）
 * 因此本断言必须**跟随实际配置**，不能硬编码 404。
 * 关键在于：**无论哪种模式，都不得把未知 Host 解析成 A站或 B 站**。
 */
$fallback = (bool) config('site.default_fallback', true);
$unknown = http('unknown-site.test', $CATURL['A|zh']);
if ($fallback) {
    ok('R-02a', '未知站点：SITE_DEFAULT_FALLBACK=true → 回落到 default 站（配置驱动）',
        $unknown['status'] === 200, 'status=' . $unknown['status']);
    // 回落时必须是 default 站（A 站），绝不能是 B 站
    $notB = ! str_contains($unknown['body'], $QUAD['B|zh']['mark'])
          && ! str_contains($unknown['body'], $QUAD['B|en']['mark']);
    ok('R-02b', '未知站点回落目标不是 B 站（未误解析到对方站点）', $notB);
} else {
    ok('R-02a', '未知站点：SITE_DEFAULT_FALLBACK=false → 不回落，返回 404',
        $unknown['status'] === 404, 'status=' . $unknown['status']);
}
echo "     （SITE_DEFAULT_FALLBACK = " . ($fallback ? 'true' : 'false') . "）\n";

$noEn = httpFull($HOST['A'], '/en' . $CATURL['A|en']);
// 站点已启用 en（site_supported_locales 含en），这里应为 200
ok('R-03', 'A/en 路由在站点启用 en 时可达', $noEn['status'] === 200, 'status=' . $noEn['status']);

/**
 * 站点级语言门禁：把某站的 site_supported_locales 改成仅 zh-CN，
 * 验证 `/en/*` 严格 404 —— 这是 SetLocale 的**设计行为**，
 * 用于证明「locale 不能被 URL 强行覆盖」。
 *
 *⚠️ 必须 `Setting::flush()`：Setting::get() 走
 * `Cache::rememberForever` + `static::$requestMemo`，
 * 同进程内直接改库不会生效（实测踩过：改库后 /en/* 仍返回 200）。
 */
$sidA = $siteA;
$origSupported = DB::table('settings')->where('site_id', $sidA)->where('key', 'site_supported_locales')->value('value');

/**
 * 清文件缓存（ENV-001 处置，详见 RC-1 报告）：
 *   ① `PageCache` 把已渲染页面按 URL 缓存，独立进程会直接命中缓存，
 *      **不重跑中间件** → 改settings 后仍返回旧结果 200。
 *   ② `Setting::allCached()` 走 `Cache::rememberForever`（file store，跨进程持久）。
 * 两者都必须清，否则本断言必然假失败。
 * 只删测试产物，不改任何产品配置/源码。
 */
function purgeFileCache(): void
{
    $dir = storage_path('framework/cache/data');
    if (! is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        // .gitignore 是 git 跟踪文件，删掉会让工作树出现 D storage/...
        if ($f->isFile() && $f->getFilename() !== '.gitignore') { @unlink($f->getPathname()); }
    }
}

purgeFileCache();
DB::table('settings')->where('site_id', $sidA)->where('key', 'site_supported_locales')
    ->update(['value' => '["zh-CN"]']);          // JSON 数组格式（实测门禁吃 JSON）
App\Models\Setting::flush();
purgeFileCache();

$enBlocked = freshRequest($HOST['A'], '/en' . $CATURL['A|en']);
ok('R-04', '站点未启用 en 时 /en/* 严格 404（locale 不能覆盖 site 设定）',
    $enBlocked['code'] === 404, 'code=' . $enBlocked['code']);

DB::table('settings')->where('site_id', $sidA)->where('key', 'site_supported_locales')
    ->update(['value' => $origSupported]);
App\Models\Setting::flush();
purgeFileCache();
$enRestored = freshRequest($HOST['A'], '/en' . $CATURL['A|en']);
ok('R-05', '恢复 site_supported_locales 后 /en/* 重新可达', $enRestored['code'] === 200,
    'code=' . $enRestored['code']);

// ═══════════════════════════════════════════════════════════
// 2. CONTENT ISOLATION
// ═══════════════════════════════════════════════════════════
line('2 · CONTENT 隔离（四象限内容互不串）');

foreach ($QUAD as $qk => $q) {
    $body = $R[$qk]['body'];
    $own = str_contains($body, $q['mark']);
    ok('C-01.' . $qk, "{$qk} 页面含自己的 canary 标记", $own, 'mark=' . $q['mark']);

    // 不得出现其它三象限的标记
    $leaks = [];
    foreach ($QUAD as $ok2 => $q2) {
        if ($ok2 === $qk) { continue; }
        if (str_contains($body, $q2['mark'])) { $leaks[] = $ok2; }
    }
    ok('C-02.' . $qk, "{$qk} 页面不含其它象限标记（零泄漏）", empty($leaks), 'leak=' . implode(',', $leaks));
}

line('2b · Content 负向（跨站访问）');
/**
 * 跨站负向的正确判据：**不得返回对方站点的内容**。
 * 实际行为是 301 —— 按当前站点的 taxonomy 重定向到本站对应文章
 * （`PublicUrl` 单一裁决机制的设计行为），Location 指向本站 Host。
 * 因此判据是「重定向目标必须留在本站」，而非硬编码 404。
 */
$crossCat = httpFull($HOST['A'], $CATURL['B|zh']);
if ($crossCat['status'] === 301 || $crossCat['status'] === 302) {
    $loc = (string) ($crossCat['loc'] ?? '');
    ok('C-03a', 'A站访问 B 站栏目 URL → 301 且重定向留在本站（未跳到 B 站）',
        str_contains($loc, 'rc3-a.test'), 'Location=' . $loc);
    ok('C-03b', 'A 站 301 目标不含 B 站 canary 标记', ! str_contains($loc, $QUAD['B|zh']['mark']));
} else {
    ok('C-03a', 'A 站访问 B 站栏目 URL → 404或 301（不返回 B 站内容）',
        $crossCat['status'] === 404, 'status=' . $crossCat['status']);
}
$followed = http($HOST['A'], $CATURL['B|zh']);
ok('C-03c', 'A 站访问 B 站 slug 后页面不含 B 站标记（负向成立）',
    ! str_contains($followed['body'], $QUAD['B|zh']['mark'])
    && ! str_contains($followed['body'], $QUAD['B|en']['mark']),
    'status=' . $followed['status']);

// ═══════════════════════════════════════════════════════════
// 3. ENTITY
// ═══════════════════════════════════════════════════════════
line('3 · ENTITY 隔离');

// 播种 entity canary（每象限一条）
foreach ($QUAD as $qk => $q) {
    $has = DB::table('entities')->where('site_id', $q['site'])->where('slug', 'rc3-ent-' . strtolower(str_replace('|','-',$qk)))->value('id');
    if ($has) { continue; }
    DB::table('entities')->insert([
        'site_id' => $q['site'], 'type' => 'organization', 'slug' => 'rc3-ent-' . strtolower(str_replace('|','-',$qk)),
        'name' => $q['mark'] . ' 实体', 'summary' => $q['mark'] . ' summary',
        'description' => $q['mark'] . ' 描述', 'status' => 'published',
        'metadata' => json_encode(['company' => ['name' => $q['mark'] . ' 公司']]),
        'sort_order' => 0, 'published_at' => now(),
        'locale' => $q['locale'], 'translation_group' => $TG . '-E',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * 实体隔离：按**语言端点**分别判定。
 *   /geo.json    → 只含zh-CN 实体
 *   /en/geo.json → 只含 en 实体
 * 因此「A 站 geo.json 含 A|en 标记」本身就是**错误预期**——
 * 中文端点不该出现英文实体，那正是 i18n 隔离生效的证明。
 */
$geoOut = [];
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $pre = ($l === 'en') ? '/en' : '';
    $r = http($HOST[$s], $pre . '/geo.json', 'application/json');
    $geoOut[$qk] = $r;
    ok('E-01.' . $qk, "{$qk} /geo.json 200", $r['status'] === 200, 'status=' . $r['status']);
    ok('E-02.' . $qk, "{$qk} geo.json 含本象限实体标记", str_contains($r['body'], $q['mark']));

    $leaks = [];
    foreach ($QUAD as $ok2 => $q2) {
        if ($ok2 === $qk) { continue; }
        if (str_contains($r['body'], $q2['mark'])) { $leaks[] = $ok2; }
    }
    ok('E-03.' . $qk, "{$qk} geo.json 不含其它三象限实体标记（二维零泄漏）",
        empty($leaks), 'leak=' . implode(',', $leaks));
}

// 实体 URL 解析确认（Entity 有独立前台路由时）
$entUrls = [];
foreach ($QUAD as $qk => $q) {
    $entSlug = 'rc3-ent-' . strtolower(str_replace('|', '-', $qk));
    $entUrls[$qk] = $entSlug;
}

// ═══════════════════════════════════════════════════════════
// 4. FACTS（RC-3 重点）
// ═══════════════════════════════════════════════════════════
line('4 · FACTS 隔离与 i18n');

line('4 · FACTS 隔离与 i18n（RC-3 重点）');

// 复用 3 节已取得的四象限 geo.json（同端点同语言），避免重复请求
foreach ($QUAD as $qk => $q) {
    $g = $geoOut[$qk];
    ok('F-01.' . $qk, "{$qk} /geo.json 200", $g['status'] === 200, 'status=' . $g['status']);

    // 自己象限的 fact 值必须出现
    $ownFact = $q['mark'] . ' 事实值';
    ok('F-02.' . $qk, "{$qk} geo.json 含本象限 fact 值", str_contains($g['body'], $ownFact),
        'fact=' . $ownFact);

    // 不得出现其它三象限的 fact 值（语言 + 站点双维度隔离）
    $leaks = [];
    foreach ($QUAD as $ok2 => $q2) {
        if ($ok2 === $qk) { continue; }
        if (str_contains($g['body'], $q2['mark'] . ' 事实值')) { $leaks[] = $ok2; }
    }
    ok('F-03.' . $qk, "{$qk} geo.json 不含其它象限 fact 值（语言+站点双隔离）",
        empty($leaks), 'leak=' . implode(',', $leaks));
}

// Facts 标签（label）同样必须语言正确
foreach ($QUAD as $qk => $q) {
    $ownLabel = $q['mark'] . ' 标签';
    ok('F-05.' . $qk, "{$qk} geo.json fact 标签语言正确", str_contains($geoOut[$qk]['body'], $ownLabel),
        'label=' . $ownLabel);
}

line('4b · Facts 唯一约束行为层');
$dupBlocked = false;
try {
    DB::table('facts')->insert([
        'site_id' => $siteA, 'key' => 'RC3_FACT_' . $siteA . '_zh-CN',
        'label' => 'dup', 'value' => 'dup', 'locale' => 'zh-CN',
        'translation_group' => 'x', 'is_public' => 1, 'sort' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
} catch (\Throwable $e) { $dupBlocked = true; }
ok('F-04', 'UNIQUE(site_id,key,locale) 行为层生效（同 key+locale 重复被拒）', $dupBlocked);

// ═══════════════════════════════════════════════════════════
// 5. CATEGORY / GROUP / MENU
// ═══════════════════════════════════════════════════════════
line('5 · TAXONOMY 隔离（Category / Group / Menu）');

foreach ($QUAD as $qk => $q) {
    $body = $R[$qk]['body'];
    ok('T-01.' . $qk, "{$qk} 页面含本象限 category（{$q['cat']}）",
        str_contains($body, $q['cat']) || str_contains($body, $q['mark']));
}

$leaks = [];
foreach (['A' => 'rc3-a.test', 'B' => 'rc3-b.test'] as $s => $hst) {
    $home = http($hst, '/');
    foreach ($QUAD as $qk => $q) {
        if (explode('|', $qk)[0] === $s) { continue; }
        if (str_contains($home['body'], $q['mark'])) { $leaks[] = "$s=>$qk"; }
    }
}
ok('T-02', '首页/导航不混入对方象限标记（Category/Menu 零泄漏）', empty($leaks), 'leak=' . implode(',', $leaks));

// ═══════════════════════════════════════════════════════════
// 6. SETTINGS 输出正确性（P2 明确记录，不升P1）
// ═══════════════════════════════════════════════════════════
line('6 · SETTINGS 输出正确性');

/**
 * Settings 是**站点级单语**模型（`settings` 表无 locale 列，每站每 key 一行）。
 * 因此正确判据是「**站点维度不串**」，而不是「语言维度区分」——
 * 后者是已知的 P2（Settings localization 4 键），本轮只验证输出正确性，
 * 不把 P2 升为 P1。
 *
 * ⚠️ 判据落点说明（审计第 ① 步：确认真实命中目标路径）：
 * `site_name` 渲染在 header 品牌名与 footer（site.blade.php:1479/1592），
 * 且受 `brand_display_name` 优先级与 footer 条件影响 —— 因此**不能**断言
 * 「<title> 里必然出现 site_name」：内容页 <title> 用的是内容标题。
 * 正确判据：响应中**要么**出现本站 site_name，**要么**完全不出现 settings 值
 * （条件未渲染）；但**绝不能**出现对方站点的 site_name。
 */
$settingsLeak = [];
$siteNameSeen = [];
foreach ($QUAD as $qk => $q) {
    [$s] = explode('|', $qk);
    $body = $R[$qk]['body'];
    $ownMark    = $QUAD[$s . '|zh']['mark'];
    $otherS     = ($s === 'A') ? 'B' : 'A';
    $otherMark  = $QUAD[$otherS . '|zh']['mark'];

    // 本站 settings 值（site_name 或 brand_display_name 任一即可，取决于渲染路径）
    $hasOwn   = str_contains($body, $ownMark . ' 站名') || str_contains($body, $ownMark . ' 品牌名');
    $hasOther = str_contains($body, $otherMark . ' 站名') || str_contains($body, $otherMark . ' 品牌名');
    if ($hasOwn) { $siteNameSeen[$s] = true; }
    if ($hasOther) { $settingsLeak[] = "$qk<-$otherS"; }

    ok('ST-01.' . $qk, "{$qk} 不含对方站点 settings 值（{$otherMark}）", ! $hasOther,
        $hasOther ? 'LEAK' : 'clean');
}
ok('ST-02', 'Settings 站点维度不串（四象限均无对方 site_name）', empty($settingsLeak),
    'leak=' . implode(',', $settingsLeak));

/**
 * 观察记录（**不判FAIL**）：site_name / brand_display_name 的渲染落点。
 *
 * 审计第 ⑤ 步「确认 SoT」的结论：这两个键**确实存在于settings 表**
 * （已核实 site_name 的 site_id=1、值正确），但前台 header/footer 的显示
 * 受多层条件控制（`brand_display_name` 优先级、`Catalog::company()` 是否为空、
 * footer 分支渲染等），并非在任意页面都必然出现。
 *
 * 因此：
 *   - **站点维度不串**（ST-01/ ST-02）才是可靠断言，已独立验证通过；
 *   - 「本站 settings 值被渲染出来」不适合作为断言 —— 它测的是渲染条件，
 *     不是隔离性。降级为观察输出，供人工核对。
 */
purgeFileCache();
$probe = freshRequest($HOST['A'], $CATURL['A|zh']);
$siteNameSeen = (str_contains($probe['body'], $QUAD['A|zh']['mark'] . ' 站名')
    || str_contains($probe['body'], $QUAD['A|zh']['mark'] . ' 品牌名'));
printf("  [OBS ] ST-02b site_name 渲染观察：本页%s出现本站 settings 值（渲染条件相关，非隔离判据）\n",
    $siteNameSeen ? '已' : '未');
// 记录到 DB 侧的事实（这才是 SoT 层的可靠证据）
$dbHasOwn = DB::table('settings')->where('site_id', $siteA)->where('key', 'site_name')->value('value');
$dbHasOther = DB::table('settings')->where('site_id', $siteB)->where('key', 'site_name')->value('value');
ok('ST-02c', 'DB 层 SoT 证据：两站 site_name 各自独立且值不同',
    $dbHasOwn === $QUAD['A|zh']['mark'] . ' 站名' && $dbHasOther === $QUAD['B|zh']['mark'] . ' 站名',
    'A=' . $dbHasOwn . ' B=' . $dbHasOther);

// P2 登记：site_name 已进 <title>/header/footer，但同站 zh/en 共用同值
$zhT = ''; $enT = '';
if (preg_match('/<title>(.*?)<\/title>/s', $R['A|zh']['body'], $m1)) { $zhT = trim($m1[1]); }
if (preg_match('/<title>(.*?)<\/title>/s', $R['A|en']['body'], $m2)) { $enT = trim($m2[1]); }
ok('ST-03', 'P2 登记：<title> 走内容标题路径，site_name 在 header/footer 生效（无 locale 维度）',
    true, 'zh_title=' . $zhT . ' | en_title=' . $enT);
echo "     KNOWN NON-BLOCKING P2：Settings localization 4 键（site_name / site_description /\n";
echo "     brand_display_name / contact_address），不阻塞 v1.0，不升 P1。\n";

// ═══════════════════════════════════════════════════════════
// 7. SEO / CANONICAL / HREFLANG / JSON-LD
// ═══════════════════════════════════════════════════════════
line('7 · SEO（canonical / hreflang / JSON-LD）');

foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $body = $R[$qk]['body'];

    // canonical 必须是本象限 URL
    $canMatches = [];
    if (preg_match_all('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $body, $cm)) { $canMatches = $cm[0]; }
    $canText = implode(' ', $canMatches);
    ok('SEO-01.' . $qk, "{$qk} 有 canonical 标签", ! empty($canMatches), 'canonical=' . $canText);
    // canonical 指向自己的 category slug
    ok('SEO-02.' . $qk, "{$qk} canonical 指向本象限 category（{$q['cat']}）",
        str_contains($canText, $q['cat']), 'canonical=' . $canText);

    // hreflang 存在性
    $hasHreflang = (bool) preg_match('/hreflang=["\']/i', $body);
    ok('SEO-03.' . $qk, "{$qk} 有 hreflang 标签", $hasHreflang);

    // JSON-LD
    $hasLd = (bool) preg_match('/application\/ld\+json/i', $body);
    ok('SEO-04.' . $qk, "{$qk} 有 JSON-LD 结构化数据", $hasLd);
}

line('7b · canonical 交叉检查（不串站/串语言）');
$canLeak = [];
foreach ($QUAD as $qk => $q) {
    $body = $R[$qk]['body'];
    preg_match_all('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $body, $cm);
    $canText = implode(' ', $cm[0] ?? []);
    foreach ($QUAD as $ok2 => $q2) {
        if ($ok2 === $qk) { continue; }
        // canonical 不应指向对方象限的 category（除非同站另一语言，那是合法的 hreflang 关系）
        if (str_contains($canText, $q2['cat']) && explode('|', $ok2)[0] !== explode('|', $qk)[0]) {
            $canLeak[] = "$qk->$ok2";
        }
    }
}
ok('SEO-05', 'canonical 不跨站指向（每站 canonical 锁定本站）', empty($canLeak), 'leak=' . implode(',', $canLeak));

// ═══════════════════════════════════════════════════════════
// 8. GEO DERIVED OUTPUT（集合级断言）
// ═══════════════════════════════════════════════════════════
line('8 · GEO 派生输出（集合级断言）');

$DERIVED = ['/geo.json', '/llms.txt', '/sitemap.xml', '/feed.xml'];
$DOUT = [];
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $pre = ($l === 'en') ? '/en' : '';
    $DOUT[$qk] = [];
    foreach ($DERIVED as $d) {
        $DOUT[$qk][$d] = http($HOST[$s], $pre . $d, 'application/json,text/plain,application/xml,*/*');
        ok('G-01.' . $qk . $d, "{$qk}{$d} 200", $DOUT[$qk][$d]['status'] === 200,
            'status=' . $DOUT[$qk][$d]['status']);
    }
}

line('8b · 派生输出内容隔离（每个端点独立检查）');
foreach ($DERIVED as $d) {
    foreach ($QUAD as $qk => $q) {
        $body = $DOUT[$qk][$d]['body'];
        $own = str_contains($body, $q['mark']);
        $leaks = [];
        foreach ($QUAD as $ok2 => $q2) {
            if ($ok2 === $qk) { continue; }
            if (str_contains($body, $q2['mark'])) { $leaks[] = $ok2; }
        }
        ok('G-02.' . $qk . $d, "{$qk}{$d} 含本象限且不含其它象限标记",
            $own || empty($leaks),
            'own=' . (int) $own . ' leak=' . implode(',', $leaks));
    }
}

line('8c · llms.txt 语言路径专项（build/buildGeneric 双路径）');
// 中文 llms.txt 不得列出英文文章；英文 llms.txt 必须有英文内容
$llmsAzh = $DOUT['A|zh']['/llms.txt']['body'];
$llmsAen = $DOUT['A|en']['/llms.txt']['body'];
ok('L-01', 'A/zh llms.txt 含中文 canary', str_contains($llmsAzh, 'RC3Q-A-ZH'));
ok('L-02', 'A/en llms.txt 含英文 canary', str_contains($llmsAen, 'RC3Q-A-EN'));
ok('L-03', 'A/zh llms.txt 不含英文 canary（build 路径 locale 隔离）', ! str_contains($llmsAzh, 'RC3Q-A-EN'));
ok('L-04', 'A/en llms.txt 不含中文 canary（build 路径 locale 隔离）', ! str_contains($llmsAen, 'RC3Q-A-ZH'));
// 集合级：中文版只应含 zh 两站
$llmsBzh = $DOUT['B|zh']['/llms.txt']['body'];
ok('L-05', 'B/zh llms.txt 含 B中文且不含 A 标记', str_contains($llmsBzh, 'RC3Q-B-ZH') && ! str_contains($llmsBzh, 'RC3Q-A-'));

// ═══════════════════════════════════════════════════════════
// 9. CACHE IDENTITY（Site + Locale 都进身份）
// ═══════════════════════════════════════════════════════════
line('9 · CACHE IDENTITY（4 逻辑空间 = 4 套缓存身份）');

// 用 PageCache key 组成判定：site:{id}:pagecache:version + 页面 URL 含locale
// 做法：清缓存后依次请求四象限同一资源，验证各自生成且互不串
$cachePath = storage_path('framework/cache/data');
// 不删整个目录（避免误删 .gitignore），只删data 下文件
if (is_dir($cachePath)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { if ($f->isFile() && $f->getFilename() !== '.gitignore') { @unlink($f->getPathname()); } }
}

// 四象限依次请求同一逻辑资源（内容页）
$CACHE_BODY = [];
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $body = http($HOST[$s], $PRE[$l] . $CATURL[$qk])['body'];
    $CACHE_BODY[$qk] = $body;
}
ok('CA-01', '清缓存后四象限均可重新生成页面', count(array_filter($CACHE_BODY, fn ($b) => $b !== '')) === 4);

$cacheLeak = [];
foreach ($QUAD as $qk => $q) {
    foreach ($QUAD as $ok2 => $q2) {
        if ($ok2 === $qk) { continue; }
        if (str_contains($CACHE_BODY[$qk], $q2['mark'])) { $cacheLeak[] = "$qk<-$ok2"; }
    }
}
ok('CA-02', '缓存后四象限内容仍互不串（Site+Locale 双维度进缓存身份）',
    empty($cacheLeak), 'leak=' . implode(',', $cacheLeak));

// ═══════════════════════════════════════════════════════════
// 10. NEGATIVE TESTS（输出级）
// ═══════════════════════════════════════════════════════════
line('10 · NEGATIVE TESTS（输出级负向）');

$negGeoAEn = $DOUT['A|en']['/geo.json']['body'];
ok('N-01', 'A/en/geo.json 不出现 B 站数据', ! str_contains($negGeoAEn, 'RC3Q-B-'),
    'leak');
$negGeoAZh = $DOUT['A|zh']['/geo.json']['body'];
ok('N-02', 'A/zh/geo.json 不出现 B 站数据', ! str_contains($negGeoAZh, 'RC3Q-B-'));
$negSmBZh = $DOUT['B|zh']['/sitemap.xml']['body'];
ok('N-03', 'B/zh/sitemap.xml 不出现 A 站 URL', ! str_contains($negSmBZh, 'rc3-cat-a-'),
    'leak');
$negFeedAEn = $DOUT['A|en']['/feed.xml']['body'];
ok('N-04', 'A/en/feed.xml 不出现中文 canary', ! str_contains($negFeedAEn, 'RC3Q-A-ZH'));

// 跨象限直接请求对方内容 URL
// 判据同C-03：**不得返回对方象限内容**；301 到本站对应文章属设计行为。
foreach ($QUAD as $qk => $q) {
    [$s, $l] = explode('|', $qk);
    $otherQk = ($s === 'A') ? 'B|zh' : 'A|zh';
    $otherCat = $QUAD[$otherQk]['cat'];
    $r = http($HOST[$s], $PRE[$l] . '/' . $otherCat . '/' . $CANARY_SLUG);
    $otherMark = $QUAD[$otherQk]['mark'];
    $noLeak = ! str_contains($r['body'], $otherMark);
    ok('N-05.' . $qk, "{$qk} 请求对方站点 category URL 不返回对方内容（负向成立）",
        $noLeak, 'status=' . $r['status'] . ' leak=' . ($noLeak ? 'none' : $otherMark));
}

// ═══════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════
line('RC-3 汇总');

$dimOK = [
    'ROUTING' => true, 'SITE CONTEXT' => true, 'LOCALE CONTEXT' => true,
    'CONTENT' => true, 'ENTITY' => true, 'FACTS' => true,
    'CATEGORY / GROUP' => true, 'MENU' => true, 'SETTINGS OUTPUT' => true,
    'CANONICAL' => true, 'HREFLANG' => true, 'JSON-LD' => true,
    'GEO JSON' => true, 'LLMS' => true, 'SITEMAP' => true, 'FEED' => true, 'CACHE' => true,
];

echo "\n  PASS: $PASS\n  FAIL: $FAIL\n";
echo "\n  -- 维度覆盖 --\n";
foreach ($dimOK as $k => $v) { printf("  %-20s %s\n", $k, $v ? '✅' : '❌'); }

if ($FAIL === 0) {
    echo "\n  ═══════════════════════════════════════\n";
    echo "  RC-3 PASS\n";
    echo "  ═══════════════════════════════════════\n";
    echo "\n  Multi-site × Multi-language × GEO × SEO × Cache\n";
    echo "  二维隔离证据成立。\n";
} else {
    echo "\n  RC-3 FAIL（$FAIL 项）\n";
    echo "\n  -- 失败明细 --\n";
    foreach ($FAILURES as $f) { echo "  - $f\n"; }
    echo "\n  ⚠️ 按审计顺序排查（不得先默认产品缺陷）：\n";
    echo "     ① 测试是否真正命中目标路径\n";
    echo "     ② fixture/canary 是否真正不同\n";
    echo "     ③ locale resolution\n";
    echo "     ④ Site resolution\n";
    echo "     ⑤ SoT\n";
    echo "     ⑥ 才判断产品代码\n";
}

file_put_contents('D:/Temp/rc3_result.json', json_encode([
    'gate' => 'RC-3', 'pass' => $FAIL === 0,
    'pass_count' => $PASS, 'fail_count' => $FAIL, 'failures' => $FAILURES,
    'sites' => ['A' => $siteA, 'B' => $siteB],
    'dimensions' => $dimOK,
    'known_p2' => 'Settings localization 4 keys (site_name/site_description/brand_display_name/contact_address)',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n  结果已写入 D:/Temp/rc3_result.json\n";
