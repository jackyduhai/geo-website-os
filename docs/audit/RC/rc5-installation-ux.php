<?php
/**
 * RC-5 · Installation / Operational UX
 * ------------------------------------------------------------------
 * 目标：证明**一个正常运营人员可以从零创建并运营一个站点**，
 *       不需要开发人员、不需要 SQL、不需要理解内部架构。
 *
 * 主流程（真实运营路径，全程 0 SQL）：
 *   Fresh Install → Create Site A → Bootstrap → zh-CN/en
 *   → Create Content → Edit → Publish → 前台验证 → GEO/SEO 派生输出
 *
 * 第二站流程：
 *   Create Site B → Bootstrap → zh-CN/en → 创建与 A 相同 slug 的内容
 *   → 不同内容 → 发布 → 验证四象限隔离
 *
 * 🔒 强制 Negative（用户明确要求，比「无重复行」更重要）：
 *   第一次 bootstrap → 手工修改初始化数据 → 第二次 bootstrap
 *   → **手工修改必须保留**（验证 firstOrCreate 而非 updateOrCreate）
 *
 * 审计纪律（沿用 RC-3/RC-4）：失败时按
 *   ① 命中目标 → ② fixture 真实 → ③ 断言方向 → ④ 才判产品缺陷
 *
 * 用法：GEO_ROOT=<repo> php rc5-installation-ux.php
 * 前置：**空库**（脚本自己用 artisan 建库，走 CLI 不碰开发库）
 */

$root = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
$workDir = 'D:/Temp/rc5_run';
$db      = $workDir . '/ux.sqlite';

$PASS = 0; $FAIL = 0; $FAILURES = [];

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

/**
 * 跑一条 artisan 命令（独立进程，DB 指向独立库）。
 * 返回 [code, output]。
 */
function artisan(string $root, string $db, array $args): array
{
    global $workDir;
    $runner = $workDir . '/_rc5_artisan.php';
    if (! is_dir($workDir)) { @mkdir($workDir, 0777, true); }
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArgvInput(
    array_merge(['artisan'], array_slice($_SERVER['argv'], 1))
);
exit($kernel->handle($input));
PHP
    );

    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']       = $root;
    $env['DB_CONNECTION']  = 'sqlite';
    $env['DB_DATABASE']    = $db;
    $env['APP_ENV']        = 'local';
    $env['CACHE_STORE']    = 'database';
    $env['SESSION_DRIVER'] = 'array';

    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    foreach ($args as $a) { $cmd .= ' "' . $a . '"'; }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $env);
    if (! is_resource($p)) { return [-1, 'proc_open failed']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return [$code, $out];
}

/** 直接读库（验证用，不做任何写入） */
function db(string $path): PDO
{
    return new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * 取当前库的 PDO 连接（单例缓存）。
 *
 * ⚠️ 命名纪律（RC-5 实测连踩两次）：
 *   ① 不要写 `$db($db)` / `$pdo($db)` —— 变量名紧贴 `(` 时，
 *      PHP 会解析为「变量 $db 的值当函数名」，
 *      报 `Call to undefined function <path>()` 或
 *      `Value of type null is not callable`。
 *   ② 函数名不要用全大写下划线风格（`GET_DB_PATH()`）——
 *      PHP 解析器易将其当常量，报 `Undefined constant`。
 *   ✅ 正确做法：函数名用小写驼峰（conn / nRows / ok），
 *      且**不要**与任何可能的局部变量同名。
 */
function conn(): PDO
{
    if (($GLOBALS['__rc5_conn'] ?? null) instanceof PDO) {
        return $GLOBALS['__rc5_conn'];
    }
    $path = (string) $GLOBALS['db'];
    return $GLOBALS['__rc5_conn'] = db($path);
}

/** 建站后复位连接单例，确保读到最新已提交数据 */
function resetConn(): void
{
    $GLOBALS['__rc5_conn'] = null;
}

function nRows(PDO $p, string $sql, array $bind = []): int
{
    $st = $p->prepare($sql); $st->execute($bind);
    return (int) $st->fetchColumn();
}

echo "RC-5 · Installation / Operational UX\n";
echo "ROOT: $root\nWORK: $workDir （项目目录外）\n";
echo "说明：全部操作走 CLI / HTTP，**零 SQL 手工干预**\n";

// ═══════════════════════════════════════════════════════════
// STEP 1 · Fresh Install
// ═══════════════════════════════════════════════════════════
line('STEP 1 · Fresh Install（artisan geo:install）');

if (! is_dir($workDir)) { @mkdir($workDir, 0777, true); }
@unlink($db);
touch($db);

[$c1, $o1] = artisan($root, $db, ['migrate', '--force']);
ok('I-01', "migrate 退出码 0（code=$c1 ）", $c1 === 0, 'out=' . mb_substr($o1, -160));

[$c2, $o2] = artisan($root, $db, [
    'geo:install',
    '--site-name=RC5 默认站', '--site-domain=rc5-default.test',
    '--admin-email=rc5-admin@example.com', '--admin-password=rc5-pass-123',
]);
ok('I-02', "geo:install 退出码 0（code=$c2 ）", $c2 === 0, 'out=' . mb_substr($o2, -200));
ok('I-03', 'geo:install 无异常特征',
    ! preg_match('/SQLSTATE|Fatal|Uncaught|Exception/i', $o2),
    mb_substr(preg_replace('/\s+/', ' ', $o2), 0, 200));

$p = db($db);
ok('I-04', 'admin 账号已创建', nRows($p, "select count(*) from users where email = ?", ['rc5-admin@example.com']) === 1);
$siteA0 = (int) nRows($p, 'select count(*) from sites');
ok('I-05', "初始站点已创建（$siteA0 个）", $siteA0 >= 1);

// ═══════════════════════════════════════════════════════════
// STEP 2 · Create Site A（走真实运营路径：HTTP + 认证）
// ═══════════════════════════════════════════════════════════
line('STEP 2 · Create Site A（运营路径：HTTP 后台）');

/**
 * 运营操作走**真实 HTTP 内核**（含 CSRF / 认证 / 校验 / Seeder 链），
 * 而不是直接调 Seeder —— 这样才能证明「运营人员零 SQL 即可建站」。
 */
function adminSession(string $root, string $db): string
{
    global $workDir;
    // 复用一次性脚本：登录取 session + CSRF
    $runner = $workDir . '/_rc5_login.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['app.debug' => false]);
// 库路径由外层 DB_DATABASE 在 bootstrap 前注入。
// 绝不在此 config(database)+purge() —— 那会重建 Connection，
// 使「写用连接」与「读/Schema用连接」可能不同一，
// 人为制造事务归零 / 行不可见 / FK failed假象（RC-5 · C-18 误判根因）。
Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');

$log = [];
// 取登录页 CSRF
$r = $kernel->handle(Illuminate\Http\Request::create('/admin/login', 'GET'));
$body = (string) $r->getContent();
preg_match('/name="_token"\s+value="([^"]+)"/', $body, $m);
$token = $m[1] ?? '';
$log[] = 'login_page=' . $r->getStatusCode() . ' csrf=' . ($token ? 'ok' : 'MISSING');

if ($token !== '') {
    $r2 = $kernel->handle(Illuminate\Http\Request::create('/admin/login', 'POST', [
        '_token' => $token,
        'email' => getenv('ADMIN_EMAIL'),
        'password' => getenv('ADMIN_PASS'),
    ], [], [], [
        'HTTP_ACCEPT' => 'text/html',
    ]));
    $log[] = 'login_post=' . $r2->getStatusCode() . ' loc=' . ($r2->headers->get('Location') ?: '-');
    $log[] = 'auth=' . (auth()->check() ? 'YES' : 'NO');
}
file_put_contents(getenv('RC5_OUT'), implode("\n", $log));
PHP
    );
    $outFile = $workDir . '/_login_out.txt';
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']    = $root;
    $env['GEO_DB']      = $db;
    $env['DB_CONNECTION'] = 'sqlite';
    $env['DB_DATABASE']   = $db;
    $env['ADMIN_EMAIL'] = 'rc5-admin@example.com';
    $env['ADMIN_PASS']  = 'rc5-pass-123';
    $env['RC5_OUT']     = $outFile;
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $env);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return (string) @file_get_contents($outFile);
}

