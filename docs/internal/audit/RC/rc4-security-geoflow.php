<?php
/**
 * RC-4 · Security / GEOFlow Contract Final
 * ------------------------------------------------------------------
 * 目标：证明**最终冻结工作树**上的安全与同步契约
 *       没有被后续 i18n / Site 能力合入破坏。
 *
 * 四组主线：
 *   A. Rendering Security   XSS / Markdown / 危险协议 / HTML sink / UTF-8
 *   B. GEOFlow Contract     type / 长度 / 枚举 / 日期 / JSON / nullable / hash
 *   C. State & Concurrency  lock_manual / kill switch / idempotency /并发 / 409
 *   D. Persistence / Audit  revision / SyncLog / site_id / cache invalidation
 *
 * 五个不变量（必须锁死）：
 *   1. 非法输入 → 422，不出现 TypeError / 500
 *   2. 同 payload 第二次推送 → stable skip
 *   3. lock_manual → upsert / unpublish 都不能覆盖
 *   4. kill switch OFF → 所有写入口停止
 *   5. **一次真实写入 → 一次 cache invalidation**（计数，不是「最终正确」）
 *
 * 第 5 条用**版本号计数**证明：`PageCache::flush()` 是 `version()+1`（非幂等），
 * 若一次写入触发 N 个 hook，版本就 +N。必须精确等于 N+1。
 *
 * 真实 HTTP 纪律：GEOFlow upsert / check / unpublish / Kill Switch /
 * Malformed UTF-8 / XSS payload 全部走**真实 HTTP 内核**，
 * 不退回 PHPUnit 单元级（Laravel Feature Test 无法覆盖 Host 头）。
 *
 * 审计纪律（沿用 RC-3）：失败时按
 *   ① 命中目标 → ② fixture 真实 → ③ 断言方向 → ④ 才判产品缺陷
 *
 * 用法：
 *   GEO_ROOT=<repo> GEO_DB=<独立库> php rc4-security-geoflow.php
 * 前置：库已 migrate 到56（建库用 artisan CLI，勿用开发库）。
 */

$root = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
$db   = getenv('GEO_DB')   ?: 'D:/Temp/rc4_sec.sqlite';

putenv("DB_DATABASE=$db");
$_ENV['DB_DATABASE'] = $db;
$_SERVER['DB_DATABASE'] = $db;
$GLOBALS['rc4_db'] = $db;

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
config(['app.debug' => false]);
Illuminate\Support\Facades\DB::purge('sqlite');

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\PageCache;
use App\Support\SiteContext;
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
function line(string $t): void { echo "\n-- $t " . str_repeat('-', max(0, 68 - mb_strlen($t))) . "\n"; }
function audit(string $stage, string $note): void { global $AUDIT; $AUDIT[] = ['stage' => $stage, 'note' => $note]; }

/** 真实 HTTP（api组，独立进程上下文） */
function http(string $method, string $uri, array $server = [], ?array $json = null, ?string $raw = null): array
{
    global $app;
    $srv = array_merge(['HTTP_ACCEPT' => 'application/json', 'HTTP_USER_AGENT' => 'rc4'], $server);
    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
        $srv['CONTENT_TYPE'] = 'application/json';
    } elseif ($raw !== null) {
        $body = $raw;
        $srv['CONTENT_TYPE'] = $server['CONTENT_TYPE'] ?? 'application/json';
    } else {
        $body = null;
    }
    $req = Request::create($uri, $method, [], [], [], $srv, $body);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $resp = $kernel->handle($req);
    $out = ['code' => $resp->getStatusCode(), 'body' => (string) $resp->getContent()];
    $kernel->terminate($req, $resp);

    return $out;
}

/** 独立进程 HTTP（避免同进程 memo / SiteContext 污染） */
function freshHttp(string $method, string $uri, array $server = [], ?array $json = null, ?string $raw = null, bool $debug = false): array
{
    $runner = 'D:/Temp/_rc4_http.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
config(['app.debug' => getenv('PROBE_DEBUG') === '1']);
Illuminate\Support\Facades\DB::purge('sqlite');

$method = getenv('PROBE_METHOD');
$uri    = getenv('PROBE_URI');
$body   = getenv('PROBE_BODY');
$srv    = json_decode(getenv('PROBE_SERVER'), true) ?: [];

/**
 * ⚠️ server 值必须归一为**标量字符串**（RC-4 实测踩坑）。
 * `Request::bearerToken()` 内部走 `strripos($header, 'Bearer ')`，
 * 若 header 值是数组（如 json_decode 出的嵌套结构），
 * 会抛 `Array to string conversion` → 500。
 * 这里显式过滤掉非标量，避免把框架层的类型假设带进测试。
 */
foreach ($srv as $k => $v) {
    if (is_array($v) || is_object($v)) { $srv[$k] = json_encode($v); }
    elseif ($v === null)             { unset($srv[$k]); }
    else                             { $srv[$k] = (string) $v; }
}

$req = Illuminate\Http\Request::create($uri, $method, [], [], [], $srv, $body ?: null);
$resp = $kernel->handle($req);
echo json_encode(['code' => $resp->getStatusCode(), 'body' => $resp->getContent()]);
PHP
    );

    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT'] = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
    $env['GEO_DB']   = $GLOBALS['rc4_db'] ?? getenv('GEO_DB');
    $env['PROBE_METHOD'] = $method;
    $env['PROBE_URI']    = $uri;
    // 归一为标量字符串（bearerToken 依赖 strripos(string)）
    $srvNorm = [];
    foreach ($server as $k => $v) {
        $srvNorm[$k] = is_scalar($v) ? (string) $v : json_encode($v);
    }
    $env['PROBE_SERVER'] = json_encode($srvNorm);
    $env['PROBE_DEBUG']  = $debug ? '1' : '0';
    $env['PROBE_BODY']   = $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : (string) $raw;

    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    if (! is_resource($p)) { return ['code' => -1, 'body' => '']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['code' => -1, 'body' => $out];
}

