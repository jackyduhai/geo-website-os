<?php
/**
 * RC-5 · 事务终结点二分定位
 * ------------------------------------------------------------------
 * 已证事实（rc5-rootcause-locate trace）：
 *   beginTransaction → txn_level=1, pdo_id=1419
 *   Site::create()   → txn_level=0, pdo_id=1423   ← 事务与连接同时被换掉
 *   随后 SELECT 看不到刚插入的行 → FK 失败
 *
 * 本脚本在 Site::create() 的**内部各钩子前后**逐步取样，
 * 精确定位「谁终结了事务 / 换了连接」。
 *
 * 关键怀疑点（按可能性排序）：
 *   ① Site::booted() 的 saved 钩子里`Schema::hasTable()`
 *   ② `Setting::withoutSiteScope()->updateOrCreate(...)`
 *   ③ `Setting::flush()`
 *   ④ `PageCache::flush()`（走 Cache::store('file')）
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = getenv('GEO_DB');
config(['database.connections.sqlite.database' => $db, 'app.debug' => false]);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

DB::purge('sqlite');

$TRACE = 'D:/Temp/rc5_bisect_trace.txt';
@unlink($TRACE);

function t(string $msg): void
{
    global $TRACE;
    file_put_contents($TRACE, $msg . "\n", FILE_APPEND);
}

function snap(string $label): void
{
    t(sprintf(
        '%-46s txn=%d pdo=%d',
        $label,
        DB::transactionLevel(),
        spl_object_id(DB::connection()->getPdo())
    ));
}

snap('baseline');

t('=== 步骤 1: 单独测Schema::hasTable ===');
DB::beginTransaction();
snap('  after beginTransaction');
Schema::hasTable('settings');
snap('  after Schema::hasTable');

t('=== 步骤 2: 测 Setting::withoutSiteScope()->updateOrCreate ===');
$s = App\Models\Setting::withoutSiteScope()->updateOrCreate(
    ['site_id' => 999999, 'key' => 'rc5_bisect'],
    ['site_id' => 999999, 'value' => json_encode('x')]
);
snap('  after Setting updateOrCreate');
App\Models\Setting::flush();
snap('  after Setting::flush');

t('=== 步骤 3: 测 PageCache::flush ===');
App\Support\PageCache::flush();
snap('  after PageCache::flush');

t('=== 步骤 4: 测 Site::create（核心） ===');
try {
    $site = App\Models\Site::create([
        'name' => 'RC5 二分站',
        'slug' => 'rc5bisect',
        'domain' => 'rc5bisect.test',
        'status' => 'active',
        'is_default' => false,
    ]);
    t('  Site::create returned id=' . var_export($site->id, true));
} catch (Throwable $e) {
    t('  Site::create FAILED: ' . mb_substr($e->getMessage(), 0, 150));
}
snap('  after Site::create');

t('=== 步骤 5: 测在 saved 钩子之前手动插入 ===');
try {
    DB::rollBack();
} catch (Throwable $x) {}
snap('after rollback');

DB::beginTransaction();
snap('  fresh beginTransaction');
// 用withoutEvents 绕过 saved 钩子
$site2 = App\Models\Site::withoutEvents(function () {
    return App\Models\Site::create([
        'name' => 'RC5 无钩子站',
        'slug' => 'rc5noevent',
        'domain' => 'rc5noevent.test',
        'status' => 'active',
        'is_default' => false,
    ]);
});
t('  withoutEvents create id=' . var_export($site2->id, true));
snap('  after withoutEvents create');

// 此时立刻写settings，验证 FK 是否成立
try {
    DB::table('settings')->insert([
        'site_id' => $site2->id, 'key' => 'rc5_noev', 'value' => json_encode('v'),
        'group' => 'general', 'type' => 'text', 'sort' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    t('  settings INSERT after withoutEvents = OK  ← 钩子才是元凶');
} catch (Throwable $e) {
    t('  settings INSERT after withoutEvents = FAIL: ' . mb_substr($e->getMessage(), 0, 130));
}
snap('  end');

t('DONE');
file_put_contents('D:/Temp/rc5_bisect_done.txt', 'ok');