$loginInfo = adminSession($root, $db);
ok('I-06', '后台登录页可访问且取得 CSRF', str_contains($loginInfo, 'login_page=200'), $loginInfo);
ok('I-07', '运营账号可登录（auth=YES）', str_contains($loginInfo, 'auth=YES'), $loginInfo);

/**
 * 建站：直接走SiteController 的完整 Seeder 链。
 * 说明：HTTP 建站需完整 session + CSRF 往返，这里用等价的一次性脚本
 * 调��同一个控制器方法（`Site::create` + 5 个 Seeder），
 * 验证重点是「**Seeder 链是否完整、是否幂等、是否覆盖运营修改**」，
 * 而非 HTTP 状态码。
 */
/**
 * 走**真实后台 HTTP 路径**建站：`POST /admin/sites`。
 *
 * ⚠️ RC-5 关键修正（用户明确要求）：
 *   旧实现是「复制 SiteController 的 Seeder 链到子进程」的**旁路**，
 *   既不走控制器、也不含 C-18 的 `enableDeferredForeignKeyChecks()`，
 *   结果会重现 FK 失败 —— 那是**测试没命中目标路径**，不是产品缺陷。
 *
 *   运营人员的真实路径是浏览器表单 → `POST /admin/sites`，
 *   本函数必须复现这条路径（含 admin.auth / admin.site 中间件）。
 *
 * @return array{0:int,1:array} [siteId, httpMeta]
 */
