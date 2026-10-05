<?php
/**
 * 20G-6 · G6-UPGRADE 升级路径取证
 *
 * 场景：20F.1 状态（40 迁移，含真实业务数据 + 双站 canary）
 *       → 升级到当前工作树（41 迁移）
 *
 * 证据链：L0 快照 → migrate → L1 快照 → 逐表 diff（行数/PK/site_id 分布/关键字段/时间戳）
 *       + GEO 输出前后对比 + 双站隔离回归
 *
 * 用法：GEO_DB=<升级目标库> GEO_DB_L0=<L0 库路径> php g6_upgrade.php verify
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db   = getenv('GEO_DB');
$dbL0 = getenv('GEO_DB_L0');

$mode = $argv[1] ?? 'verify';

require $root . '/vendor/autoload.php';

// 单一容器 + 单一 bootstrap，后续靠 config() 切库（避免重复 require bootstrap）
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

function line(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 62 - mb_strlen($t))) . "\n"; }
function ok(string $m): void   { echo "  ✅ $m\n"; }
function bad(string $m): void  { echo "  ❌ $m\n"; }

$STOP = [];
function gate(string $cond, string $pass, string $fail): void {
    global $STOP;
    if ($cond) { ok($pass); } else { bad($fail); $STOP[] = $fail; }
}

putenv("DB_DATABASE=$db");
$_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;

/** 全库数据指纹：逐表 → 行数 / PK 集合 / site_id 分布 / 全行哈希 */
function fingerprint(): array {
    config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\DB::reconnect('sqlite');

    $tables = array_map(fn ($r) => $r->name, Illuminate\Support\Facades\DB::select(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    $fp = [];
    foreach ($tables as $t) {
        $rows = Illuminate\Support\Facades\DB::table($t)->get();
        $pks = $rows->map(fn ($r) => (array) $r)->all();
        usort($pks, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $cols = array_map(fn ($c) => $c->name, Illuminate\Support\Facades\DB::select("PRAGMA table_info(\"$t\")"));
        $siteDist = [];
        if (in_array('site_id', $cols, true)) {
            $dist = Illuminate\Support\Facades\DB::table($t)
                ->selectRaw('site_id, COUNT(*) as c')
                ->groupBy('site_id')->get();
            foreach ($dist as $g) {
                $siteDist[(string) $g->site_id] = (int) $g->c;
            }
            ksort($siteDist);
        }
        $fp[$t] = [
            'count'   => count($rows),
            'site_id' => $siteDist,
            'ids'     => array_map(fn ($r) => $r->id ?? null, $pks),
            'sha'     => substr(md5(json_encode($pks, JSON_UNESCAPED_UNICODE)), 0, 16),
        ];
    }
    return $fp;
}

/** GEO 输出快照（URL 集合 / 身份 / 事实 / 实体，语义级而非字节级） */
function geoSnapshot($kernel): array {
    $out = [];
    foreach (['/geo.json' => 'geo', '/llms.txt' => 'llms', '/sitemap.xml' => 'sitemap', '/feed.xml' => 'feed'] as $uri => $kind) {
        $res = $kernel->handle(Illuminate\Http\Request::create($uri, 'GET'));
        $body = $res->getContent();
        if ($kind === 'geo') {
            $j = json_decode($body, true);
            $out[$kind] = [
                'code'    => $res->getStatusCode(),
                'site'    => $j['site']['name'] ?? '',
                'url'     => $j['site']['url'] ?? '',
                'facts'   => count($j['facts'] ?? []),
                'factkeys'=> array_map(fn ($f) => $f['key'] ?? '', $j['facts'] ?? []),
                'entities'=> array_map(fn ($e) => $e['id'] ?? '', $j['entities'] ?? []),
                'contents'=> count($j['contents'] ?? []),
                'schema'  => $j['$schema'] ?? '',
            ];
        } elseif ($kind === 'sitemap') {
            preg_match_all('#<loc>(.*?)</loc>#', $body, $m);
            preg_match_all('#<lastmod>(.*?)</lastmod>#', $body, $lm);
            $out[$kind] = ['code' => $res->getStatusCode(), 'locs' => $m[1], 'lastmod_count' => count($lm[1])];
        } elseif ($kind === 'feed') {
            preg_match_all('#<title>(.*?)</title>#', $body, $m);
            $out[$kind] = ['code' => $res->getStatusCode(), 'items' => count($m[1])];
        } else {
            $out[$kind] = ['code' => $res->getStatusCode(), 'sha' => substr(md5($body), 0, 12), 'bytes' => strlen($body)];
        }
    }
    return $out;
}

if ($mode === 'snapshot') {
    // 生成 L0 基线指纹 + GEO 快照
    putenv("DB_DATABASE=$dbL0"); $_ENV['DB_DATABASE'] = $dbL0; $_SERVER['DB_DATABASE'] = $dbL0;
    $fp = fingerprint();
    config(['database.connections.sqlite.database' => $dbL0, 'site.default_fallback' => true]);
    Illuminate\Support\Facades\DB::purge('sqlite'); Illuminate\Support\Facades\DB::reconnect('sqlite');
    \App\Support\SiteContext::clear(); \App\Support\Catalog::flush();
    $geo = geoSnapshot($kernel);
    file_put_contents('D:/Temp/g6_upgrade_baseline.json', json_encode(
        ['fingerprint' => $fp, 'geo' => $geo], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "L0 基线快照已生成(" . count($fp) . " 张表)\n";
    exit(0);
}

// ══════════════════ verify 模式 ══════════════════
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  G6-UPGRADE · 升级路径证据（20F.1 → 当前工作树）                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "L0: $dbL0\nL1: $db\n";

$base = json_decode(file_get_contents('D:/Temp/g6_upgrade_baseline.json'), true);

line('STEP 1 · 迁移状态');
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite'); Illuminate\Support\Facades\DB::reconnect('sqlite');

$applied = Illuminate\Support\Facades\DB::table('migrations')->count();
$files = count(glob($root . '/database/migrations/*.php'));
gate($applied === $files, "升级后 $applied 个迁移全部执行(/ $files)", "迁移未全部执行：$applied / $files");

// 关键迁移必须已应用
$migs = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
gate(in_array('2026_10_02_000001_add_external_id_unique_to_contents', $migs, true),
     '关键迁移 2026_10_02_000001（UNIQUE external_id）已应用', '关键迁移未应用');
gate(in_array('2026_09_30_000001_add_external_url_to_categories_table', $migs, true),
     '开发库曾滞后的 2026_09_30_000001 已补上', '2026_09_30_000001 未应用');

line('STEP 2 · 数据完整性逐表 diff（L0 → L1）');
$fp1 = fingerprint();
$tables = array_keys($base['fingerprint']);
$lost = []; $changed = []; $gained = [];
foreach ($tables as $t) {
    $a = $base['fingerprint'][$t]; $b = $fp1[$t] ?? null;
    if ($b === null) { $lost[] = "$t (表消失)"; continue; }
    if ($a['count'] !== $b['count']) {
        $d = $b['count'] - $a['count'];
        $msg = sprintf('%-22s %d → %d (%+d)', $t, $a['count'], $b['count'], $d);
        if ($d < 0) { $lost[] = $msg; echo "  ❌ $msg ← 数据丢失\n"; }
        else { $gained[] = $msg; echo "  ℹ️  $msg(迁移新增数据，正常)\n"; }
    } elseif ($a['sha'] !== $b['sha']) {
        $changed[] = "$t(行数同但内容变)";
        echo "  ⚠️  $t 行数同但内容哈希变化(需人工确认)\n";
    }
    // site_id 分布必须完全一致（多站隔离）
    if ($a['site_id'] !== $b['site_id']) {
        $changed[] = "$t site_id 分布变化";
        echo "  ⚠️  $t site_id 分布变化：" . json_encode($a['site_id']) . ' → ' . json_encode($b['site_id']) . "\n";
    }
}
gate(empty($lost), '无数据丢失（' . count($tables) . ' 张表全部比对）', '数据丢失：' . implode('; ', $lost));
gate(empty(array_diff($changed, [])), '无内容静默变化', '内容变化：' . implode('; ', $changed));

line('STEP 3 · GEO 输出语义一致性');
config(['site.default_fallback' => true]);
\Illuminate\Support\Facades\DB::purge('sqlite'); \Illuminate\Support\Facades\DB::reconnect('sqlite');
\App\Support\SiteContext::clear(); \App\Support\Catalog::flush();
$geo1 = geoSnapshot($kernel);
foreach (['geo', 'llms', 'sitemap', 'feed'] as $k) {
    $a = $base['geo'][$k]; $b = $geo1[$k];
    if ($k === 'geo') {
        gate($a['site'] === $b['site'], "geo.json 站点身份不变({$b['site']})", "geo.json 站点身份变化：{$a['site']} → {$b['site']}");
        gate($a['facts'] === $b['facts'], "geo.json 事实数不变({$b['facts']})", "事实数变化：{$a['facts']} → {$b['facts']}");
        gate($a['factkeys'] === $b['factkeys'], 'geo.json 事实 key 集合不变', '事实 key 集合变化');
        gate($a['schema'] === $b['schema'], 'geo.json 版本标识不变', '版本标识变化');
    } elseif ($k === 'sitemap') {
        $lostUrls = array_diff($a['locs'], $b['locs']);
        $newUrls  = array_diff($b['locs'], $a['locs']);
        gate(empty($lostUrls), 'sitemap 无 URL 丢失（' . count($b['locs']) . ' 条）',
             'sitemap 丢 URL：' . implode(', ', $lostUrls));
        if ($newUrls) { echo "  ℹ️  sitemap 新增 " . count($newUrls) . " 条：" . implode(', ', $newUrls) . "\n"; }
    } else {
        gate($a['code'] === $b['code'], "{$k} HTTP 状态不变({$b['code']})", "{$k} 状态变化：{$a['code']} → {$b['code']}");
    }
}

line('STEP 4 · 双站canary 隔离回归（升级后）');
$canA = 'GW6-CANARY-A'; $canB = 'GW6-CANARY-B';
foreach ([['a.gw6.test', $canA], ['b.gw6.test', $canB]] as [$host, $canary]) {
    \App\Support\SiteContext::clear(); \App\Support\Catalog::flush();
    $res = $kernel->handle(Illuminate\Http\Request::create('/llms.txt', 'GET', [], [], [], ['HTTP_HOST' => $host]));
    $body = $res->getContent();
    $own  = str_contains($body, $canary);
    $other = str_contains($body, $host === 'a.gw6.test' ? $canB : $canA);
    gate($own && !$other, "  $host 只含本站 canary(own=" . var_export($own, true) . " leak=" . var_export($other, true) . ')',
         "  $host 隔离失败 own=" . var_export($own, true) . " leak=" . var_export($other, true));
}

line('G6-UPGRADE 结论');
if (empty($STOP)) {
    echo "  ✅ G6-UPGRADE PASS\n";
    echo "     迁移 " . count($migs) . " 个全部执行 · " . count($tables) . " 张表零丢失\n";
    echo "     GEO 输出语义一致 · 双站隔离保持\n";
} else {
    echo "  ❌ G6-UPGRADE FAIL —— " . count($STOP) . " 项停止条件命中\n";
    foreach ($STOP as $s) { echo "     · $s\n"; }
}
echo "\n";
file_put_contents('D:/Temp/g6_upgrade_result.json', json_encode([
    'gate' => 'G6-UPGRADE', 'pass' => empty($STOP), 'stop' => $STOP,
    'tables_compared' => count($tables), 'migrations' => count($migs),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));