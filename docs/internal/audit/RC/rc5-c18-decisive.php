<?php
/**
 * RC-5 · C-18 决定性对照实验
 * ------------------------------------------------------------------
 * 变异测试发现：把 `PRAGMA defer_foreign_keys = ON` 换成无效 PRAGMA 后，
 * `SiteCreationProductionParityTest` 的 7 个测试**依然全部通过**。
 * → 说明该修法在现有测试里**没有牙齿**，必须查清 C-18 本身是否成立。
 *
 * 本脚本做唯一变量对照：
 *   组 A：完全复刻 SiteController::store 事务形状（含真实 5 个 Seeder）
 *         但**不开** defer
 *   组 B：同上，**开** defer
 *
 * 除此之外所有条件完全相同：同一文件库、FK=ON、同一连接、同一代码路径形状。
 * 若A 失败 B 成功 → C-18 为真，且修法有牙；
 * 若 A 也成功 → 原失败另有原因，C-18 是**误判**，必须撤案。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = getenv('GEO_DB');
config(['database.connections.sqlite.database' => $db, 'app.debug' => false]);

use Illuminate\Support\Facades\DB;

DB::purge('sqlite');

$out = ['groups' => []];

/**
 * 复刻 SiteController::store 的事务形状。
 * @param bool $defer 是否启用 defer_foreign_keys
 */
function trial(string $root, bool $defer, string $suffix): array
{
    $g = ['defer' => $defer, 'points' => []];

    DB::beginTransaction();
    $g['points']['txn_level'] = DB::transactionLevel();
    $g['points']['fk'] = (int) DB::connection()->getPdo()
        ->query('PRAGMA foreign_keys')->fetchColumn();
    $g['points']['pdo_id'] = spl_object_id(DB::connection()->getPdo());

    if ($defer) {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    $g['points']['defer_now'] = (int) DB::connection()->getPdo()
        ->query('PRAGMA defer_foreign_keys')->fetchColumn();

    try {
        // ── 完全复刻控制器：Site::create ──
        $site = App\Models\Site::create([
            'name' => 'RC5 对照 ' . $suffix,
            'slug' => 'rc5cmp' . $suffix,
            'domain' => 'rc5cmp' . $suffix . '.test',
            'status' => 'active',
            'is_default' => false,
        ]);
        $g['points']['site_id'] = (int) $site->id;

        // 事务内 SELECT 可见性（原始取证曾报NO）
        $vis = DB::table('sites')->where('id', $site->id)->exists();
        $g['points']['site_visible_in_tx'] = $vis ? 'YES' : 'NO';
        $g['points']['pdo_same_after_site'] =
            (spl_object_id(DB::connection()->getPdo()) === $g['points']['pdo_id']);

        // ── 完全复刻控制器：SiteContext::withSite + 5 个 Seeder ──
        App\Support\SiteContext::withSite($site, function () use ($site, $g): void {
            (new Database\Seeders\DefaultSettingSeeder())->run();
            (new Database\Seeders\DefaultFormSeeder())->run();
            (new Database\Seeders\BlankHomepageSeeder($site))->run();
            (new Database\Seeders\SystemPageSeeder($site))->run();
            (new Database\Seeders\SiteStructureSeeder())->run();
        });

        $g['points']['settings_rows'] =
            (int) DB::table('settings')->where('site_id', $site->id)->count();
        $g['points']['categories'] =
            (int) DB::table('categories')->where('site_id', $site->id)->count();
        $g['points']['menus'] =
            (int) DB::table('menus')->where('site_id', $site->id)->count();
        $g['points']['txn_level_before_commit'] = DB::transactionLevel();

        DB::commit();
        $g['result'] = 'OK';
        $g['points']['txn_level_after_commit'] = DB::transactionLevel();

        // 提交后复核：确认数据真实落库
        $g['points']['post_commit_site'] =
            (int) DB::table('sites')->where('slug', 'rc5cmp' . $suffix)->count();
        $g['points']['post_commit_settings'] =
            (int) DB::table('settings')->where('site_id', $site->id)->count();
    } catch (Throwable $e) {
        $g['result'] = 'FAIL';
        $g['error'] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 180);
        try { if (DB::transactionLevel() > 0) { DB::rollBack(); $g['rolled_back'] = true; } }
        catch (Throwable $x) {}
    }
    return $g;
}

// 先记录基线
$out['baseline_sites'] = (int) DB::table('sites')->count();
$out['baseline_fk'] = (int) DB::connection()->getPdo()
    ->query('PRAGMA foreign_keys')->fetchColumn();
$out['db'] = $db;
$out['is_file'] = is_file($db);

$out['groups']['A_defer_off'] = trial($root, false, 'off');
$out['groups']['B_defer_on']  = trial($root, true,  'on');

$out['final_sites'] = (int) DB::table('sites')->count();
$out['verdict'] = [
    'A_result' => $out['groups']['A_defer_off']['result'],
    'B_result' => $out['groups']['B_defer_on']['result'],
    'c18_is_real' => ($out['groups']['A_defer_off']['result'] === 'FAIL'
                  && $out['groups']['B_defer_on']['result'] === 'OK'),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);