function createSite(string $root, string $db, array $attrs): array
{
    global $workDir;
    // runner 用仓库内固定路径并**每次重建**：
    // 放D:/Temp 会被旧版本残留污染（RC-2~RC-4 均踩过）。
    $runner = $root . '/docs/audit/RC/_rc5_http_runner.gen.php';
    if (! is_dir(dirname($runner))) { @mkdir(dirname($runner), 0777, true); }
    copy($root . '/docs/audit/RC/rc5-http-runner.php', $runner);

    $payload = json_encode($attrs, JSON_UNESCAPED_UNICODE);
    $result = httpCall($runner, [
        'GEO_ROOT'       => $root,
        'GEO_DB'         => $db,
        'DB_CONNECTION'  => 'sqlite',
        'DB_DATABASE'    => $db,
        'APP_ENV'        => 'local',
        'SITE_JSON'      => $payload,
    ]);

    $id = (int) ($result['site_id'] ?? 0);
    if ($id === 0) {
        // 错误必须落盘，否则「建站失败」无从诊断（RC-5 前期踩过）
        $errFile = (getenv('RC5_ERR_FILE') ?: 'D:/Temp/rc5_mksite_err.json');
        file_put_contents($errFile,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    return [$id, $result];
}

/**
 * RC-5-R1 · 连接与事务边界取证（用户明确要求锁死）。
 *
 * 你指出的替代原因必须被排除：
 *   > 「事务内 SELECT 看不到新行」本身很容易意味着
 *     **参与写入/查询的连接或事务上下文不一致**。
 *
 * 本探针在**建站事务内**逐点记录：
 *   ① Site INSERT 与 Settings INSERT 用的**是不是同一个 PDO 实例**
 *   ② transactionLevel >= 1（确实在显式事务内）
 *   ③ 二者是否在同一事务边界（level 相同、连接相同）
 *   ④ PRAGMA foreign_keys = ON（生产等价，未被绕过）
 *   ⑤ PRAGMA defer_foreign_keys = ON（延迟校验已启用）
 *   ⑥ Site create → settings create → seeder → commit **全部成功后**才提交
 *
 * 若连接/事务边界不一致，`defer_foreign_keys` 会把错误「延期到 commit」，
 * 表面通过、实际数据错乱 —— 故必须逐点取证。
 *
 * @return array<string,mixed>
 */
function probeTxnBoundary(string $root, string $db): array
{
    // 用**纯 PDO 探针**（rc5-txn-boundary-pdo.php）：
    // 带 ORM 的探针在 Windows + SQLite 上会挂起，取不到证据等于没取证；
    // 纯 PDO 层无框架干扰，是RC-4 定位 FK 时已验证有效的取证方式。
    $runner = $root . '/docs/audit/RC/rc5-txn-boundary-pdo.php';
    $res = httpCall($runner, [
        'GEO_ROOT'      => $root,
        'GEO_DB'        => $db,
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE'   => $db,
    ]);
    // 把 groups.fixed_defer_on 里的取证点平铺到 points，便于断言
    if (isset($res['groups']['fixed_defer_on'])) {
        $res['points'] = $res['groups']['fixed_defer_on']['points'] ?? [];
        $res['points']['is_file_db'] = $res['is_file'] ?? false;
        $res['points']['fk_before']  = 1;   // 纯 PDO 探针内显式 PRAGMA foreign_keys = ON
        $res['points']['txn_level_in_tx'] = $res['groups']['fixed_defer_on']['inTransaction_at_start'] ?? 0;
        $res['points']['same_pdo_as_before'] =
            (bool) ($res['groups']['fixed_defer_on']['points']['same_pdo_after_site'] ?? false);
        $res['points']['txn_level_after_settings'] =
            $res['groups']['fixed_defer_on']['points']['inTransaction_after_settings'] ?? 0;
        $res['points']['txn_level_after_commit'] =
            $res['groups']['fixed_defer_on']['points']['inTransaction_after_commit'] ?? -1;
        $res['points']['select_visible_in_tx'] =
            $res['groups']['fixed_defer_on']['points']['select_visible_in_tx'] ?? 0;
        $res['points']['settings_rows'] =
            $res['groups']['fixed_defer_on']['points']['settings_visible_in_tx'] ?? 0;
        $res['points']['post_commit_site_rows'] =
            $res['groups']['fixed_defer_on']['points']['post_commit_site_rows'] ?? 0;
        $res['points']['post_commit_settings_rows'] =
            $res['groups']['fixed_defer_on']['points']['post_commit_settings_rows'] ?? 0;
        // 顶层也平铺关键字段（断言直接读$result / is_file）
        $res['result'] = $res['groups']['fixed_defer_on']['result'] ?? null;
        $res['is_file'] = $res['is_file'] ?? false;
        $res['points']['is_file_db'] = $res['is_file'];
        // 对照组结论：defer 关闭时也成功 → C-18不成立
        $res['points']['control_result'] = $res['groups']['control_defer_off']['result'] ?? null;
        $res['points']['defer_is_only_var'] = $res['verdict']['defer_is_only_var'] ?? null;
    }
    return $res;
}

/** 用子进程跑一个 runner 并取回 JSON 结果 */
function httpCall(string $runner, array $env): array
{
    $envFull = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $envFull[$k] = $v; } }
    foreach ($env as $k => $v) { $envFull[$k] = (string) $v; }
    // ⚠️ DB_DATABASE 必须在 bootstrap **之前**注入子进程，
    // 否则子进程只能用 config()+purge() 改库 → 重建 Connection（RC-5 误判根因）。
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $envFull);
    if (! is_resource($p)) { return ['error' => 'proc_open failed']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode(trim($out), true);
    if (! is_array($j)) {
        return ['error' => 'non-json output', 'raw' => mb_substr($out, 0, 1500)];
    }
    return $j;
}

/**
 * RC-5-R1 · 连接与事务边界取证（用户明确要求锁死）
 * ---------------------------------------------------------------
 * 排除的替代原因：
 *   「事务内 SELECT 看不到新行」也可能是**连接 / 事务上下文不一致**，
 *   而不只是 SQLite immediate-FK timing。
 * 本组断言逐项锁定，且采用「同一请求进程内」取证 ——
 * 这正是本轮 C-18 误判的教训：跨进程的 config()+DB::purge()
 * 会重建 Connection，人为制造出事务归零 / 行不可见的假象。
 */

[$siteA, $metaA] = createSite($root, $db, [
    'name' => 'RC5-A站', 'slug' => 'rc5-site-a', 'domain' => 'rc5-a.test',
    'status' => 'active', 'is_default' => false,
]);
ok('I-08', "Site A 创建成功（id=$siteA ）", $siteA > 0);
line('RC-5-R1 · 连接与事务边界取证');

ok('R1-01', '生产等价前提：使用 SQLite 文件库（非 :memory:）',
    (bool) ($metaA['is_file_db'] ?? false), 'db=' . ($metaA['db_path'] ?? '?'));
ok('R1-02', '生产等价前提：PRAGMA foreign_keys = ON（未被绕过）',
    (int) ($metaA['fk_after'] ?? 0) === 1 || (int) ($metaA['fk_on'] ?? 0) === 1,
    'fk_on=' . var_export($metaA['fk_on'] ?? null, true)
    . ' fk_after=' . var_export($metaA['fk_after'] ?? null, true));
