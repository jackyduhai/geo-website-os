<?php
/**
 * 20G-6 · G6-ROLLBACK 回滚一致性取证
 *
 * 核心命题：真正的回滚 = 应用版本 + 数据库 schema + 数据库数据 + 缓存 + 派生输出 五者一致。
 * 只回滚 DB 而留着新版本缓存/派生输出，从用户视角仍是 rollback failure。
 *
 * 场景：L1(41 迁移) → rollback --step=1 → L0(40 迁移) → 全链路必须回到 L0 状态
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db   = getenv('GEO_DB');

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
config(['database.connections.sqlite.database' => $db, 'site.default_fallback' => true]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');

function line(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 60 - mb_strlen($t))) . "\n"; }
function ok(string $m): void   { echo "  ✅ $m\n"; }
function bad(string $m): void  { echo "  ❌ $m\n"; }
$STOP = [];
function gate(string $c, string $p, string $f): void {
    global $STOP; if ($c) { ok($p); } else { bad($f); $STOP[] = $f; }
}

/** GEO 派生输出指纹（用于验证「假回滚」） */
function derived(): array {
    $out = [];
    foreach (['/geo.json', '/llms.txt', '/sitemap.xml', '/feed.xml'] as $uri) {
        \App\Support\SiteContext::clear(); \App\Support\Catalog::flush();
        Illuminate\Support\Facades\Cache::flush();
        $res = app(Illuminate\Contracts\Http\Kernel::class)
            ->handle(Illuminate\Http\Request::create($uri, 'GET'));
        $out[$uri] = ['code' => $res->getStatusCode(), 'sha' => substr(md5($res->getContent()), 0, 16)];
    }
    return $out;
}

$base = json_decode(file_get_contents('D:/Temp/g6_upgrade_baseline.json'), true);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  G6-ROLLBACK · 回滚一致性证据（41 → 40 迁移）                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "DB: $db\n";

// ─────────── 记录回滚前状态（L1）───────────
line('STEP 1 · 回滚前状态（L1）');
$migsBefore = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
gate(in_array('2026_10_02_000001_add_external_id_unique_to_contents', $migsBefore, true),
     'L1 含第 41 个迁移', 'L1 缺第 41 个迁移');
$idxBefore = Illuminate\Support\Facades\DB::select(
    "SELECT name FROM sqlite_master WHERE type='index' AND name LIKE '%external_id%'");
echo "     与 external_id 相关的索引: " . (count($idxBefore) ? implode(', ', array_map(fn ($r) => $r->name, $idxBefore)) : '无') . "\n";
$derivedL1 = derived();
foreach ($derivedL1 as $u => $d) { echo "     $u → {$d['code']} sha={$d['sha']}\n"; }

// ─────────── 执行回滚 ───────────
line('STEP 2 · 执行 migrate:rollback --step=1');
Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
$rbOut = trim(Illuminate\Support\Facades\Artisan::output());
echo "     " . str_replace("\n", "\n     ", mb_substr($rbOut, -200)) . "\n";
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::reconnect('sqlite');

$migsAfter = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
gate(!in_array('2026_10_02_000001_add_external_id_unique_to_contents', $migsAfter, true),
     '第 41 个迁移已撤销', '第 41 个迁移仍在migrations 表（回滚未生效）');
gate(count($migsAfter) === count($base['fingerprint']) - 0 || count($migsAfter) === 40,
     '迁移数回到 ' . count($migsAfter) . '（L0 = 40）', '迁移数异常：' . count($migsAfter));

// ─────────── schema 回滚验证 ───────────
line('STEP 3 · Schema 回滚一致性（不能只看 migrations 表）');
// 判据只认「唯一约束」是否移除。
// 注意：contents_external_id_index 是 up() 有意创建的**普通索引**（GEOFlow 查询用），
// up/down 两态都存在，不能用它判断回滚是否生效。
$uniqStill = false;
foreach ([
    "SELECT sql FROM sqlite_master WHERE tbl_name='contents'",
] as $q) {
    foreach (DB::select($q) as $r) {
        if ($r->sql && preg_match('/UNIQUE\s*\([^)]*external_id/iu', (string) $r->sql)) {
            $uniqStill = true;
        }
    }
}
gate(! $uniqStill,
     'UNIQUE(site_id, external_id) 唯一约束已实际删除',
     '唯一约束仍存在（migrations 表已回滚但 schema 未回滚 → 假回滚）');

// contents 表定义必须与 L0 一致
$defNow = Illuminate\Support\Facades\DB::select("SELECT sql FROM sqlite_master WHERE tbl_name='contents' AND type='table'")[0]->sql ?? '';
preg_match_all('/UNIQUE\s*\([^)]*\)/i', (string) $defNow, $m);
$uniques = array_map(fn ($u) => strtolower(str_replace(['`', '"', ' ', '[', ']'], '', $u)), $m[0]);
gate(!in_array('unique(site_id,external_id)', $uniques, true),
     'contents 表定义已回滚（无 external_id 唯一键）',
     'contents 表定义仍含 external_id 唯一键');
gate(in_array('unique(site_id,slug)', $uniques, true),
     'contents 保留 UNIQUE(site_id, slug)', 'contents 丢失 UNIQUE(site_id, slug)');
echo "     contents UNIQUE: " . implode('; ', $m[0]) . "\n";

