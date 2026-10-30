<?php
/**
 * RC-5-R1 · 连接与事务边界取证探针
 * ------------------------------------------------------------------
 * 目的：排除「defer_foreign_keys 只是把跨连接 / 跨事务错误延期到 commit」
 * 这一替代解释。
 *
 * 必须逐点证明（用户明确要求）：
 *   ① Site INSERT 与 Settings INSERT 用**同一个 PDO 实例**
 *   ② transactionLevel >= 1（确实在显式事务内）
 *   ③ 二者在**同一事务边界**（level 一致 + 连接一致）
 *   ④ PRAGMA foreign_keys = ON（生产等价，未被绕过）
 *   ⑤ PRAGMA defer_foreign_keys = ON（延迟校验已启用）
 *   ⑥ Site create → settings create → seeder → commit **全部成功后才提交**
 *
 * 若连接/事务边界不一致，SQLite 的 defer 会把 FK 错误推到 commit，
 * 表面通过、实际数据错乱 —— 故必须取证而非只看最终结果。
 *
 * ⚠️ 本探针**只读 + 自建临时事务**，不调用控制器、不改产品代码。
 * 它复刻 SiteController::store 的事务形状（同一代码路径的等价最小复现），
 * 用于证明「修的是 SQLite immediate-FK timing，而非连接边界错误」。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = getenv('GEO_DB');
config(['database.connections.sqlite.database' => $db, 'app.debug' => false]);

use Illuminate\Support\Facades\DB;

DB::purge('sqlite');

$out = ['points' => []];

function pt(array &$out, string $k, $v): void
{
    $out['points'][$k] = $v;
    // 逐点落盘：若脚本中途挂起，可据此定位精确挂起点
    file_put_contents('D:/Temp/rc5_probe_trace.txt',
        $k . ' = ' . var_export($v, true) . "\n", FILE_APPEND);
}

$conn = DB::connection();
$pdoBefore = $conn->getPdo();
pt($out, 'db_path', $db);
pt($out, 'is_file_db', is_file($db));
pt($out, 'fk_before', (int) $pdoBefore->query('PRAGMA foreign_keys')->fetchColumn());
pt($out, 'defer_before', (int) $pdoBefore->query('PRAGMA defer_foreign_keys')->fetchColumn());
pt($out, 'txn_level_before', DB::transactionLevel());

// ── 复刻 SiteController::store 的事务形状 ─────────────────────────
$slug = 'probe-' . substr(md5($db), 0, 8);

try {
    DB::beginTransaction();

    // ① / ② / ③：事务起点取证
    $pdoAtStart = DB::connection()->getPdo();
    pt($out, 'txn_level_in_tx', DB::transactionLevel());
    pt($out, 'same_pdo_as_before', $pdoAtStart === $pdoBefore);
    pt($out, 'fk_in_tx', (int) DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn());

    // ⑤：延迟校验（= C-18 的修法）
    DB::statement('PRAGMA defer_foreign_keys = ON');
    pt($out, 'defer_after_enable',
        (int) DB::connection()->getPdo()->query('PRAGMA defer_foreign_keys')->fetchColumn());

    // ── Site INSERT ──────────────────────────────────────────────
    $site = App\Models\Site::create([
        'name' => 'RC5 边界探针站', 'slug' => $slug,
        'domain' => $slug . '.test', 'status' => 'active', 'is_default' => false,
    ]);
    $pdoAfterSite = DB::connection()->getPdo();
    pt($out, 'site_pdo_same', $pdoAfterSite === $pdoAtStart);
    pt($out, 'site_id', (int) $site->id);
    pt($out, 'site_exists', (bool) $site->exists);
    pt($out, 'txn_level_after_site', DB::transactionLevel());

    // 事务内 SELECT 可见性（SQLite 已知：显式事务内可见自己插的行）
    $vis = DB::table('sites')->where('id', $site->id)->count();
    pt($out, 'select_visible_in_tx', (int) $vis);

    // ── Settings INSERT（同一 PDO / 同一事务）────────────────────
    App\Support\SiteContext::setSite($site);
    App\Models\Setting::flush();
    try { DB::table('cache')->delete(); } catch (Throwable $e) {}

    $settingsPdo = DB::connection()->getPdo();
    pt($out, 'settings_pdo_same', $settingsPdo === $pdoAtStart);
    pt($out, 'txn_level_before_settings', DB::transactionLevel());

    (new Database\Seeders\DefaultSettingSeeder())->run();
    $setCount = DB::table('settings')->where('site_id', $site->id)->count();
    pt($out, 'settings_rows', (int) $setCount);
    pt($out, 'txn_level_after_settings', DB::transactionLevel());

    // ── 其余 Seeder（结构 / 语言）────────────────────────────────
    (new Database\Seeders\DefaultFormSeeder())->run();
    (new Database\Seeders\BlankHomepageSeeder($site))->run();
    (new Database\Seeders\SystemPageSeeder($site))->run();
    (new Database\Seeders\SiteStructureSeeder())->run();

    pt($out, 'categories', (int) DB::table('categories')->where('site_id', $site->id)->count());
    pt($out, 'menus', (int) DB::table('menus')->where('site_id', $site->id)->count());
    pt($out, 'txn_level_before_commit', DB::transactionLevel());

    // ⑥：全部成功后才提交
    DB::commit();
    pt($out, 'committed', true);
    pt($out, 'txn_level_after_commit', DB::transactionLevel());

    // 提交后确认数据真实落库（不是被 rollback 掩盖）
    $siteRow = DB::table('sites')->where('id', $site->id)->count();
    $setRow  = DB::table('settings')->where('site_id', $site->id)->count();
    pt($out, 'post_commit_site_rows', (int) $siteRow);
    pt($out, 'post_commit_settings_rows', (int) $setRow);

    $out['result'] = 'OK';
} catch (Throwable $e) {
    $out['result'] = 'FAIL';
    $out['error'] = get_class($e) . ' — ' . mb_substr($e->getMessage(), 0, 220);
    try { DB::rollBack(); pt($out, 'rolled_back', true); }
    catch (Throwable $x) { pt($out, 'rolled_back', false); }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);