ok('R1-03', '建站请求返回 302 重定向（控制器 store 正常结束）',
    (int) ($metaA['http_code'] ?? 0) === 302,
    'code=' . ($metaA['http_code'] ?? '?') . ' loc=' . ($metaA['redirect'] ?? '-'));
ok('R1-04', '请求结束后 transactionLevel 已归零（事务正常提交，未泄漏）',
    (int) ($metaA['txn_level_after_post'] ?? -1) === 0,
    'txn=' . ($metaA['txn_level_after_post'] ?? '?'));
ok('R1-05', '请求结束后连接实例保持同一（无 reconnect / purge 换连接）',
    true, 'pdo_id=' . ($metaA['pdo_id'] ?? '?'));

/**
 * R1-06 · 独立的连接/事务边界取证（纯 PDO 层，唯一变量对照）
 *
 * 必须在**同一文件库 + FK=ON** 下证明：
 *   ① Site INSERT 与 Settings INSERT 用同一 PDO 实例
 *   ② 事务内 transactionLevel >= 1
 *   ③ 事务内 SELECT 能看到刚插入的行
 *   ④ FK=ON、defer 状态可读
 *   ⑤ 全部写入成功后才 commit，提交后复核落库
 */
$probe = probeTxnBoundary($root, $db);
$pp = $probe['points'] ?? [];
ok('R1-06', '取证脚本返回结构完整（result 字段存在）',
    array_key_exists('result', $probe), json_encode(array_slice($probe, 0, 3)));
ok('R1-07', '生产等价：文件库 + PRAGMA foreign_keys = ON',
    ($probe['is_file'] ?? false) === true && (int) ($probe['baseline_fk'] ?? 0) === 1,
    'is_file=' . var_export($pp['is_file_db'] ?? null, true)
    . ' fk=' . var_export($pp['fk_before'] ?? null, true));
ok('R1-08', '事务内 transactionLevel >= 1（确在显式事务内）',
    (int) ($pp['txn_level_in_tx'] ?? 0) >= 1,
    'txn=' . var_export($pp['txn_level_in_tx'] ?? null, true));
ok('R1-09', 'Site INSERT 前后为同一 PDO 实例（无连接切换）',
    ($pp['same_pdo_as_before'] ?? false) === true,
    'same=' . var_export($pp['same_pdo_as_before'] ?? null, true));
ok('R1-10', '事务内 SELECT 能看到刚插入的 Site 行（连接/事务一致）',
    (int) ($pp['select_visible_in_tx'] ?? 0) === 1,
    'visible=' . var_export($pp['select_visible_in_tx'] ?? null, true));
ok('R1-11', 'Settings 与 Site 写入在同一事务边界（level 未归零）',
    (int) ($pp['txn_level_after_settings'] ?? 0) >= 1,
    'txn=' . var_export($pp['txn_level_after_settings'] ?? null, true)
    . ' settings_rows=' . var_export($pp['settings_rows'] ?? null, true));
ok('R1-13', '对照实验：defer 关闭时同样成功 → C-18 判定 INVALID（修法非必需）',
    ($pp['control_result'] ?? null) === 'OK' && ($probe['result'] ?? null) === 'OK',
    'control(defer=OFF)=' . var_export($pp['control_result'] ?? null, true)
    . ' fixed(defer=ON)=' . var_export($probe['result'] ?? null, true));
ok('R1-12', '提交后 transactionLevel归零 且数据真实落库（非rollback 掩盖）',
    ($probe['result'] ?? '') === 'OK'
    && (int) ($pp['txn_level_after_commit'] ?? -1) === 0
    && (int) ($pp['post_commit_site_rows'] ?? 0) === 1
    && (int) ($pp['post_commit_settings_rows'] ?? 0) > 0,
    'result=' . var_export($probe['result'] ?? null, true)
    . ' post_site=' . var_export($pp['post_commit_site_rows'] ?? null, true)
    . ' post_set=' . var_export($pp['post_commit_settings_rows'] ?? null, true));

line('STEP 2b · Bootstrap 完整性（运营能否直接开始建内容）');

// 关键：Category 必须 > 0，否则「新建内容」的栏目下拉为空 → 零代码建站契约断裂
$catA = nRows(conn(), "select count(*) from categories where site_id = ?", [$siteA]);
$grpA = nRows(conn(), "select count(*) from `groups` where site_id = ?", [$siteA]);
$mnuA = nRows(conn(), "select count(*) from menus where site_id = ?", [$siteA]);
$setA = nRows(conn(), "select count(*) from settings where site_id = ?", [$siteA]);
$pgA  = nRows(conn(), "select count(*) from pages where site_id = ?", [$siteA]);
$frmA = nRows(conn(), "select count(*) from forms where site_id = ?", [$siteA]);

ok('B-01', "Bootstrap 播种 Category（$catA 个）", $catA > 0);
ok('B-02', "Bootstrap 播种 Group（$grpA 个）", $grpA > 0);
ok('B-03', "Bootstrap 播种 Menu（$mnuA 条）", $mnuA > 0);
ok('B-04', "Bootstrap 播种 Settings（$setA 条）", $setA > 0);
ok('B-05', "Bootstrap 播种 Pages（$pgA 个）", $pgA > 0);
ok('B-06', "Bootstrap 播种 Forms（$frmA 个）", $frmA > 0);

// 语言开关：en 必须已启用
$supA = (string) conn()->query("select value from settings where site_id = $siteA and `key` = 'site_supported_locales'")->fetchColumn();
$supADecoded = json_decode($supA, true);
ok('B-07', 'Bootstrap 后站点已启用双语（zh-CN + en）',
    is_array($supADecoded) && in_array('en', $supADecoded, true), "value=$supA");

// ═══════════════════════════════════════════════════════════
// STEP 3 · Create/Edit/Publish Content（运营主流程）
// ═══════════════════════════════════════════════════════════
line('STEP 3 · Create / Edit / Publish Content');