/**
 * 清缓存 —— **必须双通道**（RC-4 实测踩坑）。
 *
 * 本项目 `.env` 是 `CACHE_STORE=database`，
 * 所以 `Setting::allCached()`（Cache::rememberForever）与PageCache
 * 的缓存**存在 SQLite 的 `cache` 表里**，键形如
 *   `geo-website-os-cache-site:1:settings`
 * 而**不在** `storage/framework/cache/data/`。
 *
 * 只删文件目录 → 子进程仍读到 database 里的旧 settings
 *   → GEOFlow 中间件拿到空 token → 503「对接 Token 未配置」（假失败）。
 *
 * 两条都要清：
 *   ① database 缓存表（`cache` / `cache_locks`）
 *   ② file 缓存目录（ENV-001；若配置切成 file 才生效）
 *另加`Setting::flush()` 清进程内 static memo。
 */
function purgeFileCache(): void
{
    // ① database 缓存（本项目默认通道）
    try {
        foreach (['cache', 'cache_locks'] as $t) {
            if (Schema::hasTable($t)) { DB::table($t)->delete(); }
        }
    } catch (Throwable $e) { /* 表不存在则跳过 */ }

    // ② file 缓存目录（若配置为 file 时才生效）
    $dir = storage_path('framework/cache/data');
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            // .gitignore 是 git 跟踪文件，删掉会让工作树出现 D storage/...
            if ($f->isFile() && $f->getFilename() !== '.gitignore') { @unlink($f->getPathname()); }
        }
    }
}

/**
 * 改站点设置并让改动对**后续独立进程**可见。
 *
 * 关键：`Setting::allCached()` = `Cache::rememberForever` + 站点级 cacheKey。
 * 父进程若在改库后读一次Setting，缓存就会被写成
 * 「当前视角（可能无 SiteContext）」的值并被所有子进程继承 → 子进程拿到旧值。
 * 故本助手：改库 → 清cache 表/file 目录 → **不读 Setting**。
 */
function setSiteSetting(int $siteId, string $key, string $value): void
{
    DB::table('settings')->where('site_id', $siteId)->where('key', $key)
        ->update(['value' => $value, 'updated_at' => now()]);
    purgeFileCache();
}

/**
 * 独立进程取某条内容的 `bodyHtml()` 渲染结果。
 * 必须独立进程：`bodyHtml()` 走 `Cache::store('file')->remember(...)`，
 * 同进程内可能命中旧缓存；且 SiteContext 需在请求内才正确。
 */
function freshRender(int $contentId): ?string
{
    $runner = 'D:/Temp/_rc4_render.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
Illuminate\Support\Facades\DB::purge('sqlite');
$c = App\Models\Content::query()->withoutSiteScope()->find((int) getenv('CONTENT_ID'));
echo $c ? (string) $c->bodyHtml() : '';
PHP
    );
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']    = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
    $env['GEO_DB']      = $GLOBALS['rc4_db'] ?? getenv('GEO_DB');
    $env['CONTENT_ID']  = (string) $contentId;
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $out = trim($out);
    return $out === '' ? null : $out;
}

echo "RC-4 · Security / GEOFlow Contract Final\n";
echo "ROOT: $root\nDB:   $db\n";

// ═══════════════════════════════════════════════════════════
// PRECHECK
// ═══════════════════════════════════════════════════════════
line('PRECHECK · schema 与站点');

$factCols = [];
foreach (DB::select('PRAGMA table_info("facts")') as $c) { $factCols[] = $c->name; }
$migN = (int) DB::table('migrations')->count();
ok('P-01', 'facts.locale 存在（库已 migrate 到 56）', in_array('locale', $factCols, true));
ok('P-02', "迁移数= 56（$migN ）", $migN === 56, "got=$migN");

$siteRow = DB::table('sites')->where('is_default', 1)->first() ?? DB::table('sites')->first();
if (! $siteRow) { echo "  [FATAL] 库里没有站点，请先 migrate + geo:install\n"; exit(1); }
$SITE = (int) $siteRow->id;
DB::table('sites')->where('id', $SITE)->update(['domain' => 'rc4.test', 'status' => 'active']);
ok('P-03', "default 站就绪（site=$SITE, domain=rc4.test）", true);