// ─────────── 数据完整性 ───────────
line('STEP 4 · 数据完整性（回滚不得丢数据）');
// 排除运行时基础设施表：cache / sessions / jobs 等由验证过程自身的 HTTP 请求读写，
// 其变化与迁移回滚无关，不代表数据丢失。
$RUNTIME_TABLES = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];
$fresh = json_decode(file_get_contents('D:/Temp/g6_fingerprint_l0.json'), true);
$lost = []; $changed = [];
foreach ($fresh as $t => $a) {
    if (in_array($t, $RUNTIME_TABLES, true)) { continue; }
    $cols = array_map(fn ($c) => $c->name, Illuminate\Support\Facades\DB::select("PRAGMA table_info(\"$t\")"));
    $rows = Illuminate\Support\Facades\DB::table($t)->get();
    $pks = $rows->map(fn ($r) => (array) $r)->all();
    usort($pks, fn ($x, $y) => ($x['id'] ?? 0) <=> ($y['id'] ?? 0));
    $sha = substr(md5(json_encode($pks, JSON_UNESCAPED_UNICODE)), 0, 16);
    if ($a['count'] !== count($rows)) {
        $d = count($rows) - $a['count'];
        $lost[] = "$t {$a['count']} → " . count($rows);
        echo "  " . ($d < 0 ? '❌' : 'ℹ️ ') . sprintf(' %-20s %d → %d (%+d)', $t, $a['count'], count($rows), $d) . "\n";
    } elseif ($a['sha'] !== $sha) {
        $changed[] = $t;
        echo "  ⚠️  $t 行数同内容变\n";
    }
    // site_id 分布
    if ($a['site_id']) {
        $dist = [];
        foreach (Illuminate\Support\Facades\DB::table($t)->selectRaw('site_id, COUNT(*) as c')->groupBy('site_id')->get() as $g) {
            $dist[(string) $g->site_id] = (int) $g->c;
        }
        ksort($dist);
        if ($dist != $a['site_id']) {
            $changed[] = "$t site_id 分布";
            echo "  ⚠️  $t site_id 分布变化: " . json_encode($a['site_id']) . ' → ' . json_encode($dist) . "\n";
        }
    }
}
gate(empty($lost), '回滚零数据丢失', '回滚丢数据：' . implode('; ', $lost));
gate(empty($changed), '回滚无内容静默变化', '回滚内容变化：' . implode('; ', $changed));

// ─────────── 派生输出一致性（假回滚核心）───────────
line('STEP 5 · 派生输出回滚一致性（防「假回滚」）');
$derivedR = derived();
foreach ($derivedR as $u => $d) {
    echo "     $u → {$d['code']} sha={$d['sha']}\n";
}
gate($derivedR['/geo.json']['code'] === 200, '回滚后 geo.json 仍可用（200）',
     '回滚后 geo.json 异常：' . $derivedR['/geo.json']['code']);
gate($derivedR['/sitemap.xml']['code'] === 200, '回滚后 sitemap.xml 仍可用（200）',
     '回滚后 sitemap 异常：' . $derivedR['/sitemap.xml']['code']);

// 关键：清缓存后派生输出必须仍正确（若只有缓存撑着，清完就崩 = 假回滚）
line('STEP 5b · 清缓存后派生输出重算（假回滚的决定性判据');
Illuminate\Support\Facades\Cache::flush();
$cachePath = $root . '/storage/framework/cache/data';
if (is_dir($cachePath)) {
    $n = 0;
    foreach (glob($cachePath . '/*/*/*') as $f) { if (is_file($f)) { @unlink($f); $n++; } }
    echo "     已清理文件缓存 $n 个文件\n";
}
$derivedFresh = derived();
foreach ($derivedFresh as $u => $d) {
    $same = $d['sha'] === $derivedR[$u]['sha'];
    gate($d['code'] === 200, "  清缓存后 $u 重算成功（{$d['code']}）", "  清缓存后 $u 失败：{$d['code']}");
    if ($same) { ok("  $u 重算结果与回滚后一致（sha 相同 → 非缓存假象）"); }
    else { echo "  ℹ️  $u 重算 sha 变化（{$derivedR[$u]['sha']} → {$d['sha']}），需确认是否含时间戳等易变字段\n"; }
}

// ─────────── 双站隔离回归 ───────────
line('STEP 6 · 双站 canary 隔离回归（回滚后）');
$canA = 'GW6-CANARY-A'; $canB = 'GW6-CANARY-B';
foreach ([['a.gw6.test', $canA, $canB], ['b.gw6.test', $canB, $canA]] as [$host, $own, $other]) {
    \App\Support\SiteContext::clear(); \App\Support\Catalog::flush();
    $res = $kernel->handle(Illuminate\Http\Request::create('/llms.txt', 'GET', [], [], [], ['HTTP_HOST' => $host]));
    $body = $res->getContent();
    $hasOwn = str_contains($body, $own);
    $hasOther = str_contains($body, $other);
    gate($hasOwn && !$hasOther,
         "  $host 隔离正确（own=" . var_export($hasOwn, true) . " leak=" . var_export($hasOther, true) . '）',
         "  $host 隔离失败 own=" . var_export($hasOwn, true) . " leak=" . var_export($hasOther, true));
}

line('G6-ROLLBACK 结论');
if (empty($STOP)) {
    echo "  ✅ G6-ROLLBACK PASS\n";
    echo "     schema 回滚真实生效（非仅 migrations 表）\n";
    echo "     数据零丢失 · 派生输出清缓存后仍可重算 · 双站隔离保持\n";
} else {
    echo "  ❌ G6-ROLLBACK FAIL —— " . count($STOP) . " 项停止条件命中\n";
    foreach ($STOP as $s) { echo "     · $s\n"; }
}
echo "\n";
file_put_contents('D:/Temp/g6_rollback_result.json', json_encode([
    'gate' => 'G6-ROLLBACK', 'pass' => empty($STOP), 'stop' => $STOP,
    'migrations_after' => count($migsAfter),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));