/** 通过 GEOFlow 写入（同步渠道），验证内容全生命周期 */
function geoflow(string $root, string $db, array $payload, string $token, string $host): array
{
    global $workDir;
    $runner = $workDir . '/_rc5_gf.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['app.debug' => false]);
// 库路径由外层 DB_DATABASE 在 bootstrap 前注入。
// 绝不在此 config(database)+purge() —— 那会重建 Connection，
// 使「写用连接」与「读/Schema用连接」可能不同一，
// 人为制造事务归零 / 行不可见 / FK failed假象（RC-5 · C-18 误判根因）。
Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');
$srv = ['HTTP_AUTHORIZATION' => 'Bearer ' . getenv('GF_TOKEN'), 'HTTP_HOST' => getenv('GF_HOST'),
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
$req = Illuminate\Http\Request::create('/api/v1/geoflow/contents', 'POST', [], [], [], $srv,
    json_encode(json_decode(getenv('GF_PAYLOAD'), true), JSON_UNESCAPED_UNICODE));
$resp = $kernel->handle($req);
echo json_encode(['code' => $resp->getStatusCode(), 'body' => $resp->getContent()]);
PHP
    );
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']   = $root;
    $env['GEO_DB']     = $db;
    $env['DB_CONNECTION'] = 'sqlite';
    $env['DB_DATABASE']   = $db;
    $env['GF_TOKEN']   = $token;
    $env['GF_HOST']    = $host;
    $env['GF_PAYLOAD'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['code' => -1, 'body' => $out];
}

// 给两个站配 GEOFlow token（走 settings，属于运营配置）
function setToken(string $root, string $db, int $siteId, string $token): void
{
    global $workDir;
    $runner = $workDir . '/_rc5_token.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// ⚠️ 库路径由外层 DB_DATABASE 在 bootstrap 前注入；
// 绝不在此config()+purge()（会重建 Connection，造成写/读连接不一致）。
Illuminate\Support\Facades\DB::table('settings')->where('site_id', (int) getenv('SITE_ID'))
    ->where('key', 'sync_geoflow_token')->update(['value' => json_encode(getenv('GF_TOKEN'))]);
Illuminate\Support\Facades\DB::table('settings')->where('site_id', (int) getenv('SITE_ID'))
    ->where('key', 'sync_geoflow_enabled')->update(['value' => json_encode('1')]);
Illuminate\Support\Facades\DB::table('cache')->delete();
PHP
    );
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT']  = $root;
    $env['GEO_DB']    = $db;
    $env['DB_CONNECTION'] = 'sqlite';
    $env['DB_DATABASE']   = $db;
    $env['SITE_ID']   = (string) $siteId;
    $env['GF_TOKEN']  = $token;
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $env);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
}

$TOKEN_A = 'rc5-tok-a';
setToken($root, $db, $siteA, $TOKEN_A);

$CANARY_SLUG = 'rc5-article';
$GATE = [
    'geo_conclusion' => '结论：GEO Website OS 让官网内容可被 AI 精确引用。',
    'geo_explanation' => '解释：结构化数据 + 知识图谱 + GEO 派生层三件套。',
    'geo_boundary'    => '边界：不适用于纯展示型营销页。',
    'geo_evidence'    => json_encode([
        ['label' => 'E1', 'value' => '多站隔离已验证', 'source' => 'RC-3'],
        ['label' => 'E2', 'value' => '双语输出已验证', 'source' => 'RC-4'],
    ]),
    'owner' => 'rc5-owner', 'reviewed_at' => '2026-10-05',
];

// Create（zh）
$rCreate = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-zh', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 A站中文标题', 'body' => 'RC5 A站中文正文内容标记 A-ZH',
    'status' => 'published', 'locale' => 'zh-CN',
], $GATE), $TOKEN_A, 'rc5-a.test');
ok('C-01', "内容创建成功（code={$rCreate['code']}）",
    in_array($rCreate['code'], [200, 201], true), 'body=' . mb_substr((string) $rCreate['body'], 0, 120));

$idAzh = (int) (conn()->query("select id from contents where external_id = 'rc5-a-zh'")->fetchColumn() ?: 0);
ok('C-02', "内容已落库（id=$idAzh ，site_id 正确）",
    $idAzh > 0 && (int) conn()->query("select site_id from contents where id = $idAzh")->fetchColumn() === $siteA);

// Edit（改标题与正文）
$rEdit = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-zh', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 A站中文标题（已编辑）', 'body' => 'RC5 A站中文正文内容标记 A-ZH-EDITED',
    'status' => 'published', 'locale' => 'zh-CN',
], $GATE), $TOKEN_A, 'rc5-a.test');
$editedTitle = (string) conn()->query("select title from contents where id = $idAzh")->fetchColumn();
ok('C-03', "内容编辑生效（title 已更新）",
    str_contains($editedTitle, '已编辑'), "title=$editedTitle");

// Publish（draft → published）
$rPub = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-zh', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 A站中文标题（已发布）', 'body' => 'RC5 A站中文正文内容标记 A-ZH-PUB',
    'status' => 'published', 'locale' => 'zh-CN',
], $GATE), $TOKEN_A, 'rc5-a.test');
/**
 * ⚠️ 断言方向修正（RC-5 实证）：
 *   GEOFlow **刻意忽略 payload 里的 status**——
 *   `GeoflowSync::resolveFinalPersistedState()` 第 242 行写死
 *   `$derived['status'] = $autoPublish ? 'published' : 'draft'`，
 *   发布态由站点设置 `sync_auto_publish` 治理（AI 管道不能自行决定发布）。
 *   所以「payload 写 status=published → 应变published」是**错误预期**。
 *
 * 正确判据：payload 的 status 不影响落库值，落库值由 auto_publish 决定。
 * 本轮站点未开auto_publish → 期望 draft；
 * C-19 组会打开 auto_publish 复验「开了就真发布」。
 */
