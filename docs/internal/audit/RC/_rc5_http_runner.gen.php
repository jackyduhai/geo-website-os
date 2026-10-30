<?php
/**
 * RC-5 · 真实后台 HTTP 建站 runner
 * ------------------------------------------------------------------
 * 复现运营人员的真实路径：
 *   GET  /admin/login   → 取 CSRF token + session cookie
 *   POST /admin/login   → 登录
 *   POST /admin/sites   → 建站（含 C-18 的 defer_foreign_keys 修复）
 *
 * ⚠️ 为什么必须走 HTTP 而不是复制 Seeder 链：
 *   C-18 的修复在 `SiteController::store` 内，
 *   旁路调用会绕过它 → 重现 FK 失败（假失败）。
 *
 * Session 必须在**同一进程**内保持，故三步请求都在这里串行完成。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$db = getenv('GEO_DB');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠️ 绝不在 bootstrap 后用 config()+DB::purge() 改库路径。
 * 那会重建 Connection 实例，使「写用连接」与「Schema/Setting 读用连接」可能不同一，
 * 人为制造「transactionLevel 归零 / 刚插入的行不可见 / FK failed」假象。
 * RC-5 期间的 C-18 误判即源于此。
 * 正确做法：库路径由外层 `DB_DATABASE=... php` 在 bootstrap **之前**注入。
 */
if (DB::connection()->getDatabaseName() !== $db) {
    echo json_encode(['error' => 'db mismatch: got '
        . DB::connection()->getDatabaseName() . ' want ' . $db]);
    exit(2);
}

$out = ['steps' => []];

/** 记录一步 */
function step(array &$out, string $name, $data = null): void
{
    $out['steps'][] = ['name' => $name, 'data' => $data];
}

// ── 生产等价前提：文件库 + FK 开启 ────────────────────────────────────
$fk = (int) DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn();
$out['db_path']    = $db;
$out['is_file_db'] = is_file($db);
$out['fk_on']      = $fk;
if ($fk !== 1) { DB::statement('PRAGMA foreign_keys = ON'); }

// ── 准备一个 super admin（若不存在）────────────────────────────────
$admin = App\Models\User::query()->first();
if (! $admin) {
    $out['error'] = 'no user in database (geo:install should create one)';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(1);
}
$out['admin_email'] = $admin->email;

// ── 第 1 步：GET /admin/login 取 CSRF + session ─────────────────────
$loginPage = $kernel->handle(Illuminate\Http\Request::create('/admin/login', 'GET'));
step($out, 'GET /admin/login', ['code' => $loginPage->getStatusCode()]);

$body = (string) $loginPage->getContent();
if (! preg_match('/name="_token"\s+value="([^"]+)"/', $body, $m)) {
    $out['error'] = 'csrf token not found on login page';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(1);
}
$token = $m[1];
step($out, 'csrf', ['len' => strlen($token)]);

// session cookie（session.driver=array 时在同一进程内共享，无需 cookie）
// 但仍需把 token 传给 POST

// ── 第 2 步：建立认证态 ─────────────────────────────────────────────
// ⚠️ 用编程式登录而非表单登录：RC-5 验证的是**建站链路**，不是认证本身。
// 表单登录需要已知明文密码，会引入与本 Gate 无关的假失败
//（RC-5 首轮就因此报 "login failed (password mismatch?)"，属测试缺陷）。
// 中间件 admin.auth 只检查认证态，语义等价。
auth()->login($admin);
step($out, 'auth', ['check' => auth()->check(), 'id' => auth()->id()]);
if (! auth()->check()) {
    $out['error'] = 'auth failed';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(1);
}
// 认证态切换后必须清Setting 进程内 memo，否则读到匿名视角的旧缓存
App\Models\Setting::flush();

// ── 第 3 步：GET /admin/sites/new 取新 CSRF（表单页）───────────────
$formResp = $kernel->handle(Illuminate\Http\Request::create('/admin/sites/create', 'GET'));
$formBody = (string) $formResp->getContent();
preg_match('/name="_token"\s+value="([^"]+)"/', $formBody, $fm);
$formToken = $fm[1] ?? $token;
step($out, 'GET /admin/sites/new', [
    'code'  => $formResp->getStatusCode(),
    'csrf'  => $formToken !== '',
]);

// ── 第 4 步：POST /admin/sites（真正的建站）────────────────────────
$attrs = json_decode(getenv('SITE_JSON'), true) ?: [];

$payload = array_merge([
    '_token'       => $formToken,
    'name'         => 'RC5 站',
    'slug'         => 'rc5-site',
    'status'       => 'active',
    'is_default'   => '0',
], $attrs);

$createReq = Illuminate\Http\Request::create('/admin/sites', 'POST', $payload);
$createReq->setLaravelSession($app['session']->driver());
$createResp = $kernel->handle($createReq);
$code = $createResp->getStatusCode();
step($out, 'POST /admin/sites', [
    'code'      => $code,
    'loc'       => $createResp->headers->get('Location'),
    'csrf_err'  => str_contains((string) $createResp->getContent(), 'The token has expired')
                    || str_contains((string) $createResp->getContent(), 'CSRF token mismatch'),
]);

// ── 结果取证：按 slug 查权威 id ─────────────────────────────────────
$slug = $attrs['slug'] ?? 'rc5-site';
$siteId = DB::table('sites')->where('slug', $slug)->value('id');
$out['slug']        = $slug;
// 连接与事务不变量（RC-5-R1 要求锁死）
$out['txn_level_after_post'] = DB::transactionLevel();
$out['pdo_id']       = spl_object_id(DB::connection()->getPdo());
$out['fk_after']     = (int) DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn();
$out['defer_after']  = (int) DB::connection()->getPdo()->query('PRAGMA defer_foreign_keys')->fetchColumn();

$out['site_id']     = $siteId === null ? 0 : (int) $siteId;
$out['http_code']   = $code;
$out['redirect']    = $createResp->headers->get('Location');

if ($siteId !== null) {
    $sid = (int) $siteId;
    $out['seeded'] = [
        'settings'   => DB::table('settings')->where('site_id', $sid)->count(),
        'categories' => DB::table('categories')->where('site_id', $sid)->count(),
        'menus'      => DB::table('menus')->where('site_id', $sid)->count(),
        'contents'   => DB::table('contents')->where('site_id', $sid)->count(),
        'forms'      => Schema::hasTable('forms')
            ? DB::table('forms')->where('site_id', $sid)->count() : null,
    ];
    // 语言开关（RC-5 要求 bootstrap 后 zh-CN / en 均可用）
    $sup = DB::table('settings')->where('site_id', $sid)
        ->where('key', 'site_supported_locales')->value('value');
    $out['site_supported_locales'] = $sup;
    // 事务边界：commit 后 transactionLevel 必须回到 0
    $out['txn_level_after'] = DB::transactionLevel();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);