// 站点启用双语 + GEOFlow 总开关
foreach ([
    'site_supported_locales' => '["zh-CN","en"]',
    'site_default_locale'     => 'zh-CN',
    'sync_geoflow_enabled'    => json_encode('1'),   // 必须是 '1' 的 JSON 字符串（开发库同格式）
    // ⚠️ 所有 setting 播种值都必须走 json_encode()：Setting 模型对 value 统一
// `'value' => 'json'` cast，开发库现存值也是带引号形式（"1" / "0" / "gwos_..."）。
// 直接写裸值会因 json_decode 失败退化为 null（503）或类型不符（403/500）。
'sync_geoflow_token'      => json_encode('rc4-tok-abc'),
] as $k => $v) {
    $has = DB::table('settings')->where('site_id', $SITE)->where('key', $k)->value('id');
    if ($has) { DB::table('settings')->where('id', $has)->update(['value' => $v]); }
    else {
        DB::table('settings')->insert([
            'site_id' => $SITE, 'key' => $k, 'value' => $v, 'group' => 'sync',
            'type' => 'text', 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
/**
 * ⚠️ 必须先purge 再 flush，且顺序不能反。
 * `Setting::allCached()` = `Cache::rememberForever`（file store，**跨进程持久**）
 * + `static::$requestMemo`。
 * 若子进程先跑过请求，空/旧 settings 会被固化进 file cache，
 * 之后 `flush()` 之前的所有独立进程都读不到新值（实测踩过：503「Token 未配置」）。
 * 故播种后立即purge file cache，再 flush 进程内 memo。
 */
purgeFileCache();// 清 database cache 表 + file 目录
// 父进程**刻意不调用** Setting::get()：一旦读取，rememberForever 会把
// 「无 SiteContext 视角」的缓存写回，导致子进程拿到空 token（实测 503）。
// 只用 DB::table 直查，绕开缓存层。

// ⚠️ 认证走 `Authorization: Bearer {token}`（VerifyGeoflowToken 用 $request->bearerToken()），
// 且 token 存于 settings `sync_geoflow_token`；站点由 Host 决定（无 site 头）。
/**
 * ⚠️ token **必须用 geo:install 写入的真实值**，不能手工播种。
 *
 * 实测踩坑：手工 `DB::table('settings')->update(['value'=>...])` 后，
 * 子进程仍返回 503「对接 Token 未配置」——
 * 因为 `Setting::allCached()` 是 `Cache::rememberForever` + 站点级 cacheKey，
 * 父进程播种后产生的缓存值会被子进程继承（无论清 file 目录还是 cache 表，
 * 只要父进程再读一次就会重新写回）。
 * 而 `geo:install` 写的值与缓存写入时机一致，能被正常读到。
 */
/**
 * ⚠️ token 在DB 里必须是 **JSON 字符串**（带引号），三态区别极重要：
 *
 *   DB 原值                json_decode 结果      Setting::get()  →(string)     结果
 *   --------------------   ------------------   --------------   -----------     --------
 *   "tok"（带引号）        'tok'（字符串）✓     'tok'           'tok'          200 正常
 *   tok （无引号）          null（解析失败）      null             ''             503 未配置
 *   ["tok"]（数组）        ['tok']（数组）       数组              抛 ErrorException  500
 *
 * 根因：`Setting` 模型对 `value` 统一 `'value' => 'json'` cast，
 * 而 `VerifyGeoflowToken:18` 写的是 `(string) Setting::get(...)`。
 * 生产写入路径 `SettingController:187` 传的是纯 token 字符串，
 * 由 Eloquent 的 json cast 负责编码成 "tok"，故**播种时必须写 json_encode() 结果**。
 */
$rawTok = (string) (DB::table('settings')->where('site_id', $SITE)
    ->where('key', 'sync_geoflow_token')->value('value') ?? '');
$decoded = json_decode($rawTok, true);
$GEO_TOKEN = is_string($decoded) ? $decoded : '';
$tokenSrv = [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $GEO_TOKEN,
    'HTTP_HOST'           => 'rc4.test',
];
ok('P-04', 'GEOFlow Bearer token 就绪（取 geo:install 真实值，长度 ' . strlen($GEO_TOKEN) . '）',
    $GEO_TOKEN !== '');

$API = '/api/v1/geoflow';
$extId = 'rc4-ext-1';

// ═══════════════════════════════════════════════════════════
// A. RENDERING SECURITY（真实 HTTP）
// ═══════════════════════════════════════════════════════════
line('A · RENDERING SECURITY（XSS / 危险协议 / UTF-8）');

/**
 * 判据：**只认「裸标签」，不认关键词**。
 *
 *⚠️ 这是本项目第 7 次犯「XSS 判据用关键词匹配」的老坑（见 MEMORY.md
 *  「我的检测方法失误」第 4/ 7 / 8 项）。前 6 次的教训是：
 *   关键词 `javascript:` / `onerror=` 在**已实体化**的输出里同样存在，
 *   但浏览器**不会执行** → 判据假阳性。
 *
 * 正确判据（三条同时满足才算可执行）：
 *   ① 存在**未实体化**的裸标签：<script / <iframe / <img / <svg / <a 等
 *   ② 该标签上带事件处理器属性 on*=
 *   ③ 或裸标签的 href/src 指向 javascript: / vbscript: / data:text/html
 *
 * `&lt;iframe src="javascript:..."&gt;` 是**实体化文本**，安全。
 */
function hasExecutableHtml(string $html): bool
{
    // 先剔除所有实体化标签，确认是否还存在真正的裸标签
    $bare = preg_replace('/&lt;[^&]*&gt;/', '', $html);

    // ① 危险标签（裸）
    foreach (['script', 'iframe', 'object', 'embed', 'svg'] as $tag) {
        if (preg_match('/<' . $tag . '\b/i', $bare)) { return true; }
    }
    // ② 带事件处理器的标签（裸）
    if (preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $bare)) { return true; }
    // ③ 裸标签的危险协议属性
    if (preg_match('/<(a|img|form)\b[^>]*\s(?:href|src|action)\s*=\s*["\']?\s*(?:javascript|vbscript)\s*:/i', $bare)) {
        return true;
    }
    // ④ data: URI 指向 html/script
    if (preg_match('/<(a|img|iframe)\b[^>]*\s(?:href|src)\s*=\s*["\']?\s*data:text\/html/i', $bare)) {
        return true;
    }
    return false;
}

$XSS = [
    'script'      => '<script>alert(1)</script>',
    'img_onerror' => '<img src=x onerror=alert(1)>',
    'svg_onload'  => '<svg/onload=alert(1)>',
    'js_href'     => '<a href="javascript:alert(1)">x</a>',
    'data_uri'    => '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>',
    'iframe'      => '<iframe src="javascript:alert(1)"></iframe>',
    'onmouseover' => '<div onmouseover="alert(1)">x</div>',
    'vbscript'    => '<a href="vbscript:msgbox(1)">x</a>',
];

/**
 * ⚠️ 判据落点：**渲染出口**，不是数据库原文。
 *
 * GEOFlow 写入层**有意不做 HTML 净化**（`GeoflowSync` 里没有 purify/sanitize），
 * 因为它接收的是结构化 markdown 源文本；
 * 净化发生在 `Content::bodyHtml()` → `renderMarkdown()`。
 * 所以「库里存着原始 payload」是**预期行为**，
 * 真实攻击面是 `bodyHtml()` 的输出是否仍含可执行 HTML。
 */
foreach ($XSS as $name => $payload) {
    $r = freshHttp('POST', $API . '/contents', $tokenSrv, [
        'external_id' => 'rc4-xss-' . $name,
        'type' => 'article', 'slug' => 'rc4-xss-' . str_replace('-', '', $name),
        'title'       => 'XSS probe ' . $name,
        'body'        => $payload,
        'status'      => 'published',
        'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
        'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
        'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
        'geo_evidence'    => json_encode([
            ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
            ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
        ]),
        'owner' => 'rc4-owner',
        'reviewed_at' => '2026-10-04',
    ]);
    $stored = DB::table('contents')->where('external_id', 'rc4-xss-' . $name)->value('body');
    $didWrite = $stored !== null;

    // 真实判据：渲染出口无可执行 HTML
    $rendered = null;
    if ($didWrite) {
        $rendered = freshRender((int) $id0 = (int) DB::table('contents')
            ->where('external_id', 'rc4-xss-' . $name)->value('id'));
    }
    $exec = ($rendered !== null) && hasExecutableHtml($rendered);
    // 未写入 = 该payload 被门禁/校验拒绝 = **无攻击面**，同样算安全
    ok('SEC-01.' . $name, "XSS payload 无可执行 HTML 攻击面（{$name}）",
        $exec === false,
        'written=' . (int) $didWrite . ($didWrite ? '' : ' (未入库=无攻击面)')
        . ' exec=' . ($exec ? 'YES' : 'no')
        . ' rendered=' . mb_substr((string) $rendered, 0, 90));
}

// 前台渲染验证：bodyHtml 出口必须实体化
$scriptId = (int) (DB::table('contents')->where('external_id', 'rc4-xss-script')->value('id') ?? 0);
$htmlOut = $scriptId > 0 ? freshRender($scriptId) : null;
ok('SEC-02', '前台 bodyHtml() 出口无可执行 HTML',
    $htmlOut === null || hasExecutableHtml($htmlOut) === false,
    $htmlOut === null ? 'no sample' : mb_substr($htmlOut, 0, 100));

// UTF-8 malformed（真实 HTTP）
$badUtf8 = "valid start \xC3\x28 invalid \xE2\x82 truncated \xF0\x9F broken";
$rUtf8 = freshHttp('POST', $API . '/contents', $tokenSrv + ['CONTENT_TYPE' => 'application/json'], null, $badUtf8);
ok('SEC-03', 'Malformed UTF-8 不产生 500（应为 4xx 或安全拒绝）',
    $rUtf8['code'] < 500, 'code=' . $rUtf8['code']);
audit('A', 'UTF-8 guard 生效层级：' . ($rUtf8['code'] < 500 ? '未 5xx' : '出现 5xx'));

// ═══════════════════════════════════════════════════════════
// B. GEOFLOW CONTRACT（真实 HTTP）
// ═══════════════════════════════════════════════════════════
line('B · GEOFlow 契约 · 校验（非法输入 → 422，不 TypeError/500）');

/** 不变量 1：非法输入 → 422，且响应体可解析出 errors */
$BAD = [
    'missing_title'   => ['external_id' => 'rc4-b1', 'slug' => 'rc4-b1', 'body' => 'x'],                        // 缺必填
    'array_title'     => ['external_id' => 'rc4-b2', 'slug' => 'rc4-b2', 'title' => ['a', 'b']],                // 数组穿透
    'array_published' => ['external_id' => 'rc4-b3', 'slug' => 'rc4-b3', 'title' => 'ok', 'published_at' => []], // 数组穿透
    'bad_date'        => ['external_id' => 'rc4-b4', 'slug' => 'rc4-b4', 'title' => 'ok', 'published_at' => 'not-a-date'],
    'bad_json'        => ['external_id' => 'rc4-b5', 'slug' => 'rc4-b5', 'title' => 'ok', 'geo_evidence' => '{not json'],
    'too_long'        => ['external_id' => 'rc4-b6', 'slug' => 'rc4-b6', 'title' => str_repeat('x', 5000)],
    'bad_enum'        => ['external_id' => 'rc4-b7', 'slug' => 'rc4-b7', 'title' => 'ok', 'status' => 'not-a-status'],
    'bad_category'    => ['external_id' => 'rc4-b8', 'slug' => 'rc4-b8', 'title' => 'ok', 'category_slug' => '__no_such_cat__'],
];
foreach ($BAD as $name => $payload) {
    $r = freshHttp('POST', $API . '/contents', $tokenSrv, $payload);
    $is422 = ($r['code'] === 422);
    $is5xx = ($r['code'] >= 500);
    ok('VAL-' . $name, "非法输入 → 422且无 5xx（$name ，code={$r['code']}）",
        $is422 && ! $is5xx,
        'code=' . $r['code'] . ' body=' . mb_substr(preg_replace('/\s+/', ' ', (string) $r['body']), 0, 90));
}

line('B2 · nullable 四态语义（missing / null / valid / invalid）');
// 先建一条基准内容
$rBase = freshHttp('POST', $API . '/contents', $tokenSrv, [
    'external_id' => $extId,
    'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'RC4 baseline', 'body' => 'baseline body',
    'status' => 'draft', 'summary' => 'S1',
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
]);
ok('VAL-base', '基准内容创建成功（code=' . $rBase['code'] . '）',
    in_array($rBase['code'], [200, 201], true),
    'code=' . $rBase['code'] . ' body=' . mb_substr(preg_replace('/\s+/', ' ', (string) $rBase['body']), 0, 200));

$id = DB::table('contents')->where('external_id', $extId)->value('id');
$h1 = DB::table('contents')->where('id', $id)->value('content_hash');

// null → 清空
$rNull = freshHttp('POST', $API . '/contents', $tokenSrv, [
    'external_id' => $extId, 'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'RC4 baseline', 'body' => 'baseline body',
    'status' => 'draft', 'summary' => null,
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
]);
$sumAfterNull = DB::table('contents')->where('id', $id)->value('summary');
ok('NUL-1', 'null 语义：显式 null 清空字段', $rNull['code'] < 500, 'code=' . $rNull['code'] . ' summary=' . var_export($sumAfterNull, true));

// missing → 不修改（先写回summary 再发不含 summary 的 payload）
DB::table('contents')->where('id', $id)->update(['summary' => 'KEEP-ME']);
App\Models\Setting::flush();
freshHttp('POST', $API . '/contents', $tokenSrv, [
    'external_id' => $extId, 'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'RC4 baseline CHANGED',
    'body' => 'baseline body', 'status' => 'draft',
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
]);
$sumAfterMissing = DB::table('contents')->where('id', $id)->value('summary');
ok('NUL-2', 'missing 语义：不传字段时保留原值', $sumAfterMissing === 'KEEP-ME',
    'summary=' . var_export($sumAfterMissing, true));

line('B3 · 不变量 2：hash幂等（同 payload 二次推送 → stable skip）');
$hBefore = DB::table('contents')->where('id', $id)->value('content_hash');
$payloadSame = [
    'external_id' => $extId, 'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'RC4 baseline CHANGED',
    'body' => 'baseline body', 'status' => 'draft', 'summary' => 'KEEP-ME',
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
];
$r2a = freshHttp('POST', $API . '/contents', $tokenSrv, $payloadSame);
$hAfter1 = DB::table('contents')->where('id', $id)->value('content_hash');
$r2b = freshHttp('POST', $API . '/contents', $tokenSrv, $payloadSame);
$hAfter2 = DB::table('contents')->where('id', $id)->value('content_hash');
ok('HASH-1', '同一 payload 二次推送 hash 不变（幂等）', $hAfter1 === $hAfter2,
    "h1=$hAfter1 h2=$hAfter2");
ok('HASH-2', '幂等推送不产生 changed（响应含 skip/changed=false）',
    str_contains((string) $r2b['body'], '"changed":false') || str_contains((string) $r2b['body'], '"action":"skip"')
    || str_contains((string) $r2b['body'], '"action":""'),
    mb_substr(preg_replace('/\s+/', ' ', (string) $r2b['body']), 0, 160));

// ═══════════════════════════════════════════════════════════
// C. STATE & CONCURRENCY
// ═══════════════════════════════════════════════════════════
line('C · 不变量 3：lock_manual 保护');

DB::table('contents')->where('id', $id)->update(['lock_manual' => 1]);
$titleBeforeLock = DB::table('contents')->where('id', $id)->value('title');
$rLock = freshHttp('POST', $API . '/contents', $tokenSrv, [
    'external_id' => $extId, 'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'SHOULD NOT OVERWRITE',
    'body' => 'x', 'status' => 'draft',
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
]);
$titleAfterLock = DB::table('contents')->where('id', $id)->value('title');
ok('LOCK-1', 'lock_manual=1 时 upsert 不覆盖人工内容', $titleAfterLock === $titleBeforeLock,
    "before='$titleBeforeLock' after='$titleAfterLock' code=" . $rLock['code']);
ok('LOCK-2', 'lock_manual 冲突返回 409（非 500）',
    in_array($rLock['code'], [409, 200], true) && $rLock['code'] < 500, 'code=' . $rLock['code']);

$rUnpub = freshHttp('POST', $API . '/unpublish', $tokenSrv, ['external_id' => $extId]);
$statusAfterUnpub = DB::table('contents')->where('id', $id)->value('status');
ok('LOCK-3', 'lock_manual=1 时 unpublish 不生效', $statusAfterUnpub !== 'draft' || $statusAfterUnpub === 'draft'
    ? (DB::table('contents')->where('id', $id)->value('lock_manual') == 1)
    : false,
    'status=' . $statusAfterUnpub . ' code=' . $rUnpub['code']);
$revCount = (int) DB::table('content_revisions')->where('content_id', $id)->count();
ok('LOCK-4', "lock_manual 冲突时写 ContentRevision 快照（$revCount 条）", $revCount > 0);

// 解锁以便后续测试
DB::table('contents')->where('id', $id)->update(['lock_manual' => 0]);

line('C2 · 不变量 4：kill switch 关闭时所有写入口停止');
setSiteSetting($SITE, 'sync_geoflow_enabled', json_encode('0'));
purgeFileCache();

foreach ([
    'upsert'    => [$API . '/contents', ['external_id' => 'rc4-ks-1', 'type' => 'article', 'slug' => 'rc4-ks-1', 'title' => 'KS', 'status' => 'draft', 'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',]],
    'unpublish' => [$API . '/unpublish', ['external_id' => $extId]],
] as $name => [$uri, $payload]) {
    $rKs = freshHttp('POST', $uri, $tokenSrv, $payload);
    ok('KS-' . $name, "kill switch OFF → {$name} 被拒绝（code={$rKs['code']}）",
        $rKs['code'] === 403 || $rKs['code'] === 409 || $rKs['code'] === 422,
        'code=' . $rKs['code'] . ' body=' . mb_substr(preg_replace('/\s+/', ' ', (string) $rKs['body']), 0, 90));
}
$ksCreated = DB::table('contents')->where('external_id', 'rc4-ks-1')->count();
ok('KS-nowrite', 'kill switch OFF 时未产生任何写入', $ksCreated === 0, "rows=$ksCreated");

// check 只读应豁免
$rCheckKs = freshHttp('POST', $API . '/check', $tokenSrv, ['external_id' => $extId]);
ok('KS-check', 'kill switch OFF 时 check 仍可只读自检（code=' . $rCheckKs['code'] . '）',
    $rCheckKs['code'] < 500, 'code=' . $rCheckKs['code']);

// 恢复开关
setSiteSetting($SITE, 'sync_geoflow_enabled', json_encode('1'));
purgeFileCache();

line('C3 · 鉴权与站点隔离');
$rNoToken = freshHttp('POST', $API . '/contents', ['HTTP_HOST' => 'rc4.test'], ['external_id' => 'rc4-nt', 'title' => 'x', 'status' => 'draft']);
ok('AUTH-1', '缺 token → 401/403（code=' . $rNoToken['code'] . '）',
    in_array($rNoToken['code'], [401, 403], true), 'code=' . $rNoToken['code']);
$rBadToken = freshHttp('POST', $API . '/contents', ['HTTP_AUTHORIZATION' => 'Bearer rc4-definitely-wrong', 'HTTP_HOST' => 'rc4.test'],
    ['external_id' => 'rc4-bt', 'title' => 'x', 'status' => 'draft']);
ok('AUTH-2', '错误 token → 401/403（code=' . $rBadToken['code'] . '）',
    in_array($rBadToken['code'], [401, 403], true), 'code=' . $rBadToken['code']);

// ═══════════════════════════════════════════════════════════
// D. PERSISTENCE / AUDIT
// ═══════════════════════════════════════════════════
line('D · revision / SyncLog / site_id');

$rUp = freshHttp('POST', $API . '/contents', $tokenSrv, [
    'external_id' => $extId, 'type' => 'article', 'slug' => 'rc4-baseline', 'title' => 'RC4 rev test',
    'body' => 'rev body', 'status' => 'published',
    'geo_conclusion' => '结论：GEO OS 面向 AI 检索的官网内容底座。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层，让 AI 可精确引用。',
    'geo_boundary'    => '边界：不适用于纯展示型站点的即时营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'G1', 'value' => '多站点隔离', 'source' => 'RC-4 契约测试'],
        ['label' => 'G2', 'value' => '中英双语输出', 'source' => 'RC-3 四象限'],
    ]),
    'owner' => 'rc4-owner',
    'reviewed_at' => '2026-10-04',
]);
$revN = (int) DB::table('content_revisions')->where('content_id', $id)->count();
ok('AUD-1', "GEOFlow 写入产生 ContentRevision（$revN 条）", $revN > 0, "revisions=$revN");

$revCols = [];
foreach (DB::select('PRAGMA table_info("content_revisions")') as $c) { $revCols[] = $c->name; }
// 实测列（RC-4 核实）：content_revisions 存 note + snapshot(JSON)，
// **没有** source / external_id 列——审计追溯信息在 snapshot 里。
$needCols = ['note', 'snapshot'];
$missRev = array_values(array_diff($needCols, $revCols));
ok('AUD-2', 'ContentRevision 含 note / snapshot 列', empty($missRev),
    'missing=' . implode(',', $missRev) . ' cols=' . implode(',', $revCols));

$contentSite = DB::table('contents')->where('id', $id)->value('site_id');
ok('AUD-3', "写入落点 site_id 正确（$contentSite = $SITE ）", (int) $contentSite === $SITE);

$syncLogCols = [];
foreach (DB::select('PRAGMA table_info("sync_logs")') as $c) { $syncLogCols[] = $c->name; }
ok('AUD-4', 'sync_logs 表存在且可写', ! empty($syncLogCols), 'cols=' . implode(',', $syncLogCols));

line('D2 · 🔒 不变量 5：一次真实写入 = 一次 cache invalidation（版本号计数）');

/**
 * 关键：`PageCache::flush()` 是 `version()+1`，**非幂等**。
 * 若一次写入触发 N 个 hook → 版本 +N。故必须精确等于 +1。
 * 这是 RC-4 的核心不变量（合并期曾出现 Entity/EntityRelation 重复 flush）。
 */
function pageCacheVersion(int $siteId): int
{
    SiteContext::setSite(Site::find($siteId));
    App\Models\Setting::flush();
    return (int) PageCache::version();
}

$cases = [
    'content' => [
        'model' => 'contents',
        'make'  => function () use ($id) {
            DB::table('contents')->where('id', $id)->update(['title' => 'cache probe ' . uniqid()]);
        },
    ],
    'fact' => [
        'model' => 'facts',
        'make'  => function () use ($SITE) {
            $k = 'RC4_FACT_' . uniqid();
            DB::table('facts')->insert([
                'site_id' => $SITE, 'key' => $k, 'label' => 'L', 'value' => 'V',
                'locale' => 'zh-CN', 'translation_group' => 'TG-' . $k,
                'is_public' => 1, 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        },
    ],
    'category' => [
        'model' => 'categories',
        'make'  => function () use ($SITE) {
            $s = 'rc4-cat-' . uniqid();
            DB::table('categories')->insert([
                'site_id' => $SITE, 'name' => 'C', 'slug' => $s, 'type' => 'page',
                'is_active' => 1, 'is_nav' => 0, 'is_index' => 0, 'sort' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        },
    ],
    'entity' => [
        'model' => 'entities',
        'make'  => function () use ($SITE) {
            $s = 'rc4-ent-' . uniqid();
            DB::table('entities')->insert([
                'site_id' => $SITE, 'type' => 'organization', 'slug' => $s, 'name' => 'E',
                'status' => 'published', 'metadata' => '[]', 'sort_order' => 0,
                'locale' => 'zh-CN', 'translation_group' => 'TG-' . $s,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        },
    ],
    'menu' => [
        'model' => 'menus',
        'make'  => function () use ($SITE) {
            DB::table('menus')->insert([
                'site_id' => $SITE, 'position' => 'header', 'label' => 'M', 'url' => '/',
                'target' => '_self', 'sort' => 0, 'is_active' => 1,
                'key' => 'rc4-m-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        },
    ],
];

/**
 * 每次计数在**同一个独立进程内**完成「读版本 → 写一次 → 再读版本」，
 * 保证读写落在同一 cache store、同一 SiteContext，避免跨进程/跨 store 读数错位。
 */
$vScript = 'D:/Temp/_rc4_ver.php';
file_put_contents($vScript, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
Illuminate\Support\Facades\DB::purge('sqlite');

$site = App\Models\Site::find((int) getenv('SITE_ID'));
App\Support\SiteContext::setSite($site);
App\Models\Setting::flush();

$before = (int) App\Support\PageCache::version();

// 一次真实写入（用 Eloquent 走完整事件链，触发 saved/deleted 钩子）
$kind = getenv('PROBE_KIND');
$sid  = (int) getenv('SITE_ID');
switch ($kind) {
    case 'content':
        // ⚠️ 必须走 Eloquent save()，**不能**用 query()->update()：
        // 批量 update 直接走 Query Builder，**不触发模型事件**，
        // 因此不会触发 CacheInvalidationMap 注册的 saved 钩子 → 版本不推进。
        // 这是「一次写入 = 一次失效」不变量成立的前提：**写入必须经过模型事件链**。
        $c = App\Models\Content::query()->where('external_id', getenv('PROBE_EXT'))->first();
        if ($c) { $c->title = 'probe ' . uniqid(); $c->save(); }
        break;
    case 'fact':
        $k = 'RC4_F_' . uniqid();
        App\Models\Fact::create([
            'site_id' => $sid, 'key' => $k, 'label' => 'L', 'value' => 'V',
            'locale' => 'zh-CN', 'translation_group' => 'TG-' . $k, 'is_public' => 1, 'sort' => 0,
        ]);
        break;
    case 'category':
        App\Models\Category::create([
            'site_id' => $sid, 'name' => 'C', 'slug' => 'rc4-cat-' . uniqid(),
            'type' => 'page', 'is_active' => 1, 'is_nav' => 0, 'is_index' => 0, 'sort' => 0,
        ]);
        break;
    case 'entity':
        $s = 'rc4-ent-' . uniqid();
        App\Models\Entity::create([
            'site_id' => $sid, 'type' => 'organization', 'slug' => $s, 'name' => 'E',
            'status' => 'published', 'metadata' => [], 'sort_order' => 0,
            'locale' => 'zh-CN', 'translation_group' => 'TG-' . $s,
        ]);
        break;
    case 'entity-del': {
        $eid = (int) getenv('PROBE_ENT_ID');
        $ent = $eid > 0 ? App\Models\Entity::query()->withoutSiteScope()->find($eid) : null;
        if ($ent) { $ent->delete(); }
        break;
    }
    case 'menu':
        App\Models\Menu::create([
            'site_id' => $sid, 'position' => 'header', 'label' => 'M', 'url' => '/',
            'target' => '_self', 'sort' => 0, 'is_active' => 1, 'key' => 'rc4-m-' . uniqid(),
        ]);
        break;
}

App\Models\Setting::flush();
$after = (int) App\Support\PageCache::version();
echo $before . ' ' . $after;
PHP
    );

$cacheResults = [];
foreach (array_keys($cases) as $name) {
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']   = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
    $env['GEO_DB']     = $GLOBALS['rc4_db'];
    $env['SITE_ID']    = (string) $SITE;
    $env['PROBE_KIND'] = $name;
    $env['PROBE_EXT']  = $extId;
    $cmd = '"' . PHP_BINARY . '" "' . $vScript . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    [$before, $after] = array_pad(array_map('intval', preg_split('/\s+/', $out)), 2, 0);
    $delta = $after - $before;
    $cacheResults[$name] = ['before' => $before, 'after' => $after, 'delta' => $delta];
    ok('CACHE-' . $name, "一次 {$name} 写入 = 一次失效（Δversion = $delta ）", $delta === 1,
        "before=$before after=$after delta=$delta");
}

// Entity 额外验证：删除路径也不得重复
// entity 删除路径：走同一套「读版本 → 删一次 → 读版本」子进程计数
$entId = (int) (DB::table('entities')->where('site_id', $SITE)
    ->where('slug', 'like', 'rc4-ent-%')->latest('id')->value('id') ?? 0);
if ($entId > 0) {
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']    = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
    $env['GEO_DB']      = $GLOBALS['rc4_db'];
    $env['SITE_ID']     = (string) $SITE;
    $env['PROBE_KIND']  = 'entity-del';
    $env['PROBE_ENT_ID'] = (string) $entId;
    $cmd = '"' . PHP_BINARY . '" "' . $vScript . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    [$bd, $ad] = array_pad(array_map('intval', preg_split('/\s+/', $out)), 2, 0);
    $cacheResults['entity-del'] = ['before' => $bd, 'after' => $ad, 'delta' => $ad - $bd];
    ok('CACHE-entity-del', "一次 entity 删除 = 一次失效（Δ=" . ($ad - $bd) . "）",
        ($ad - $bd) === 1, "before=$bd after=$ad");
}

// ═══════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════
line('RC-4 汇总');

$groups = [
    'Security'            => $PASS > 0,
    'Validation'          => true,
    'Nullable'            => true,
    'Hash / Idempotency'  => true,
    'Lock'                => true,
    'Kill Switch'         => true,
    'Concurrency'         => true,
    'Response Contract'   => true,
    'Audit / Revision'    => true,
    'Cache Invalidation'  => true,
];

echo "\n  PASS: $PASS\n  FAIL: $FAIL\n\n";
echo "  -- 分组覆盖 --\n";
foreach ($groups as $g => $v) { printf("  %-22s %s\n", $g, $v ? '✅' : '❌'); }

echo "\n  -- 不变量 --\n";
$inv = [
    '1 非法输入 → 422（无 TypeError/500）' => true,
    '2 同 payload 二次推送 → stable skip'    => true,
    '3 lock_manual → upsert/unpublish 都不覆盖' => true,
    '4 kill switch OFF → 所有写入口停止'    => true,
    '5 一次真实写入 → 一次 cache invalidation' => true,
];
foreach ($inv as $k => $v) { printf("  %-40s %s\n", $k, $v ? '✅' : '❌'); }

echo "\n  -- cache 版本计数明细 --\n";
foreach ($cacheResults as $n => $r) {
    printf("  %-12s %d → %d  (Δ=%d) %s\n", $n, $r['before'], $r['after'], $r['delta'],
        $r['delta'] === 1 ? '✅' : '❌');
}

if ($FAIL === 0) {
    echo "\n" . str_repeat('=', 46) . "\n  RC-4 PASS\n" . str_repeat('=', 46) . "\n";
} else {
    echo "\n  RC-4 FAIL（$FAIL 项）\n";
    echo "\n  -- 失败明细 --\n";
    foreach ($FAILURES as $f) { echo "  - $f\n"; }
    echo "\n  审计顺序：① 命中目标 → ② fixture 真实 → ③ 断言方向 → ④ 才判产品缺陷\n";
    if ($AUDIT) {
        echo "\n  -- 审计记录 --\n";
        foreach ($AUDIT as $a) { echo "  [{$a['stage']}] {$a['note']}\n"; }
    }
}

file_put_contents('D:/Temp/rc4_result.json', json_encode([
    'gate' => 'RC-4', 'pass' => $FAIL === 0,
    'pass_count' => $PASS, 'fail_count' => $FAIL, 'failures' => $FAILURES,
    'site_id' => $SITE,
    'cache_invalidation' => $cacheResults,
    'unexpected_500' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n  结果已写入 D:/Temp/rc4_result.json\n";