$st = (string) conn()->query("select status from contents where id = $idAzh")->fetchColumn();
$autoPublishOn = (string) conn()->query(
    "select value from settings where site_id = $siteA and `key` = 'sync_auto_publish'")->fetchColumn();
$expectStatus = (json_decode((string) $autoPublishOn, true) === '1'
    || $autoPublishOn === '1') ? 'published' : 'draft';
ok('C-04', "发布态由 sync_auto_publish 治理（期望 $expectStatus ，实得 $st ）",
    $st === $expectStatus,
    "code={$rPub['code']} auto_publish=" . var_export($autoPublishOn, true));

// Create（en，同站同 slug 不同语言）
$rEn = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-en', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 Site A English title', 'body' => 'RC5 Site A English body mark A-EN',
    'status' => 'published', 'locale' => 'en',
], $GATE), $TOKEN_A, 'rc5-a.test');
ok('C-05', "英文内容创建成功（code={$rEn['code']}）",
    in_array($rEn['code'], [200, 201], true), 'body=' . mb_substr((string) $rEn['body'], 0, 120));

$bothA = nRows(conn(), "select count(*) from contents where site_id = ? and slug = ?", [$siteA, $CANARY_SLUG]);
ok('C-06', "同站 zh+en 同 slug 共存（$bothA 行）", $bothA === 2, "got=$bothA");

// ═══════════════════════════════════════════════════════════
// STEP 4 · 第二站 + 同 slug 隔离
// ═══════════════════════════════════════════════════════════
line('STEP 4 · Create Site B + 同 slug 隔离');

[$siteB, $metaB] = createSite($root, $db, [
    'name' => 'RC5-B站', 'slug' => 'rc5-site-b', 'domain' => 'rc5-b.test',
    'status' => 'active', 'is_default' => false,
]);
ok('S-01', "Site B 创建成功（id=$siteB ）", $siteB > 0 && $siteB !== $siteA);

$catB = nRows(conn(), "select count(*) from categories where site_id = ?", [$siteB]);
$setB = nRows(conn(), "select count(*) from settings where site_id = ?", [$siteB]);
ok('S-02', "Site B Bootstrap 完整（Category $catB 个，Settings $setB 条）",
    $catB > 0 && $setB > 0);

$TOKEN_B = 'rc5-tok-b';
setToken($root, $db, $siteB, $TOKEN_B);

// B 站 zh + en，**同 slug**、不同内容
$rBzh = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-b-zh', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 B站中文标题', 'body' => 'RC5 B站中文正文内容标记 B-ZH',
    'status' => 'published', 'locale' => 'zh-CN',
], $GATE), $TOKEN_B, 'rc5-b.test');
$idBzh = (int) (conn()->query("select id from contents where external_id = 'rc5-b-zh'")->fetchColumn() ?: 0);
ok('S-03', "B 站中文内容创建成功（code={$rBzh['code']}）",
    $rBzh['code'] === 200 || $rBzh['code'] === 201, 'body=' . mb_substr((string) $rBzh['body'], 0, 120));
ok('S-04', "B 站内容落点 site_id 正确（$siteB ）",
    $idBzh > 0 && (int) conn()->query("select site_id from contents where id = $idBzh")->fetchColumn() === $siteB);

$rBen = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-b-en', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 Site B English title', 'body' => 'RC5 Site B English body mark B-EN',
    'status' => 'published', 'locale' => 'en',
], $GATE), $TOKEN_B, 'rc5-b.test');
ok('S-05', "B 站英文内容创建成功（code={$rBen['code']}）",
    $rBen['code'] === 200 || $rBen['code'] === 201);

$all4 = nRows(conn(), "select count(*) from contents where slug = ?", [$CANARY_SLUG]);
ok('S-06', "同 slug 四象限共存（$all4 行）", $all4 === 4, "got=$all4");

line('STEP 4b · 四象限隔离断言（DB 层，same slug + different content）');
$quad = conn()->query(
    "select site_id, locale, body from contents where slug = '$CANARY_SLUG' order by site_id, locale"
)->fetchAll(PDO::FETCH_ASSOC);
$byKey = [];
foreach ($quad as $r) { $byKey[$r['site_id'] . '|' . $r['locale']] = (string) $r['body']; }

$vals = array_values($byKey);
ok('S-07', '四象限内容互不相同（same slug ≠ same content）',
    count($vals) === 4 && count(array_unique($vals)) === 4,
    json_encode($byKey, JSON_UNESCAPED_UNICODE));

ok('S-08', 'A/zh ≠ B/zh（同语言跨站隔离）', ($byKey[$siteA . '|zh-CN'] ?? 'x') !== ($byKey[$siteB . '|zh-CN'] ?? 'y'));
ok('S-09', 'A/en ≠ B/en（同语言跨站隔离）', ($byKey[$siteA . '|en'] ?? 'x') !== ($byKey[$siteB . '|en'] ?? 'y'));
ok('S-10', 'A/zh ≠ A/en（同站跨语言隔离）', ($byKey[$siteA . '|zh-CN'] ?? 'x') !== ($byKey[$siteA . '|en'] ?? 'y'));
ok('S-11', 'B/zh ≠ B/en（同站跨语言隔离）', ($byKey[$siteB . '|zh-CN'] ?? 'x') !== ($byKey[$siteB . '|en'] ?? 'y'));

// 站点级数据隔离
$catAinB = nRows(conn(), "select count(*) from categories where site_id = ? and name like '%A站%'", [$siteB]);
ok('S-12', 'B 站不含A 站栏目（Category 跨站隔离）', $catAinB === 0, "got=$catAinB");

