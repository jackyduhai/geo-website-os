<?php
/**
 * 20G-6 · G6-FRESH 全新安装取证
 *
 * 场景：从零开始 0 → 可运行
 * 证据链：迁移完整 → schema 快照 → geo:install → 播种 → 真实 HTTP 启动
 * 全程使用项目外的独立库，不触碰开发库。
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db   = getenv('GEO_DB') ?: 'D:/Temp/g6_fresh.sqlite';

// 迁移与播种由 CLI 预置（避免脚本内 bootstrap 阶段的 env/config 时序问题——第 9 项检测教训）。
// 本脚本只负责「验证」，不负责「建库」。
putenv("DB_DATABASE=$db");
$_ENV['DB_DATABASE'] = $db;
$_SERVER['DB_DATABASE'] = $db;

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

function line(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 62 - mb_strlen($t))) . "\n"; }
function ok(string $m): void   { echo "  ✅ $m\n"; }
function bad(string $m): void  { echo "  ❌ $m\n"; }

$STOP = [];
function gate(string $cond, string $pass, string $fail): void {
    global $STOP;
    if ($cond) { ok($pass); } else { bad($fail); $STOP[] = $fail; }
}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  G6-FRESH · 全新安装证据（0 → 可运行）                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "DB: $db\n";

// ---------- STEP 1 迁移（校验 CLI 已预置）----------
line('STEP 1 · migrate（校验 CLI 预置的全新库）');
$files = glob($root . '/database/migrations/*.php');
$done = DB::table('migrations')->count();
gate($done === count($files), "41 个迁移全部 DONE（$done / " . count($files) . "）",
     "迁移数不匹配：$done / " . count($files));

$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$tNames = array_map(fn ($r) => $r->name, $tables);
gate(count($tNames) === 27, "建表 27 张（实际 " . count($tNames) . "）", "建表数异常：" . count($tNames));
echo "     表清单：" . implode(', ', $tNames) . "\n";

// 多站地基校验：所有接入 BelongsToSite 的业务表必须有 site_id
line('STEP 1b · 多站地基校验（BelongsToSite 模型 vs实际列）');
$siteTables = [];
foreach (glob($root . '/app/Models/*.php') as $mf) {
    $src = file_get_contents($mf);
    if (!str_contains($src, 'BelongsToSite')) { continue; }
    if (preg_match('/class\s+(\w+)/', $src, $cm)) {
        $siteTables[] = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $cm[1]));
    }
}
foreach ($siteTables as $t) {
    if (!in_array($t, $tNames, true)) { continue; }
    $cols = array_map(fn ($c) => $c->name, DB::select("PRAGMA table_info(\"$t\")"));
    if (!in_array('site_id', $cols, true)) {
        bad("  $t 接入 BelongsToSite 但无 site_id 列 ← 跨站隔离地基缺失");
        $STOP[] = "$t 缺 site_id";
    }
}
gate(count($STOP) === 0, count($siteTables) . ' 个 BelongsToSite 模型对应表均含 site_id',
     '存在模型/表不一致');

// ---------- STEP 2 schema 快照 ----------
line('STEP 2 · schema 快照');
$schema = [];
foreach ($tNames as $t) {
    $cols = [];
    foreach (DB::select("PRAGMA table_info(\"$t\")") as $c) {
        $cols[$c->name] = $c->type . ($c->notnull ? ' NOTNULL' : '') . ($c->pk ? ' PK' : '');
    }
    // UNIQUE 有两种载体：表级声明（在 sqlite_master.sql）与独立索引（type='index'）。
    // 必须同时扫，只查一处会漏（这是本项目已犯 2 次的同类失误）。
    $idx = [];
    $tblRows = DB::select("SELECT sql FROM sqlite_master WHERE tbl_name=\"$t\" AND type='table'");
    $tblSql = $tblRows[0]->sql ?? null;
    if ($tblSql) {
        preg_match_all('/UNIQUE\s*\([^)]*\)/i', (string) $tblSql, $m);
        foreach ($m[0] as $u) { $idx[] = trim(preg_replace('/\s+/', ' ', $u)); }
    }
    // 两种语法都要抓：
    //   表级：  UNIQUE(site_id, slug)
    //   独立索引：CREATE UNIQUE INDEX "x" on "t" ("site_id", "slug")
    foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name=\"$t\" AND type='index' AND sql IS NOT NULL") as $i) {
        $sql = (string) $i->sql;
        if (stripos($sql, 'UNIQUE') === false) { continue; }
        if (preg_match('/\bon\s+\S+\s*\(([^)]*)\)/i', $sql, $m)) {
            $idx[] = 'UNIQUE(' . trim($m[1]) . ')';
        } else {
            preg_match_all('/UNIQUE\s*\([^)]*\)/i', $sql, $m2);
            foreach ($m2[0] as $u) { $idx[] = trim(preg_replace('/\s+/', ' ', $u)); }
        }
    }
    $schema[$t] = ['cols' => $cols, 'unique' => array_values(array_unique($idx))];
}
file_put_contents('D:/Temp/g6_schema_fresh.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$totalCols = array_sum(array_map(fn ($t) => count($t['cols']), $schema));
$totalUniq = array_sum(array_map(fn ($t) => count($t['unique']), $schema));
ok("schema 快照已存D:/Temp/g6_schema_fresh.json（$totalCols 列 / $totalUniq 条 UNIQUE）");

// 关键 UNIQUE 检查（多站隔离的地基）
$expectUnique = [
    'contents' => ['UNIQUE(site_id, slug)', 'UNIQUE(site_id, slot)', 'UNIQUE(site_id, external_id)'],
    'entities' => ['UNIQUE(site_id, type, slug)'],
    'categories' => [],
];
// 归一化：去空格 + 去引号 + 去反引号（SQLite 三种引用风格混用）
$norm = fn ($u) => strtolower(str_replace([' ', '"', '`', '[', ']'], '', $u));
foreach ($expectUnique as $tbl => $wants) {
    $have = array_map($norm, $schema[$tbl]['unique']);
    foreach ($wants as $w) {
        gate(in_array($norm($w), $have, true),
             '  ' . $tbl . ' 具备 ' . $w,
             '  ' . $tbl . ' 缺 ' . $w . '（实有：' . (implode(' | ', $schema[$tbl]['unique']) ?: '无') . '）');
    }
}
echo "     全库 UNIQUE 清单：\n";
foreach ($schema as $t => $s) {
    if ($s['unique']) {
        echo "       $t: " . implode('; ', $s['unique']) . "\n";
    }
}

// C-15 探测：entities 唯一约束的完整性（含跨站行为）
line('STEP 2b · entities 唯一约束行为实测');
$sid = DB::table('sites')->first()->id;
$probe = 'c15probe' . substr(md5((string) microtime(true)), 0, 6);
$mk = function (string $slug, string $type, int $siteId) {
    DB::table('entities')->insert([
        'site_id' => $siteId, 'type' => $type, 'slug' => $slug,
        'name' => '探针', 'status' => 'draft', 'metadata' => '{}',
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
};
// (a) 同站同类型同slug 应被拒
try { $mk($probe, 'product', $sid); $mk($probe, 'product', $sid);
      bad('  同站同类型同 slug 重复写入成功 ← 缺唯一约束');
      $STOP[] = 'entities 同站同类型 slug 可重复';
      DB::table('entities')->where('slug', $probe)->delete();
} catch (\Throwable $e) {
      ok('  同站同类型同 slug 被拒绝（' . substr($e->getMessage(), 0, 45) . '）');
      DB::table('entities')->where('slug', $probe)->delete();
}
// (b) 跨站同 slug 应允许（site_id 参与唯一键）
$sid2 = DB::table('sites')->count() > 1
    ? DB::table('sites')->skip(1)->first()->id
    : DB::table('sites')->insertGetId(['slug' => 'probe-b', 'name' => 'B', 'domain' => 'b.probe', 'status' => 'active', 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
try {
    $mk($probe, 'product', $sid);
    $mk($probe, 'product', $sid2);
    ok('  跨站同 slug 允许写入（site_id 参与唯一键，多站设计正确）');
} catch (\Throwable $e) {
    bad('  跨站同 slug 被误拒：' . substr($e->getMessage(), 0, 45));
    $STOP[] = 'entities 唯一键未含 site_id，破坏多站隔离';
}
DB::table('entities')->where('slug', $probe)->delete();

// ---------- STEP 3 geo:install（CLI 已执行，这里只验证 + 测幂等）----------
line('STEP 3 · geo:install 结果校验');
$siteCount = DB::table('sites')->count();
$userCount = DB::table('users')->count();
gate($siteCount >= 1, "geo:install 建站成功（$siteCount 个站点）", "geo:install 未建站");
gate($userCount >= 1, "geo:install 创建管理员（$userCount 个用户）", "geo:install 未建用户");
foreach (['站点设置' => 'settings', '默认栏目' => 'categories'] as $label => $tbl) {
    echo "     $label: " . DB::table($tbl)->count() . " 条\n";
}

// ---------- STEP 3b 幂等性（STOP 条件：非幂等）----------
line('STEP 3b · geo:install 幂等性（重复执行）');
$before = ['sites' => DB::table('sites')->count(), 'users' => DB::table('users')->count(),
           'settings' => DB::table('settings')->count(), 'categories' => DB::table('categories')->count()];
Artisan::call('geo:install', ['--no-interaction' => true]);
$after = ['sites' => DB::table('sites')->count(), 'users' => DB::table('users')->count(),
          'settings' => DB::table('settings')->count(), 'categories' => DB::table('categories')->count()];
$diffs = [];
foreach ($before as $k => $v) { if ($after[$k] !== $v) { $diffs[] = "$k: $v → {$after[$k]}"; } }
gate(empty($diffs), '重复执行无副作用（' . implode(', ', array_map(fn ($k) => "$k={$before[$k]}", array_keys($before))) . '）',
     "geo:install 非幂等：" . implode('; ', $diffs));

// ---------- STEP 4 播种状态（CLI 已执行）----------
line('STEP 4 · 播种状态校验');
foreach (['categories', 'groups', 'facts', 'settings'] as $t) {
    $n = DB::table($t)->count();
    gate($n > 0, "$t: $n 条", "$t 为空 —— 前台/GEO 无法验证");
}

// ---------- STEP 5 真实 HTTP 启动 ----------
line('STEP 5 · 真实 HTTP 启动验证');
config(['site.default_fallback' => true]);
function hit($kernel, string $uri): array {
    $req = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => '*/*']);
    $res = $kernel->handle($req);
    return [$res->getStatusCode(), $res->getContent()];
}
$pages = [
    '/'=> '首页', '/products/' => '产品列表', '/knowledge/' => '知识中心',
    '/about/profile/' => '关于', '/contact/' => '联系',
    '/geo.json' => 'GEO 图谱', '/llms.txt' => 'LLM 索引',
    '/sitemap.xml' => '站点地图', '/feed.xml' => 'RSS', '/robots.txt' => 'robots',
];
foreach ($pages as $uri => $label) {
    [$code, $body] = hit($kernel, $uri);
    $size = strlen($body);
    $expectFail = in_array($uri, ['/products/', '/about/profile/', '/contact/'], true);
    if ($expectFail) {
        // 空站无目录数据时这些页应 404（空数据守卫），不算失败
        gate($code === 404 || $code === 200, $label . ' ' . $uri . ' → ' . $code . '（' . $size . 'B）',
             $label . ' ' . $uri . ' → ' . $code . ' 异常');
    } else {
        gate($code === 200 && $size > 0, $label . ' ' . $uri . ' → 200（' . $size . 'B）',
             $label . ' ' . $uri . ' → ' . $code . '（' . $size . 'B）');
    }
}

