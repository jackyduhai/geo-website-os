<?php
/**
 * RC-5 · C-18 根因最小定位（逐步落盘，防挂起丢证据）
 * ------------------------------------------------------------------
 * 背景两条硬事实：
 *   ① 变异测试：禁用 defer 后 Production-Parity 7 个测试**仍全绿**
 *      → defer 修法在现有测试中无牙齿
 *   ② 纯 PDO 层（同连接、同事务、FK=ON）**不复现** FK 失败
 *   ③带 ORM 的脚本在 Windows + SQLite 文件库上会**挂起**
 *
 * 本脚本逐步落盘，回答唯一问题：
 *   `Site::create()` 之后，同一连接上能否 SELECT 到该行？
 *   若不能，则「FK 失败」与「挂起」是同一个根因（连接/事务视图问题），
 *   而非 immediate-FK timing。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = getenv('GEO_DB');
config(['database.connections.sqlite.database' => $db, 'app.debug' => false]);

use Illuminate\Support\Facades\DB;

DB::purge('sqlite');

$TRACE = 'D:/Temp/rc5_step_trace.txt';
@unlink($TRACE);

function t(string $msg): void
{
    global $TRACE;
    file_put_contents($TRACE, $msg . "\n", FILE_APPEND);
}

t('boot ok');
t('db = ' . $db);
t('is_file = ' . var_export(is_file($db), true));
t('fk = ' . DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn());
t('txn_level = ' . DB::transactionLevel());
t('pdo_id = ' . spl_object_id(DB::connection()->getPdo()));

t('--- begin txn ---');
DB::beginTransaction();
t('txn_level = ' . DB::transactionLevel());

t('--- Site::create ---');
$site = App\Models\Site::create([
    'name' => 'RC5 定位站',
    'slug' => 'rc5locate',
    'domain' => 'rc5locate.test',
    'status' => 'active',
    'is_default' => false,
]);
t('created. id = ' . var_export($site->id, true));
t('exists() = ' . var_export($site->exists, true));
t('wasRecentlyCreated = ' . var_export($site->wasRecentlyCreated, true));

t('--- pdo / txn after create ---');
t('pdo_id = ' . spl_object_id(DB::connection()->getPdo()));
t('txn_level = ' . DB::transactionLevel());

t('--- SELECT visibility ---');
$rows = DB::table('sites')->where('id', $site->id)->count();
t('DB::table(sites) count = ' . $rows);
$rows2 = DB::select('select count(*) as c from sites where id = ?', [$site->id]);
t('DB::select count = ' . json_encode($rows2));

t('--- raw INSERT into settings (bypass model) ---');
try {
    DB::table('settings')->insert([
        'site_id' => $site->id,
        'key'     => 'rc5_probe',
        'value'   => json_encode('v'),
        'group'   => 'general',
        'type'    => 'text',
        'sort'    => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    t('raw settings INSERT = OK');
} catch (Throwable $e) {
    t('raw settings INSERT = FAIL: ' . mb_substr($e->getMessage(), 0, 160));
}

t('--- Setting model INSERT (goes through casts) ---');
try {
    App\Models\Setting::flush();
    App\Support\SiteContext::setSite($site);
    App\Models\Setting::flush();
    App\Models\Setting::create([
        'site_id' => $site->id,
        'key'     => 'rc5_probe2',
        'value'   => json_encode('v2'),
        'group'   => 'general',
        'type'    => 'text',
        'sort'    => 0,
    ]);
    t('Setting::create = OK');
} catch (Throwable $e) {
    t('Setting::create = FAIL: ' . mb_substr($e->getMessage(), 0, 160));
}

t('--- commit ---');
try {
    DB::commit();
    t('commit OK');
} catch (Throwable $e) {
    t('commit FAIL: ' . mb_substr($e->getMessage(), 0, 160));
}

t('--- post commit ---');
t('sites total = ' . DB::table('sites')->count());
t('settings for site = ' . DB::table('settings')->where('site_id', $site->id)->count());
t('DONE');

file_put_contents('D:/Temp/rc5_step_done.json', json_encode(['done' => true], JSON_UNESCAPED_UNICODE));