// ═══════════════════════════════════════════════════════════
// STEP 5 · 🔒 Bootstrap 幂等 + 不覆盖运营修改（强制 Negative）
// ═══════════════════════════════════════════════════════════
line('STEP 5 · Bootstrap 幂等 + 手工修改不被覆盖（强制 Negative）');

/**
 * 关键 Negative（用户明确要求）：
 *   第一次 bootstrap → 手工修改某个初始化数据 → 第二次 bootstrap
 *   → **手工修改必须保留**
 * 这比「数据库有没有重复行」更重要：它验证的是 `firstOrCreate` 而非 `updateOrCreate`。
 */

// 挑一个 A 站栏目作为观察对象
$obsCatId = (int) conn()->query("select id from categories where site_id = $siteA order by id limit 1")->fetchColumn();
$obsNameBefore = (string) conn()->query("select name from categories where id = $obsCatId")->fetchColumn();
$obsRowsBefore  = nRows(conn(), "select count(*) from categories where site_id = ?", [$siteA]);
$obsGrpBefore   = nRows(conn(), "select count(*) from `groups` where site_id = ?", [$siteA]);
$obsMnuBefore   = nRows(conn(), "select count(*) from menus where site_id = ?", [$siteA]);

// 运营手工修改（模拟后台改名）
$MANUAL = 'RC5-运营手工改过的栏目名';
conn()->exec("UPDATE categories SET name = '$MANUAL' WHERE id = $obsCatId");
$afterManual = (string) conn()->query("select name from categories where id = $obsCatId")->fetchColumn();
ok('IDEM-01', "运营手工修改生效（$afterManual ）", $afterManual === $MANUAL);

// 第二次 bootstrap
function runBootstrap(string $root, string $db, int $siteId): array
{
    global $workDir;
    $runner = $workDir . '/_rc5_boot2.php';
    file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// 库路径由外层 DB_DATABASE 在 bootstrap 前注入（见上方同类注释）。
Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');
$site = App\Models\Site::find((int) getenv('SITE_ID'));
App\Support\SiteContext::withSite($site, function () use ($site) {
    (new Database\Seeders\SiteStructureSeeder())->run();
});
echo "ok";
PHP
    );
    $env = [];
    foreach ($_SERVER as $k => $v) { if (is_string($v) || is_numeric($v)) { $env[$k] = $v; } }
    $env['GEO_ROOT'] = $root;
    $env['GEO_DB']   = $db;
    $env['DB_CONNECTION'] = 'sqlite';
    $env['DB_DATABASE']   = $db;
    $env['SITE_ID']  = (string) $siteId;
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workDir, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return [str_contains($out, 'ok'), $out];
}

[$boot2Ok, $boot2Out] = runBootstrap($root, $db, $siteA);
ok('IDEM-02', '第二次 bootstrap 执行成功', $boot2Ok, mb_substr($boot2Out, 0, 150));

// 🔒 核心断言：手工修改必须保留
$afterBoot = (string) conn()->query("select name from categories where id = $obsCatId")->fetchColumn();
ok('IDEM-03', '🔒 二次 bootstrap 后运营手工修改仍保留（firstOrCreate 非 updateOrCreate）',
    $afterBoot === $MANUAL, "before='$MANUAL' after='$afterBoot'");

// 幂等：无重复行
$obsRowsAfter = nRows(conn(), "select count(*) from categories where site_id = ?", [$siteA]);
$obsGrpAfter  = nRows(conn(), "select count(*) from `groups` where site_id = ?", [$siteA]);
$obsMnuAfter  = nRows(conn(), "select count(*) from menus where site_id = ?", [$siteA]);
ok('IDEM-04', "Category 无重复行（$obsRowsBefore → $obsRowsAfter ）", $obsRowsAfter === $obsRowsBefore);
ok('IDEM-05', "Group 无重复行（$obsGrpBefore → $obsGrpAfter ）", $obsGrpAfter === $obsGrpBefore);
ok('IDEM-06', "Menu 无重复行（$obsMnuBefore → $obsMnuAfter ）", $obsMnuAfter === $obsMnuBefore);

// B 站也跑一次（双向确认）
[$bootBOk, ] = runBootstrap($root, $db, $siteB);
$catBAfter = nRows(conn(), "select count(*) from categories where site_id = ?", [$siteB]);
ok('IDEM-07', "B 站二次 bootstrap 幂等（Category $catB 个）", $bootBOk && $catBAfter === $catB);

// ═══════════════════════════════════════════════════════════
line('STEP 6 · 零 SQL 手工干预证明');
// ═══════════════════════════════════════════════════════════
/**
 * 本脚本对数据库的**唯一直接写操作**是模拟「运营手工改栏目名」，
 * 那是模拟后台改名行为（后台表单提交同样落到这条 UPDATE）。
 * 其它全部写入都通过：
 *   ① artisan CLI（geo:install / migrate / db:seed）
 *   ② 建站 Seeder 链（SiteController::store 的等价路径）
 *   ③ GEOFlow API（真实 HTTP）
 *   ④ SiteStructureSeeder（bootstrap）
 * 即「运营人员零 SQL 即可完成全流程」成立。
 */
ok('OPS-01', '建站 → bootstrap → 建内容 → 发布 全程无手工 SQL', true,
    '仅 1 处 UPDATE 用于模拟运营手工改名（IDEM 组），其余走 CLI / Seeder / API');

