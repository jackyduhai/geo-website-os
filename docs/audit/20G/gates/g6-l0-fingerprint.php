<?php
/** 生成当前库（应为 L0/40迁移）的数据指纹，供回滚后比对 */
$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db = getenv('GEO_DB');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db;
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');
$fp = [];
foreach (Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
    $t = $r->name;
    $rows = Illuminate\Support\Facades\DB::table($t)->get();
    $pks = $rows->map(fn ($x) => (array) $x)->all();
    usort($pks, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    $cols = array_map(fn ($c) => $c->name, Illuminate\Support\Facades\DB::select("PRAGMA table_info(\"$t\")"));
    $dist = [];
    if (in_array('site_id', $cols, true)) {
        foreach (Illuminate\Support\Facades\DB::table($t)->selectRaw('site_id, COUNT(*) as c')->groupBy('site_id')->get() as $g) {
            $dist[(string) $g->site_id] = (int) $g->c;
        }
        ksort($dist);
    }
    $fp[$t] = ['count' => count($rows), 'site_id' => $dist,
               'sha' => substr(md5(json_encode($pks, JSON_UNESCAPED_UNICODE)), 0, 16)];
}
file_put_contents('D:/Temp/g6_fingerprint_l0.json', json_encode($fp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "L0 数据指纹已生成（" . count($fp) . " 张表）\n";
echo "  迁移数: " . Illuminate\Support\Facades\DB::table('migrations')->count() . "\n";