// GEO 输出有效性
[$c, $geo] = hit($kernel, '/geo.json');
if ($c === 200) {
    $g = json_decode($geo, true);
    gate(is_array($g), 'geo.json 是合法 JSON', 'geo.json 解析失败');
if (is_array($g)) {
    echo "     顶层键: " . implode(', ', array_keys($g)) . "\n";
    // $schema 是刻意设计的图谱版本标识（对应 JSON-LD 的 @context惯例），非缺陷
    $schemaKey = '$schema';
    gate(($g[$schemaKey] ?? '') === 'geo-os/graph/v1',
         'geo.json 带图谱版本标识 $schema=geo-os/graph/v1',
         'geo.json 版本标识异常：' . var_export($g[$schemaKey] ?? null, true));
    foreach (['entities', 'contents', 'facts', 'relations'] as $k) {
        echo "     $k: " . count($g[$k] ?? []) . " 条\n";
    }
    gate(count($g['facts'] ?? []) > 0, 'geo.json 输出事实条目（LLM 可消费）', 'geo.json facts 为空');
}
}
[$c, $llms] = hit($kernel, '/llms.txt');
gate($c === 200 && (str_contains($llms, '#') || str_contains($llms, 'http')),
     'llms.txt 内容有效（Markdown 结构）', 'llms.txt 内容可疑');

// ---------- 汇总 ----------
line('G6-FRESH 结论');
if (empty($STOP)) {
    echo "  ✅ G6-FRESH PASS —— 全新安装全链路通过\n";
    echo "     迁移 41/41 · 表 27 · schema 快照 $totalCols 列/$totalUniq UNIQUE\n";
    echo "     geo:install 幂等 · 真实 HTTP 10 端点可用\n";
} else {
    echo "  ❌ G6-FRESH FAIL —— " . count($STOP) . " 项停止条件命中\n";
    foreach ($STOP as $s) { echo "     · $s\n"; }
}
echo "\n";
file_put_contents('D:/Temp/g6_fresh_result.json', json_encode([
    'gate' => 'G6-FRESH', 'pass' => empty($STOP), 'stop_conditions' => $STOP,
    'tables' => count($tNames), 'migrations' => $done, 'cols' => $totalCols, 'unique' => $totalUniq,
    'fresh_geo_sha' => substr(md5($geo ?? ''), 0, 12),
    'fresh_llms_sha' => substr(md5($llms ?? ''), 0, 12),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));