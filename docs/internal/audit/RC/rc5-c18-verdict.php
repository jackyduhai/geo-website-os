<?php
/**
 * RC-5 · C-18 最终裁定：三条路径同条件对照
 * ------------------------------------------------------------------
 * 目的：判定 C-18（后台建站 FK 失败）是**产品缺陷**还是**测试脚本假象**。
 *
 * 已证事实：
 *   F1. 变异测试：defer 换成无效 PRAGMA 后 Production-Parity 7 个测试仍全绿
 *       → defer 修法无牙齿
 *   F2. 纯 PDO（同连接/同事务/FK=ON）不复现 FK 失败
 *   F3. 根因 trace：`Site::create()` 后 txn_level 1→0、pdo 1419→1423，
 *       随后 SELECT 看不到刚插入的行 → FK 失败
 *
 * 三条路径（同一文件库、同一 FK=ON、同一代码形状）：
 *   P1 旁路 = RC-5 初版脚本的写法（DB::transaction + Site::create + 5 Seeder）
 *   P2 控制器等= SiteController::store 的形状（含 defer 调用）
 *   P3 HTTP   = 真实后台 POST /admin/sites（真实运营路径，含中间件）
 *
 * 判定：
 *   若 P3（真实路径）成立 → 产品无缺陷，C-18 = 测试脚本假象 → INVALID
 *   若 P3 失败→ 产品缺陷成立，C-18 = REAL
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$db = getenv('GEO_DB');

use Illuminate\Support\Facades\DB;

// ⚠️ 不在 bootstrap 后改config()+DB::purge()：
//   那会重建 Connection 实例，使「写用连接」与「Schema/Setting 读用连接」可能不同一，
//   从而人为制造「事务被判负、行不可见」的假象 ——
//   本次探针自身失败（419 / txnrst归零）就是这么来的，不是产品问题。
//   库路径由外层 `DB_DATABASE=... php artisan` 在 bootstrap 前注入。
if (DB::connection()->getDatabaseName() !== $db) {
    echo json_encode(['error' => 'db mismatch: ' . DB::connection()->getDatabaseName()
        . ' != ' . $db], JSON_UNESCAPED_UNICODE);
    exit(2);
}

$out = ['paths' => []];

function pin(array &$out, string $k, $v): void
{
    $out['paths'][$k] = $v;
    file_put_contents('D:/Temp/rc5_verdict_trace.txt',
        $k . ' = ' . (is_scalar($v) ? var_export($v, true) : json_encode($v)) . "\n",
        FILE_APPEND);
}

pin($out, 'db', $db);
pin($out, 'is_file', is_file($db));
pin($out, 'fk', (int) DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn());

$admin = App\Models\User::query()->first();
pin($out, 'admin_exists', $admin !== null);

if (! $admin) {
    pin($out, 'fatal', 'no admin user (run geo:install first)');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(1);
}

// ══════════════════════════════════════════════════════════════════
// P1 · 旁路（RC-5 初版脚本写法，**不含** defer）
// ══════════════════════════════════════════════════════════════════
$p1 = ['result' => '?'];
try {
    $slug1 = 'verdict-p1';
    $s1 = DB::transaction(function () use ($slug1, &$p1) {
        $site = App\Models\Site::create([
            'name' => 'P1 旁路站', 'slug' => $slug1,
            'domain' => $slug1 . '.test', 'status' => 'active',
        ]);
        $p1['txn_after_create'] = DB::transactionLevel();
        $p1['pdo_after_create']  = spl_object_id(DB::connection()->getPdo());
        $p1['visible'] = DB::table('sites')->where('id', $site->id)->exists() ? 'YES' : 'NO';
        App\Support\SiteContext::withSite($site, function () use ($site) {
            (new Database\Seeders\DefaultSettingSeeder())->run();
            (new Database\Seeders\DefaultFormSeeder())->run();
            (new Database\Seeders\BlankHomepageSeeder($site))->run();
            (new Database\Seeders\SystemPageSeeder($site))->run();
            (new Database\Seeders\SiteStructureSeeder())->run();
        });
        return $site;
    });
    $p1['result'] = 'OK';
    $p1['id'] = (int) $s1->id;
    $p1['settings'] = (int) DB::table('settings')->where('site_id', $s1->id)->count();
    $p1['site_persisted'] = (int) DB::table('sites')->where('slug', $slug1)->count();
} catch (Throwable $e) {
    $p1['result'] = 'FAIL';
    $p1['error'] = mb_substr($e->getMessage(), 0, 200);
    try { if (DB::transactionLevel() > 0) { DB::rollBack(); } } catch (Throwable $x) {}
}
pin($out, 'P1_bypass', $p1);

// ══════════════════════════════════════════════════════════════════
// P3 · 真实 HTTP：GET /admin/login → POST /admin/sites
// ══════════════════════════════════════════════════════════════════
$p3 = ['result' => '?'];
try {
    $loginPage = $kernel->handle(Illuminate\Http\Request::create('/admin/login', 'GET'));
    $p3['login_page'] = $loginPage->getStatusCode();
    preg_match('/name="_token"\s+value="([^"]+)"/', (string) $loginPage->getContent(), $m);
    $csrf = $m[1] ?? '';
    $p3['csrf'] = $csrf !== '';

    // 用编程方式登录（等价于通过表单登录后的认证态）
    auth()->login($admin);
    $p3['auth'] = auth()->check();
    App\Models\Setting::flush();

    $formPage = $kernel->handle(Illuminate\Http\Request::create('/admin/sites/create', 'GET'));
    preg_match('/name="_token"\s+value="([^"]+)"/', (string) $formPage->getContent(), $fm);
    $formToken = $fm[1] ?? $csrf;
    $p3['form_page'] = $formPage->getStatusCode();

    $slug3 = 'verdict-p3';
    $req = Illuminate\Http\Request::create('/admin/sites', 'POST', [
        '_token'      => $formToken,
        'name'        => 'P3 HTTP 站',
        'slug'        => $slug3,
        'domain'      => $slug3 . '.test',
        'status'      => 'active',
        'is_default'  => '0',
    ]);
    $req->setLaravelSession($app['session']->driver());
    $resp = $kernel->handle($req);
    $p3['post_code'] = $resp->getStatusCode();
    $p3['redirect']  = $resp->headers->get('Location');

    $row = DB::table('sites')->where('slug', $slug3)->first();
    $p3['site_persisted'] = $row ? 1 : 0;
    if ($row) {
        $sid = (int) $row->id;
        $p3['id'] = $sid;
        $p3['settings']   = (int) DB::table('settings')->where('site_id', $sid)->count();
        $p3['categories'] = (int) DB::table('categories')->where('site_id', $sid)->count();
        $p3['menus']      = (int) DB::table('menus')->where('site_id', $sid)->count();
        $p3['contents']   = (int) DB::table('contents')->where('site_id', $sid)->count();
        $p3['supported_locales'] = DB::table('settings')
            ->where('site_id', $sid)->where('key', 'site_supported_locales')->value('value');
    }
    $p3['result'] = ($row && (int) DB::table('settings')->where('site_id', $row->id)->count() > 0)
        ? 'OK' : 'FAIL';
} catch (Throwable $e) {
    $p3['result'] = 'FAIL';
    $p3['error'] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
}
pin($out, 'P3_http', $p3);

$out['verdict'] = [
    'P1_result'        => $p1['result'],
    'P3_result'        => $p3['result'],
    'product_defect'   => ($p3['result'] === 'FAIL'),
    'c18_status'       => ($p3['result'] === 'FAIL' ? 'REAL' : 'INVALID'),
];
pin($out, 'verdict', $out['verdict']);

echo json_encode($out, JSON_UNESCAPED_UNICODE);