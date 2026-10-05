<?php
/**
 * C-18 取证：SiteController::store 事务内 FK 断点的确切位置
 * 生产等价条件：SQLite 文件库 + PRAGMA foreign_keys = ON
 */
$root = 'D:/GEO-OS-rewrite/geo-website-os';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
Illuminate\Support\Facades\DB::purge('sqlite');

use Illuminate\Support\Facades\DB;
$log = [];
$out = function (string $m) use (&$log) { $log[] = $m; };

$out('=== 环境 ===');
$out('DB = ' . config('database.connections.sqlite.database'));
$out('FK pragma = ' . DB::connection()->getPdo()->query('PRAGMA foreign_keys')->fetchColumn());

$out('');
$out('=== Schema 事实 ===');
$out('sites PK: ' . DB::select("select sql from sqlite_master where name='sites'")[0]->sql);
$out('settings FK:');
foreach (DB::select('PRAGMA foreign_key_list(settings)') as $f) {
    $out(sprintf('  %s -> %s.%s  on_delete=%s on_update=%s',
        $f->from, $f->table, $f->to, $f->on_delete, $f->on_update));
}
$out('现有 sites: ' . json_encode(DB::table('sites')->pluck('id')->all()));
$out('现有 settings 的 site_id 分布: ' . json_encode(
    DB::table('settings')->select('site_id', DB::raw('count(*) c'))->groupBy('site_id')->pluck('c', 'site_id')->all()
));

$out('');
$out('=== 逐 Seeder 定位断点（事务内）===');
try {
    DB::beginTransaction();

    $site = App\Models\Site::create([
        'name' => 'C18 探针', 'slug' => 'c18-probe', 'domain' => 'c18.test',
        'status' => 'active', 'is_default' => false,
    ]);

    $out('1) Site::create 后：');
    $out('   $site->id            = ' . var_export($site->id, true));
    $out('   $site->getKey()      = ' . var_export($site->getKey(), true));
    $out('   exists()             = ' . var_export($site->exists(), true));
    $out('   wasRecentlyCreated   = ' . var_export($site->wasRecentlyCreated, true));
    $out('   事务内 SELECT 可见?  = ' . (DB::table('sites')->where('id', $site->id)->exists() ? 'YES' : 'NO'));

    // 逐个 Seeder 单独试，定位确切断点
    foreach ([
        'DefaultSettingSeeder' => fn ($s) => (new Database\Seeders\DefaultSettingSeeder())->run(),
        'DefaultFormSeeder'     => fn ($s) => (new Database\Seeders\DefaultFormSeeder())->run(),
        'BlankHomepageSeeder'  => fn ($s) => (new Database\Seeders\BlankHomepageSeeder($s))->run(),
        'SystemPageSeeder'     => fn ($s) => (new Database\Seeders\SystemPageSeeder($s))->run(),
        'SiteStructureSeeder'  => fn ($s) => (new Database\Seeders\SiteStructureSeeder())->run(),
    ] as $name => $fn) {
        try {
            App\Support\SiteContext::setSite($site);
            App\Models\Setting::flush();
            DB::table('cache')->delete();
            $fn($site);
            $out("   [OK]   $name");
        } catch (Throwable $e) {
            $out("   [FAIL] $name → " . get_class($e));
            $out('          ' . mb_substr($e->getMessage(), 0, 220));
            // 打印 settings 写入时的实际 site_id 视角
            try {
                $out('          当前 SiteContext = ' . var_export(
                    optional(App\Support\SiteContext::getSite())->id, true));
            } catch (Throwable $x) {}
            break;
        }
    }
    DB::rollBack();
} catch (Throwable $e) {
    $out('OUTER FAIL: ' . mb_substr($e->getMessage(), 0, 200));
    try { DB::rollBack(); } catch (Throwable $x) {}
}

$out('');
$out('=== 回滚后（原子性检查）===');
$out('sites  = ' . json_encode(DB::table('sites')->pluck('slug')->all()));
$out('settings where site_id = c18 探针行数 = ' . DB::table('settings')->count());

file_put_contents(getenv('RC5_OUT'), implode("\n", $log));