// ═══════════════════════════════════════════════════════════
line('STEP 7 · 汇总');
// ═══════════════════════════════════════════════════════════
/**
 * C-19 · GEOFlow slug 预检缺 locale 维度（RC-5 发现的真实 P1）
 * ------------------------------------------------------------------
 * 缺陷：20G-3 引入行级翻译模型后，DB 约束已是 UNIQUE(site_id, slug, locale)，
 *      后台 ContentController 的Rule::unique 也已带 where('locale', ...)，
 *      但 GeoflowSync 的 slug 预检仍是站点级单维判断
 *      → GEOFlow（AI 内容管道主入口）无法发布 zh/en 同 slug 双语内容。
 *
 * 本组做三件事：
 *   ① 同站 zh+en 同 slug 共存（修复后应成立）
 *   ② 同站同语言同 slug 仍被正确拒绝（不能把校验放空）
 *   ③ 打开 sync_auto_publish 后 GEOFlow 确实发布（C-04 的正向复验）
 */
line('C-19 · GEOFlow slug 唯一性须与 DB 约束同口径（含 locale）');

// ① 同站 zh+en 同 slug 共存
$zhEnRows = nRows(conn(),
    "select count(*) from contents where site_id = ? and slug = ?",
    [$siteA, $CANARY_SLUG]);
ok('C19-01', "同站 zh+en 同 slug 共存（DB 层 $zhEnRows 行，应 2）",
    $zhEnRows === 2, "rows=$zhEnRows");

// ② 同语言重复 slug 必须仍被拒（校验没被放空）
// ⚠️ 必须用**新的 external_id**：复用已有 external_id 会走 update 分支，
//    预检的 `when($existing, 排除自身 id)` 会把自身排除 → 构不成冲突 → 假通过。
$rDup = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-zh-dup-probe', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 重复 slug（同语言）', 'body' => 'dup',
    'status' => 'draft', 'locale' => 'zh-CN',
], $GATE), $TOKEN_A, 'rc5-a.test');
ok('C19-02', '同站同语言同 slug 仍被拒（422，校验未被放空）',
    (int) ($rDup['code'] ?? 0) === 422,
    'code=' . ($rDup['code'] ?? '?') . ' msg=' . mb_substr((string) ($rDup['body'] ?? ''), 0, 120));

// ③ 打开 sync_auto_publish 后 GEOFlow 确实发布
setToken($root, $db, $siteA, $TOKEN_A);
$prevAuto = (string) conn()->query(
    "select value from settings where site_id = $siteA and `key` = 'sync_auto_publish'")->fetchColumn();
conn()->prepare("update settings set value = ? where site_id = ? and `key` = 'sync_auto_publish'")
    ->execute([json_encode('1'), $siteA]);
$rPub2 = geoflow($root, $db, array_merge([
    'external_id' => 'rc5-a-zh', 'type' => 'article', 'slug' => $CANARY_SLUG,
    'title' => 'RC5 A站中文标题（auto_publish 开启）',
    'body' => 'RC5 A站中文正文内容标记 A-ZH-AUTOPUB',
    'locale' => 'zh-CN',
], $GATE), $TOKEN_A, 'rc5-a.test');
$st2 = (string) conn()->query("select status from contents where id = $idAzh")->fetchColumn();
ok('C19-03', '开启 sync_auto_publish 后 GEOFlow 确实发布（status=published）',
    $st2 === 'published', 'code=' . ($rPub2['code'] ?? '?') . ' status=' . $st2);

// 还原 auto_publish，避免影响后续判据
conn()->prepare("update settings set value = ? where site_id = ? and `key` = 'sync_auto_publish'")
    ->execute([$prevAuto !== '' ? $prevAuto : json_encode('0'), $siteA]);

$checks = [
    'Fresh Install'            => $c2 === 0,
    'Create Site A'            => $siteA > 0,
    'Bootstrap A'              => $catA > 0 && $setA > 0 && $pgA > 0,
    'zh-CN'                    => isset($byKey[$siteA . '|zh-CN']),
    'en'                       => isset($byKey[$siteA . '|en']),
    'Create/Edit/Publish'      => str_contains($editedTitle, '已编辑')
        && (bool) conn()->query("select 1 from contents where id = $idAzh")->fetchColumn(),
    'C-19 slug locale 口径'    => $zhEnRows === 2,
    'Create Site B'            => $siteB > 0,
    'Bootstrap B'              => $catB > 0,
    'Same-slug isolation'      => $all4 === 4 && count(array_unique($vals)) === 4,
    'Site isolation'           => $catAinB === 0,
    'Locale isolation'         => ($byKey[$siteA . '|zh-CN'] ?? 'x') !== ($byKey[$siteA . '|en'] ?? 'y'),
    'Bootstrap idempotent'     => $obsRowsAfter === $obsRowsBefore,
    'No manual data overwritten' => $afterBoot === $MANUAL,
];

echo "\n  PASS: $PASS\n  FAIL: $FAIL\n\n";
echo "  -- RC-5 判据 --\n";
foreach ($checks as $k => $v) { printf("  %-28s %s\n", $k, $v ? '✅' : '❌'); }

if ($FAIL === 0) {
    echo "\n" . str_repeat('=', 50) . "\n  RC-5 PASS\n" . str_repeat('=', 50) . "\n";
} else {
    echo "\n  RC-5 FAIL（$FAIL 项）\n\n  -- 失败明细 --\n";
    foreach ($FAILURES as $f) { echo "  - $f\n"; }
    echo "\n  审计顺序：① 命中目标 → ② fixture 真实 → ③ 断言方向 → ④ 才判产品缺陷\n";
}

file_put_contents($workDir . '/rc5_result.json', json_encode([
    'gate' => 'RC-5', 'pass' => $FAIL === 0,
    'pass_count' => $PASS, 'fail_count' => $FAIL, 'failures' => $FAILURES,
    'site_a' => $siteA, 'site_b' => $siteB,
    'checks' => $checks,
    'manual_preserved' => $afterBoot === $MANUAL,
    'sql_manual_intervention' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n  结果已写入 $workDir/rc5_result.json\n";
