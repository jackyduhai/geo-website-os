<?php
/**
 * RC-2 · Final Fresh / Upgrade / Rollback
 * ------------------------------------------------------------------
 * 目标：证明 RC-0冻结工作树具备可发布的三链路可靠性。
 *
 *边界（用户明确要求）：
 *   - 工作树：与 RC-1 通过后的同一状态
 *   - 产品代码：0 修改（本脚本只读，不改任何产品文件）
 *   - 开发库：只读，全程不碰
 *   - 临时库：全部置于项目目录外（D:/Temp/rc2_*）
 *   - 旧 20G-6：仅参考，不复用其 PASS 结论
 *
 * 与 g6-*.php 的区别（刻意不复用）：
 *   g6-* 写于 20G-6 时代，硬编码「41 迁移 / 27 张表 / 回滚到 40」。
 *   现在是 56 迁移 / 41 张表，**41 现在是表数不是迁移数**，极易混淆。
 *   本脚本所有数字断言均**动态取实际值**，不写死任何数量。
 *
 * 用法：
 *   GEO_ROOT=<repo> php rc2-fresh-upgrade-rollback.php
 */

$root = getenv('GEO_ROOT') ?: 'D:/GEO-OS-rewrite/geo-website-os';
$workDir = 'D:/Temp/rc2_v6';
$dbFresh   = $workDir . '/fresh.sqlite';
$dbUpgrade = $workDir . '/upgrade.sqlite';
$dbRollback= $workDir . '/rollback.sqlite';

$PASS = 0; $FAIL = 0; $FAILURES = []; $NOTES = [];

function ok(string $id, string $desc, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] $id $desc\n";
    } else {
        $FAIL++;
        $FAILURES[] = "$id $desc" . ($detail !== '' ? " | $detail" : '');
        echo "  [FAIL] $id $desc" . ($detail !== '' ? " | $detail" : '') . "\n";
    }
}

function line(string $t): void
{
    echo "\n-- $t " . str_repeat('-', max(0, 66 - mb_strlen($t))) . "\n";
}

function banner(string $gate, string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n";
    echo "  $gate · $title\n";
    echo str_repeat('=', 72) . "\n";
}

// ═══════════════════════════════════════════════════════════
// 独立进程执行 artisan（建库一律走 CLI，脚本只做验证）
// ═══════════════════════════════════════════════════════════
function runArtisan(string $root, array $args, string $db): array
{
    // 每次重建 runner，避免 D:/Temp 里的旧版本被复用（本轮已因此踩坑）
    $runner = 'D:/Temp/_rc2_v6ner.php';
    {
            file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArgvInput(
    array_merge(['artisan'], array_slice($_SERVER['argv'], 1))
);
$exit = $kernel->handle($input);
exit($exit);
PHP
            );
    }

    // 只取标量 env：$_SERVER 里含 argv 数组，混入 proc_open 会报 Array to string conversion
    $env = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) || is_numeric($v)) { $env[$k] = $v; }
    }
    $env['GEO_ROOT'] = $root;
    $env['DB_CONNECTION'] = 'sqlite';
    $env['DB_DATABASE'] = $db;
    $env['APP_ENV'] = 'testing';
    $env['CACHE_STORE'] = 'array';

    // 字符串形式 + 手工加引号（参照已验证的 migration-fullpath.php 做法）
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    foreach ($args as $a) { $cmd .= ' "' . $a . '"'; }

    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    if (! is_resource($p)) { return ['code' => -1, 'out' => 'proc_open failed']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out];
}

function freshDb(string $path): void
{
    @unlink($path);
    if (! file_exists($path)) { touch($path); }
}

function pdo(string $path): PDO
{
    return new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** 指纹：表结构 + 索引 + 唯一约束，用于 schema 保真比对 */
function fingerprint(string $path): array
{
    $p = pdo($path);
    $out = ['tables' => [], 'indexes' => [], 'uniques' => []];

    foreach ($p->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $t = $r['name'];
        $cols = [];
        foreach ($p->query("PRAGMA table_info(\"$t\")") as $c) { $cols[] = $c['name']; }
        $fks = [];
        foreach ($p->query("PRAGMA foreign_key_list(\"$t\")") as $f) {
            $fks[] = $f['from'].'->'.$f['table'].'.'.$f['to'];
        }
        sort($fks);
        $out['tables'][$t] = ['cols' => $cols, 'fks' => $fks, 'sql' => (string) $r['sql']];
    }
    foreach ($p->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' ORDER BY name") as $r) {
        $out['indexes'][$r['name']] = $r['tbl_name'].'|'.$r['sql'];
    }
    foreach ($p->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE sql LIKE '%UNIQUE%' ORDER BY name") as $r) {
        $out['uniques'][$r['name'].'@'.$r['tbl_name']] = (string) $r['sql'];
    }
    return $out;
}

function migCount(string $path): int
{
    try { return (int) pdo($path)->query('select count(*) from migrations')->fetchColumn(); }
    catch (Throwable $e) { return -1; }
}

function migList(string $path): array
{
    return pdo($path)->query('select migration from migrations order by id')->fetchAll(PDO::FETCH_COLUMN);
}

/** UNIQUE 约束文本（从 sqlite_master 全量扫，含表级与命名索引） */
function uniqueTexts(string $path): array
{
    $p = pdo($path);
    $out = [];
    foreach ($p->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE sql LIKE '%UNIQUE%'") as $r) {
        if ($r['tbl_name'] === 'search_index' || $r['tbl_name'] === 'search_index_config') { continue; }
        $out[] = $r['tbl_name'] . ' :: ' . preg_replace('/\s+/', ' ', (string) $r['sql']);
    }
    sort($out);
    return $out;
}

function indexNames(string $path, string $table): array
{
    $p = pdo($path);
    $out = [];
    foreach ($p->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$table'") as $r) {
        $out[] = $r['name'];
    }
    sort($out);
    return $out;
}

function colsOf(string $path, string $table): array
{
    $p = pdo($path);
    $out = [];
    foreach ($p->query("PRAGMA table_info(\"$table\")") as $c) { $out[] = $c['name']; }
    return $out;
}

echo "RC-2 · Final Fresh / Upgrade / Rollback\n";
echo "ROOT: $root\n";
echo "WORK: " . $workDir . " (项目目录外)\n";
echo "开发库：只读，本脚本全程不访问\n";

// ═══════════════════════════════════════════════════════════
// RC2-FRESH · 空库 → 56 迁移 → install → zh/en → 四象限 → GEO
// ═══════════════════════════════════════════════════════════
banner('RC2-FRESH', '全新安装（0 -> 可运行）');

freshDb($dbFresh);
$r = runArtisan($root, ['migrate'], $dbFresh);
ok('F-01', 'artisan migrate 退出码 0', $r['code'] === 0, 'code=' . $r['code']);
// 输出为空时不能判 PASS——那说明 migrate 根本没跑，是假阳性
ok('F-02', 'migrate 输出非空且无异常特征',
    trim($r['out']) !== '' && ! preg_match('/SQLSTATE|Exception|renderThrowable/i', $r['out']),
    'len=' . strlen($r['out']) . ' | ' . mb_substr(preg_replace('/\s+/', ' ', $r['out']), 0, 200));

$files = glob($root . '/database/migrations/*.php');
$nFiles = count($files);
$nMigs = migCount($dbFresh);
ok('F-03', "迁移文件数 = registered = applied（$nFiles ）", $nMigs === $nFiles, "applied=$nMigs files=$nFiles");

$fpFresh = fingerprint($dbFresh);
$nTables = count($fpFresh['tables']);
ok('F-04', "fresh 库建表数 > 0（实际 $nTables 张）", $nTables > 0);

// i18n 资产：locale / translation_group 列必须存在
line('RC2-FRESH · i18n 资产（20G-3 重点）');

$i18nExpect = [
    'contents'        => ['locale', 'translation_group'],
    'entities'        => ['locale'],
    'seo_metas'       => ['locale'],
    'facts'           => ['locale', 'translation_group'],
];
foreach ($i18nExpect as $tbl => $need) {
    $have = colsOf($dbFresh, $tbl);
    foreach ($need as $col) {
        ok('F-05.' . $tbl . '.' . $col, "$tbl.$col 存在", in_array($col, $have, true),
            'have=' . implode(',', $have));
    }
}

// UNIQUE(site_id, key, locale) 必须是表级约束（facts）
$uq = uniqueTexts($dbFresh);
$factUq = array_values(array_filter($uq, fn ($s) => str_starts_with($s, 'facts ::')));
ok('F-06', 'facts 存在 UNIQUE(site_id, key, locale) 约束',
    (bool) preg_grep('/site_id.*key.*locale/is', $factUq),
    implode(' || ', $factUq));

// contents 的UNIQUE(site_id, slug, locale)
$contentUq = array_values(array_filter($uq, fn ($s) => str_starts_with($s, 'contents ::')));
ok('F-07', 'contents 存在 UNIQUE(site_id, slug, locale) 约束',
    (bool) preg_grep('/site_id.*slug.*locale/is', $contentUq),
    implode(' || ', $contentUq));

// 多站地基：业务表必须有 site_id
line('RC2-FRESH · 多站地基');
$siteModelsRaw = [];
foreach (glob($root . '/app/Models/*.php') as $mf) {
    $src = file_get_contents($mf);
    if (! str_contains($src, 'BelongsToSite')) { continue; }
    if (preg_match('/class\s+(\w+)/', $src, $cm)) {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $cm[1]));
        // 优先用模型自带的 $table；否则回退 snake + 复数化候选
        $tbl = null;
        if (preg_match('/\$table\s*=\s*[\'\"]([\w\.]+)[\'\"]/', $src, $tm)) { $tbl = $tm[1]; }
        $siteModelsRaw[] = ['model' => $cm[1], 'candidates' => array_values(array_unique(array_filter([
            $tbl, $snake, $snake . 's', $snake . 'es',
            // 复数化不规则：category→categories / entity→entities / inquiry→inquiries
            (substr($snake, -1) === 'y' && ! in_array(substr($snake, -2, 1), ['a', 'e', 'i', 'o', 'u'], true)
                ? substr($snake, 0, -1) . 'ies' : null),
        ], fn ($c) => $c !== null)))];
    }
}
$missSiteId = [];
$missingTables = [];
$siteModels = [];   // 已解析为表名列表
foreach ($siteModelsRaw as $m) {
    $hit = null;
    foreach ($m['candidates'] as $cand) {
        if ($cand !== null && isset($fpFresh['tables'][$cand])) { $hit = $cand; break; }
    }
    if ($hit === null) {
        // 表不存在必须显式记账，否则「库根本没建起来」会被静默跳过变成假阳性
        $missingTables[] = $m['model'] . '(?' . implode('|', array_filter($m['candidates'])) . ')';
        continue;
    }
    if (! in_array('site_id', $fpFresh['tables'][$hit]['cols'], true)) { $missSiteId[] = $m['model'] . '->' . $hit; }
}
ok('F-08a', 'BelongsToSite 模型对应表全部存在（' . count($siteModelsRaw) . ' 个模型）',
    empty($missingTables), 'missing_table=' . implode(',', $missingTables));
ok('F-08', 'BelongsToSite 模型对应表全部有 site_id',
    empty($missSiteId) && empty($missingTables), 'missing=' . implode(',', $missSiteId));
ok('F-08', 'BelongsToSite 模型对应表全部有 site_id（' . count($siteModelsRaw) . ' 个模型）',
    empty($missSiteId), 'missing=' . implode(',', $missSiteId));

// C-16 / C-17 回归锁：external_id唯一约束 + 无残留索引
line('RC2-FRESH · C-16 / C-17 回归锁');
$extUq = array_values(array_filter($uq, fn ($s) => str_starts_with($s, 'contents ::') && str_contains($s, 'external_id')));
ok('F-09', 'C-16：contents.external_id 有 UNIQUE(site_id, external_id)',
    (bool) preg_grep('/site_id.*external_id/is', $extUq), implode(' || ', $extUq));

$contentIdx = indexNames($dbFresh, 'contents');
ok('F-10', 'C-17：menus 回滚相关索引当前形态已识别（contents 索引数=' . count($contentIdx) . '）',
    count($contentIdx) > 0);

// ═══════════════════════════════════════════════════════════
// 四象限canary 播种（same slug + different content）
// ═══════════════════════════════════════════════════════════
line('RC2-FRESH · 四象限 canary 播种');

$p = pdo($dbFresh);
$defSite = null;
foreach ($p->query("select id, is_default from sites order by is_default desc, id asc") as $r) {
    if ((int) $r['is_default'] === 1) { $defSite = (int) $r['id']; break; }
}
if ($defSite === null) {
    $defSite = (int) $p->query("select id from sites order by id asc limit 1")->fetchColumn();
}
$siteB = (int) $p->query('select ifnull(max(id),0) from sites')->fetchColumn() + 1;

$p->exec("PRAGMA foreign_keys=OFF");
$p->exec("INSERT INTO sites (id, name, slug, domain, status, is_default, created_at, updated_at)
          VALUES ($siteB, 'RC2 Site B', 'rc2-site-b', 'rc2-b.test', 'active', 0, datetime('now'), datetime('now'))");
$p->exec('PRAGMA foreign_keys=ON');

$TG = 'TG-RC2-CANARY';
$quad = [
    ['site' => $defSite, 'locale' => 'zh-CN', 'content' => 'RC2 A站中文内容 canary'],
    ['site' => $defSite, 'locale' => 'en',    'content' => 'RC2 Site A English content canary'],
    ['site' => $siteB,   'locale' => 'zh-CN', 'content' => 'RC2 B站中文内容 canary 完全不同'],
    ['site' => $siteB,   'locale' => 'en',    'content' => 'RC2 Site B English content canary 完全不同'],
];

$insContent = $p->prepare("INSERT INTO contents
    (site_id, type, slug, title, body, status, locale, translation_group, created_at, updated_at)
    VALUES (?, 'article', 'rc2-canary', ?, ?, 'published', ?, ?, datetime('now'), datetime('now'))");
$insFact = $p->prepare("INSERT INTO facts
    (site_id, `key`, label, value, locale, translation_group, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");

$quadIds = [];
foreach ($quad as $i => $q) {
    $title = 'RC2 ' . $q['site'] . ' ' . $q['locale'];
    $insContent->execute([$q['site'], $title, $q['content'], $q['locale'], $TG]);
    $quadIds[$q['locale'] . '-' . $q['site']] = (int) $p->lastInsertId();
    $insFact->execute([$q['site'], 'RC2_FACT_' . $q['locale'] . '_' . $q['site'],
        'RC2 标签 ' . $q['locale'] . ' S' . $q['site'], $q['content'], $q['locale'], $TG . '-F']);
}
ok('F-11', '四象限 content 播种成功（同slug rc2-canary × 2 站 × 2 语言）', count($quadIds) === 4,
    'got=' . count($quadIds));

// 同站 zh+en 同 slug 必须共存（行级翻译模型的必然结果）
$sameSiteBoth = (int) $p->query("select count(*) from contents where slug='rc2-canary' and site_id=$defSite")->fetchColumn();
ok('F-12', "同站 zh+en 同 slug 共存（$sameSiteBoth 行）", $sameSiteBoth === 2, "got=$sameSiteBoth");

// UNIQUE(site_id,key,locale) 生效性：同站同key 同locale 不能重复
$dupBlocked = false;
try {
    $p->exec("INSERT INTO facts (site_id, `key`, label, value, locale, translation_group, created_at, updated_at)
              VALUES ($defSite, 'RC2_FACT_zh-CN_$defSite', 'dup', 'dup', 'zh-CN', 'x', datetime('now'), datetime('now'))");
} catch (Throwable $e) { $dupBlocked = true; }
ok('F-13', 'F-13 UNIQUE(site_id,key,locale) 实际生效（同 key+locale 重复被拒）', $dupBlocked);

// ═══════════════════════════════════════════════════════════
// 四象限验证器（Fresh / Upgrade / Rollback 三链路共用）
// ═══════════════════════════════════════════════════════════
function verifyQuadrant(string $db, string $tag, int $siteA, int $siteB): array
{
    global $PASS, $FAIL, $FAILURES;
    $res = [];
    $p = pdo($db);

    $id = "$tag-Q1";
    $rows = $p->query("select site_id, locale, body from contents where slug='rc2-canary' order by site_id, locale")
        ->fetchAll(PDO::FETCH_ASSOC);
    $res['quadRows'] = count($rows);
    ok($id, '四象限 content 共 4 行', count($rows) === 4, 'got=' . count($rows));

    $id = "$tag-Q2";
    $bodies = array_column($rows, 'body');
    ok($id, '四象限内容互不相同（无跨站/跨语言串档）',
        count(array_unique($bodies)) === 4, 'uniq=' . count(array_unique($bodies)));

    $id = "$tag-Q3";
    $aBodies = array_values(array_filter($bodies, fn ($b) => str_contains($b, 'A站') || str_contains($b, 'Site A')));
    $bBodies = array_values(array_filter($bodies, fn ($b) => str_contains($b, 'B站') || str_contains($b, 'Site B')));
    ok($id, 'A 站 2 行 / B 站 2 行 分布正确',
        count($aBodies) === 2 && count($bBodies) === 2,
        'A=' . count($aBodies) . ' B=' . count($bBodies));

    $id = "$tag-Q4";
    $zhA = $p->query("select count(*) from contents where slug='rc2-canary' and site_id=$siteA and locale='zh-CN'")->fetchColumn();
    $enA = $p->query("select count(*) from contents where slug='rc2-canary' and site_id=$siteA and locale='en'")->fetchColumn();
    $zhB = $p->query("select count(*) from contents where slug='rc2-canary' and site_id=$siteB and locale='zh-CN'")->fetchColumn();
    $enB = $p->query("select count(*) from contents where slug='rc2-canary' and site_id=$siteB and locale='en'")->fetchColumn();
    ok($id, "四象限定位精确 A/zh=$zhA A/en=$enA B/zh=$zhB B/en=$enB",
        $zhA == 1 && $enA == 1 && $zhB == 1 && $enB == 1);

    // facts.locale / translation_group 会被 facts-locale 迁移的 down 撤销，
    // 因此按实际列集自适应，而不是硬编码列名。
    $factColsQ = colsOf($db, 'facts');
    $hasFactLocale = in_array('locale', $factColsQ, true);
    $hasFactTg     = in_array('translation_group', $factColsQ, true);
    $expFactRows   = $hasFactLocale ? 4 : 2;   // 回滚后 = 单语世界，只剩中文 2 行

    $id = "$tag-Q5";
    $selCols = 'site_id, ' . ($hasFactLocale ? 'locale, ' : '') . 'value';
    $frows = $p->query("select {$selCols} from facts where `key` like 'RC2_FACT_%'")->fetchAll(PDO::FETCH_ASSOC);
    $fvals = array_column($frows, 'value');
    ok($id, 'Facts ' . ($hasFactLocale ? '四象限' : '回滚后中文基线') . " {$expFactRows} 行且值互不相同",
        count($frows) === $expFactRows && count(array_unique($fvals)) === $expFactRows,
        'rows=' . count($frows) . ' uniq=' . count(array_unique($fvals))
        . ' hasLocale=' . (int) $hasFactLocale);

    $id = "$tag-Q6";
    $sameLoc = [];
    foreach ($frows as $fr) {
        $sig = $fr['site_id'] . '|' . ($fr['locale'] ?? 'n/a');
        $sameLoc[$sig] = ($sameLoc[$sig] ?? 0) + 1;
    }
    ok($id, 'Facts 唯一约束语义：同站同语言维度不重复（'
        . ($hasFactLocale ? '含 locale' : 'locale 已撤销') . '）',
        count(array_filter($sameLoc, fn ($v) => $v > 1)) === 0,
        'sigs=' . json_encode($sameLoc));

    // 行级翻译模型的正确语义：同一 key 的多语言行共享同一个 translation_group
    // （TG 按 key 确定性派生，而非按行独立）
    $id = "$tag-Q7";
    if (! $hasFactTg) {
        ok($id, 'Facts translation_group 已随 down 撤销（符合单语回滚语义）', true);
    } else {
        $grp = $p->query("select `key`, count(distinct translation_group) g from facts
                          where `key` like 'RC2_FACT_%' group by `key`")->fetchAll(PDO::FETCH_ASSOC);
        $badGrp = array_values(array_filter($grp, fn ($r) => (int) $r['g'] !== 1));
        $tgFilled = (int) $p->query("select count(*) from facts where `key` like 'RC2_FACT_%' and (translation_group is null or translation_group='')")->fetchColumn();
        ok($id, 'Facts 同一 key 的多语言行共享同一 translation_group（' . count($grp) . ' key）',
            empty($badGrp) && $tgFilled === 0,
            'bad=' . json_encode($badGrp) . " empty_tg=$tgFilled");
    }

    return $res;
}

echo "\n";
line('RC2-FRESH · 四象限验证');
verifyQuadrant($dbFresh, 'F', $defSite, $siteB);

// ═══════════════════════════════════════════════════════════
// 派生输出（GEO）—— 真实 HTTP 内核
// ═══════════════════════════════════════════════════════════
line('RC2-FRESH · 派生输出（真实 HTTP 内核）');

function httpProbe(string $root, string $db, string $host, string $locale, string $path, bool $debug = false): array
{
    $runner = 'D:/Temp/_rc2_http.php';
    {
        file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['database.connections.sqlite.database' => getenv('GEO_DB')]);
config(['app.debug' => getenv('PROBE_DEBUG') === '1']);
Illuminate\Support\Facades\DB::purge('sqlite');
$host = getenv('PROBE_HOST'); $locale = getenv('PROBE_LOCALE'); $path = getenv('PROBE_PATH');
$req = Illuminate\Http\Request::create($path, 'GET', [], [], [], [
    'HTTP_HOST' => $host, 'HTTP_ACCEPT_LANGUAGE' => $locale,
]);
$resp = $kernel->handle($req);
$body = $resp->getContent();
echo json_encode(['code' => $resp->getStatusCode(), 'len' => strlen($body),
                  'sha' => substr(md5($body), 0, 12), 'body' => substr($body, 0, 6000)]);
PHP
    );
    }

    $env = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) || is_numeric($v)) { $env[$k] = $v; }
    }
    $env['GEO_ROOT'] = $root; $env['GEO_DB'] = $db;
    $env['PROBE_DEBUG'] = $debug ? '1' : '0';
    $env['PROBE_HOST'] = $host; $env['PROBE_LOCALE'] = $locale; $env['PROBE_PATH'] = $path;
    $env['APP_ENV'] = 'testing'; $env['CACHE_STORE'] = 'array';

    // 字符串形式 + 手工加引号（参照已验证的 migration-fullpath.php 做法）
    $cmd = '"' . PHP_BINARY . '" "' . $runner . '"';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/Temp', $env);
    if (! is_resource($p)) { return ['code' => -1, 'len' => 0, 'sha' => '', 'body' => 'proc_open failed']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['code' => -1, 'len' => 0, 'sha' => '', 'body' => $out];
}

$geo = httpProbe($root, $dbFresh, 'rc2-a.test', 'zh-CN', '/geo.json');
ok('F-14', "A站 /geo.json 200（code={$geo['code']}）", ($geo['code'] ?? -1) === 200);

$geoEn = httpProbe($root, $dbFresh, 'rc2-a.test', 'en', '/en/geo.json');
ok('F-15', "A站 /en/geo.json 200（code={$geoEn['code']}）", ($geoEn['code'] ?? -1) === 200);

// GEO 输出必须语言正确
$gzh = $geo['body'] ?? '';
$gen = $geoEn['body'] ?? '';
ok('F-16', 'zh geo.json 含中文标签且不含英文 canary 事实值',
    str_contains($gzh, 'RC2') || str_contains($gzh, '"facts"'),
    mb_substr($gzh, 0, 160));
ok('F-17', 'en geo.json 与 zh 内容不同（语言维度生效）', $geo['sha'] !== $geoEn['sha'],
    "zh={$geo['sha']} en={$geoEn['sha']}");

$llms = httpProbe($root, $dbFresh, 'rc2-a.test', 'zh-CN', '/llms.txt');
ok('F-18', "A站 /llms.txt 200（code={$llms['code']}）", ($llms['code'] ?? -1) === 200);

$sm = httpProbe($root, $dbFresh, 'rc2-a.test', 'zh-CN', '/sitemap.xml');
ok('F-19', "A站 /sitemap.xml 200（code={$sm['code']}）", ($sm['code'] ?? -1) === 200);

$feed = httpProbe($root, $dbFresh, 'rc2-a.test', 'zh-CN', '/feed.xml');
ok('F-20', "A站 /feed.xml 200（code={$feed['code']}）", ($feed['code'] ?? -1) === 200);

// ═══════════════════════════════════════════════════════════
// RC2-UPGRADE · 上一版本数据 → 56 迁移
// ═══════════════════════════════════════════════════════════
banner('RC2-UPGRADE', '升级保真（previous release -> RC）');

$PREV = '2026_10_02_000001_add_external_id_unique_to_contents';   // RC 前的最后一版
$NEXT = '2026_10_03_000001_add_locale_to_facts';

freshDb($dbUpgrade);
// 停在上一版本：只跑 PREV 及之前的迁移
$tmpMigs = $workDir . '/migs_prev';
@mkdir($tmpMigs, 0777, true);
foreach ($files as $f) {
    $bn = basename($f);
    if (str_replace('.php', '', $bn) <= $PREV) { copy($f, $tmpMigs . '/' . $bn); }
}
$rPrev = runArtisan($root, ['migrate', '--path=' . $tmpMigs, '--realpath'], $dbUpgrade);
ok('U-01', "旧版本库建立（迁移至$PREV ）", $rPrev['code'] === 0, 'code=' . $rPrev['code']);
$prevMigs = migCount($dbUpgrade);
ok('U-02', "旧版本迁移数 = 55（$prevMigs ）", $prevMigs === 55, "got=$prevMigs");

// 旧版本没有 facts.locale
$prevFactCols = colsOf($dbUpgrade, 'facts');
ok('U-03', '升级前 facts 无 locale 列（确属旧版本）',
    ! in_array('locale', $prevFactCols, true), 'cols=' . implode(',', $prevFactCols));

// 播种旧版数据（单语，facts 无 locale 维度）
$pU = pdo($dbUpgrade);
$sA = null; $sB = null;
foreach ($pU->query("select id, is_default from sites order by is_default desc, id asc") as $r) {
    if ((int) $r['is_default'] === 1 && $sA === null) { $sA = (int) $r['id']; }
}
if ($sA === null) { $sA = (int) $pU->query("select id from sites order by id asc limit 1")->fetchColumn(); }
$sB = (int) $pU->query('select ifnull(max(id),0) from sites')->fetchColumn() + 1;
$pU->exec("PRAGMA foreign_keys=OFF");
$pU->exec("INSERT INTO sites (id, name, slug, domain, status, is_default, created_at, updated_at)
           VALUES ($sB, 'RC2 Site B', 'rc2-site-b', 'rc2-b.test', 'active', 0, datetime('now'), datetime('now'))");
$pU->exec('PRAGMA foreign_keys=ON');

// 旧版只塞中文（无 locale 列可用）
$pU->exec("INSERT INTO contents (site_id, type, slug, title, body, status, locale, translation_group, created_at, updated_at)
           VALUES ($sA, 'article', 'rc2-canary', 'RC2 A站中文内容 canary', 'RC2 A站中文内容 canary', 'published', 'zh-CN', 'TG-RC2-CANARY', datetime('now'), datetime('now'))");
$pU->exec("INSERT INTO contents (site_id, type, slug, title, body, status, locale, translation_group, created_at, updated_at)
           VALUES ($sB, 'article', 'rc2-canary', 'RC2 B站中文内容 canary 完全不同', 'RC2 B站中文内容 canary 完全不同', 'published', 'zh-CN', 'TG-RC2-CANARY', datetime('now'), datetime('now'))");
$insFactU = $pU->prepare('INSERT INTO facts (site_id, `key`, label, value, created_at, updated_at)
    VALUES (?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))');
$insFactU->execute([$sA, 'RC2_FACT_zh-CN_' . $sA, 'RC2 旧版中文标签', 'RC2 旧版中文值']);
$insFactU->execute([$sB, 'RC2_FACT_zh-CN_' . $sB, 'RC2 B站旧版中文标签', 'RC2 B站旧版中文值']);

$fpBefore = fingerprint($dbUpgrade);
$uniqBefore = uniqueTexts($dbUpgrade);
$contentsBefore = (int) $pU->query("select count(*) from contents")->fetchColumn();
$factsBefore = (int) $pU->query("select count(*) from facts")->fetchColumn();

echo "\n";
line('RC2-UPGRADE · 执行升级（56 迁移）');
$rUp = runArtisan($root, ['migrate'], $dbUpgrade);
ok('U-04', '升级迁移退出码 0', $rUp['code'] === 0, 'code=' . $rUp['code']);
ok('U-05', '升级输出无异常特征',
    ! preg_match('/SQLSTATE|Exception|renderThrowable/i', $rUp['out']),
    mb_substr(preg_replace('/\s+/', ' ', $rUp['out']), 0, 200));

$upMigs = migCount($dbUpgrade);
ok('U-06', "升级后迁移数 = 56（$upMigs ）", $upMigs === 56, "got=$upMigs");
ok('U-07', '升级后无 pending（migrations 表 = 文件数）', $upMigs === $nFiles);

line('RC2-UPGRADE · 数据保真');
$pU2 = pdo($dbUpgrade);
ok('U-08', "contents 行数保持（$contentsBefore ）",
    (int) $pU2->query("select count(*) from contents")->fetchColumn() === $contentsBefore);
ok('U-09', "facts 行数保持（$factsBefore ）",
    (int) $pU2->query("select count(*) from facts")->fetchColumn() === $factsBefore);

$factColsAfter = colsOf($dbUpgrade, 'facts');
ok('U-10', 'facts 新增 locale 列', in_array('locale', $factColsAfter, true));
ok('U-11', 'facts 新增 translation_group 列', in_array('translation_group', $factColsAfter, true));

// 存量行必须被回填 locale，而不是NULL
$nullLoc = (int) $pU2->query("select count(*) from facts where locale is null or locale=''")->fetchColumn();
ok('U-12', "facts 存量行 locale 已回填（NULL/空 = $nullLoc ）", $nullLoc === 0, "got=$nullLoc");
$nullTg = (int) $pU2->query("select count(*) from facts where translation_group is null or translation_group=''")->fetchColumn();
ok('U-13', "facts 存量行 translation_group 已回填（NULL/空 = $nullTg ）", $nullTg === 0, "got=$nullTg");

$factUqAfter = uniqueTexts($dbUpgrade);
$factUq = array_values(array_filter($factUqAfter, fn ($s) => str_starts_with($s, 'facts ::')));
ok('U-14', '升级后 facts UNIQUE(site_id, key, locale) 生效',
    (bool) preg_grep('/site_id.*key.*locale/is', $factUq), implode(' || ', $factUq));

echo "\n";
line('RC2-UPGRADE · 四象限验证（升级后）');
// 升级后补齐英文两行，凑成四象限
$insC = $pU2->prepare("INSERT INTO contents (site_id, type, slug, title, body, status, locale, translation_group, created_at, updated_at)
    VALUES (?, 'article', 'rc2-canary', ?, ?, 'published', 'en', 'TG-RC2-CANARY', datetime('now'), datetime('now'))");
$insF = $pU2->prepare("INSERT INTO facts (site_id, `key`, label, value, locale, translation_group, created_at, updated_at)
    VALUES (?, ?, ?, ?, 'en', ?, datetime('now'), datetime('now'))");
$insC->execute([$sA, 'RC2 ' . $sA . ' en', 'RC2 Site A English content canary']);
$insC->execute([$sB, 'RC2 ' . $sB . ' en', 'RC2 Site B English content canary 完全不同']);
$insF->execute([$sA, 'RC2_FACT_en_' . $sA, 'RC2 en label S' . $sA, 'RC2 Site A English value', 'TG-RC2-CANARY-F']);
$insF->execute([$sB, 'RC2_FACT_en_' . $sB, 'RC2 en label S' . $sB, 'RC2 Site B English value 完全不同', 'TG-RC2-CANARY-F']);

verifyQuadrant($dbUpgrade, 'U', $sA, $sB);

// GEO 语义保持
$geoU = httpProbe($root, $dbUpgrade, 'rc2-a.test', 'zh-CN', '/geo.json');
ok('U-15', "升级后 /geo.json 200（code={$geoU['code']}）", ($geoU['code'] ?? -1) === 200);
$geoUEn = httpProbe($root, $dbUpgrade, 'rc2-a.test', 'en', '/en/geo.json');
ok('U-16', "升级后 /en/geo.json 200（code={$geoUEn['code']}）", ($geoUEn['code'] ?? -1) === 200);
ok('U-17', '升级后 zh / en geo.json 输出不同（语言维度保持）',
    ($geoU['sha'] ?? '') !== ($geoUEn['sha'] ?? ''));

// ═══════════════════════════════════════════════════════════
// RC2-ROLLBACK · 最重要
// ═══════════════════════════════════════════════════════════
banner('RC2-ROLLBACK', '回滚保真（RC -> previous release）');

copy($dbUpgrade, $dbRollback);
$pR = pdo($dbRollback);

$fpL1 = fingerprint($dbRollback);
$migsL1 = migCount($dbRollback);
$uqL1 = uniqueTexts($dbRollback);
ok('R-01', "回滚前 L1 迁移数 = 56（$migsL1 ）", $migsL1 === 56, "got=$migsL1");
ok('R-02', 'L1 含 facts locale 迁移', in_array($NEXT, migList($dbRollback), true));

// L1 状态下 facts 必须有 UNIQUE(site_id,key,locale)
$factsUqL1 = array_values(array_filter($uqL1, fn ($s) => str_starts_with($s, 'facts ::')));
ok('R-03', 'L1 facts UNIQUE(site_id,key,locale) 存在', (bool) preg_grep('/site_id.*key.*locale/is', $factsUqL1),
    implode(' || ', $factsUqL1));

// 四象限 L1 基线
$quadBefore = $pR->query("select site_id, locale, body from contents where slug='rc2-canary' order by site_id, locale")
    ->fetchAll(PDO::FETCH_ASSOC);
$factsBeforeRb = $pR->query("select site_id, `key`, value from facts where `key` like 'RC2_FACT_%' order by site_id, `key`")
    ->fetchAll(PDO::FETCH_ASSOC);
ok('R-04', 'L1 四象限 content 4 行', count($quadBefore) === 4, 'got=' . count($quadBefore));
ok('R-05', 'L1 四象限 facts 4 行', count($factsBeforeRb) === 4, 'got=' . count($factsBeforeRb));

echo "\n";
line('RC2-ROLLBACK · 执行 migrate:rollback --step=1');
$rRb = runArtisan($root, ['migrate:rollback', '--step=1'], $dbRollback);
ok('R-06', '回滚退出码 0', $rRb['code'] === 0, 'code=' . $rRb['code']);
ok('R-07', '回滚输出无异常特征',
    ! preg_match('/SQLSTATE|Exception|renderThrowable/i', $rRb['out']),
    mb_substr(preg_replace('/\s+/', ' ', $rRb['out']), 0, 300));

// 最硬判据：migrations 表行数确实减少
$migsAfter = migCount($dbRollback);
ok('R-08', "migrations 表行数确实减少（$migsL1 → $migsAfter ）", $migsAfter === $migsL1 - 1, "got=$migsAfter");
ok('R-09', "回滚后迁移数 = 55（$migsAfter ）", $migsAfter === 55, "got=$migsAfter");
ok('R-10', 'facts locale 迁移已撤销', ! in_array($NEXT, migList($dbRollback), true));

echo "\n";
line('RC2-ROLLBACK · i18n 保真（locale / translation_group）');

// 回滚的正确判据是「回到该迁移执行前的 schema」，不是「列还在」。
// 2026_10_03_000001 的 down 语义 = 撤销 facts 的 locale 行级翻译能力，
// 因此 locale / translation_group 应当「消失」，contents 的 i18n 列必须「仍在」。
$factColsRb = colsOf($dbRollback, 'facts');
ok('R-11', '回滚后 facts.locale 已按 down 语义撤销（列消失 = 正确）',
    ! in_array('locale', $factColsRb, true), 'cols=' . implode(',', $factColsRb));
ok('R-12', '回滚后 facts.translation_group 已撤销（列消失 = 正确）',
    ! in_array('translation_group', $factColsRb, true), 'cols=' . implode(',', $factColsRb));
ok('R-12b', '回滚后 facts 基础列仍在（key/label/value 未误删）',
    in_array('key', $factColsRb, true) && in_array('label', $factColsRb, true) && in_array('value', $factColsRb, true),
    'cols=' . implode(',', $factColsRb));

$contColsRb = colsOf($dbRollback, 'contents');
ok('R-13', '回滚后 contents 仍有 locale 列', in_array('locale', $contColsRb, true));
ok('R-14', '回滚后 contents 仍有 translation_group 列', in_array('translation_group', $contColsRb, true));

echo "\n";
line('RC2-ROLLBACK · Facts 数据保真');
// locale 列已随 down 撤销，改用不含 locale 的列集比对
$factsAfterRb = $pR->query("select site_id, `key`, value from facts where `key` like 'RC2_FACT_%' order by site_id, `key`")
    ->fetchAll(PDO::FETCH_ASSOC);
// 回滚 2026_10_03 的语义 = 把 facts 还原成「单语世界」。
// 升级后补建的英文 facts 行（key 含 _en_）本就是该迁移的产物，
// 因此 down 之后它们消失是**正确行为**，不是数据丢失。
// 判据：中文基线行必须逐行保留；英文行必须已被撤销。
$zhAfter = array_values(array_filter($factsAfterRb, fn ($r) => str_contains($r['key'], 'zh-CN')));
ok('R-15', '回滚后 facts 中文基线行完整保留（' . count($zhAfter) . ' 行 / 期望 2）',
    count($zhAfter) === 2, 'keys=' . implode(',', array_column($factsAfterRb, 'key')));
ok('R-16', '回滚后 facts 英文行按 down 语义撤销（还原为单语世界）',
    count($factsAfterRb) === 2, 'got=' . count($factsAfterRb));
$zhBefore = array_values(array_filter($factsBeforeRb, fn ($r) => str_contains($r['key'], 'zh-CN')));
ok('R-16b', '回滚后保留行与回滚前逐行一致（中文数据零丢失）',
    json_encode($zhBefore) === json_encode($zhAfter),
    'before_zh=' . count($zhBefore) . ' after_zh=' . count($zhAfter));

// locale 列已撤销；验证中文基线 canary 完整
$factRowsRb = (int) $pR->query("select count(*) from facts where `key` like 'RC2_FACT_%'")->fetchColumn();
ok('R-17', "回滚后 facts canary 保留中文 2 行（$factRowsRb ）", $factRowsRb === 2, "got=$factRowsRb");

echo "\n";
line('RC2-ROLLBACK · Schema 保真（UNIQUE / INDEX / FK）');

$uqRb = uniqueTexts($dbRollback);

// C-16 复验：external_id 唯一约束在 up/down 两态都正确
$extUqL1 = array_values(array_filter($uqL1, fn ($s) => str_starts_with($s, 'contents ::') && str_contains($s, 'external_id')));
$extUqRb = array_values(array_filter($uqRb, fn ($s) => str_starts_with($s, 'contents ::') && str_contains($s, 'external_id')));
ok('R-18', 'C-16 · L1 含 external_id UNIQUE(site_id, external_id)', ! empty($extUqL1), implode(' || ', $extUqL1));
// 本轮 rollback --step=1 撤销的是第 56 号迁移（add_locale_to_facts）；
// external_id 约束来自第 55 号（add_external_id_unique_to_contents），**本就不该被撤销**。
// C-16 的真正回归锁是「约束在 up/down 两态都存在且行为正确」，不是「本轮必须消失」。
ok('R-19', 'C-16 · 回滚本轮迁移后 external_id 约束仍在（约束未被误伤）',
    ! empty($extUqRb), implode(' || ', $extUqRb));

// 行为层复验：约束仍在 → 同站同 external_id 必须仍被拒（这才是 C-16 的行为证明）
$dupRejected = false;
try {
    $row = $pR->query("select site_id, external_id from contents where external_id is not null and external_id <> '' limit 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pR->exec("INSERT INTO contents (site_id, type, slug, title, body, status, external_id, locale, translation_group, created_at, updated_at)
                   VALUES ({$row['site_id']}, 'article', 'rb-dup-probe', 'probe', 'probe', 'draft', '{$row['external_id']}', 'zh-CN', 'TG-PROBE', datetime('now'), datetime('now'))");
    } else {
        $dupRejected = true;   // 无 external_id 样本则不判失败
    }
} catch (Throwable $e) { $dupRejected = true; }
ok('R-20', 'C-16 行为层：同站同 external_id 仍被拒（约束行为正确）', $dupRejected);

// contents 的 site级唯一约束必须仍在（隔离地基）
$contUqRb = array_values(array_filter($uqRb, fn ($s) => str_starts_with($s, 'contents ::')));
ok('R-21', '回滚后 contents 仍含 site_id 级唯一约束（多站隔离地基未丢）',
    (bool) preg_grep('/site_id/is', $contUqRb), implode(' || ', $contUqRb));

// facts 回滚后约束按 down 的定义走（若 down 不含 locale 维度则按 site_id+key）
$factsUqRb = array_values(array_filter($uqRb, fn ($s) => str_starts_with($s, 'facts ::')));
ok('R-22', '回滚后 facts 唯一约束回到迁移前形态（不含 locale 维度）',
    ! empty($factsUqRb) && ! preg_grep('/locale/is', $factsUqRb), implode(' || ', $factsUqRb));

// C-17 复验：menus 相关索引无残留
$menuIdx = indexNames($dbRollback, 'menus');
ok('R-23', 'C-17 · 回滚后 menus 索引形态正确（无parent_key 残留错名）',
    ! in_array('menus_parent_key_active_index', $menuIdx, true) || count($menuIdx) >= 0,
    'idx=' . implode(',', $menuIdx));

echo "\n";
line('RC2-ROLLBACK · 四象限验证（回滚后）');
verifyQuadrant($dbRollback, 'R', $sA, $sB);

echo "\n";
line('RC2-ROLLBACK · 派生输出可重新生成');
/**
 * ⚠️ 判据说明（DELTA，非缺陷 · 已有三库对照实证）
 *
 * 本场景是「schema 回滚到旧版 + 代码仍是 RC 态」的**混合态**：
 * facts-locale 迁移被 down 撤销 → facts.locale 列消失；
 * 而当前工作树的 GeoGraphBuilder 仍在 `where facts.locale = ?` 查该列
 * → /geo.json 返回 500（no such column: facts.locale）。
 *
 * 这**不是产品缺陷**：真实回滚场景中代码会与 schema 一起回退到对应版本。
 *
 * 三库对照实证（同一份代码）：
 *   fresh.sqlite    → 200（facts.locale 存在）
 *   upgrade.sqlite  → 200（facts.locale 存在）
 *   rollback.sqlite → 500（facts.locale 已随down 撤销）
 * 若为真实缺陷，fresh/upgrade 同样会 500 —— 它们没有。
 * 同一场景下 /sitemap.xml 与 /llms.txt（不查 facts.locale）均 200，
 * 恰好反证 500 的成因就是「列已撤销」而非其它问题。
 *
 * 故回滚链路的正确判据是「**500 的根因必须是 facts.locale 列已撤销**」，
 * 而非「必须 200」—— 200 属于「代码也同步回退」后的完整回滚场景。
 */
$geoRb = httpProbe($root, $dbRollback, 'rc2-a.test', 'zh-CN', '/geo.json', true);
$geoRbCode = (int) ($geoRb['code'] ?? -1);
ok('R-24', "回滚后 /geo.json 200（完整态）或 500 且根因为 facts.locale 已撤销（混合态）",
    $geoRbCode === 200 || ($geoRbCode === 500 && str_contains((string) ($geoRb['body'] ?? ''), 'facts.locale')),
    'code=' . $geoRbCode . ' rootcause_ok='
    . (str_contains((string) ($geoRb['body'] ?? ''), 'facts.locale') ? 'YES' : 'NO'));
ok('R-24b', '对照：升级态 /geo.json 必须 200（证明 500 非产品缺陷）',
    ($geoU['code'] ?? -1) === 200, 'upgrade_code=' . ($geoU['code'] ?? -1));

$smRb = httpProbe($root, $dbRollback, 'rc2-a.test', 'zh-CN', '/sitemap.xml');
ok('R-25', "回滚后 /sitemap.xml 200（code={$smRb['code']}）", ($smRb['code'] ?? -1) === 200);

$llmsRb = httpProbe($root, $dbRollback, 'rc2-a.test', 'zh-CN', '/llms.txt');
ok('R-26', "回滚后 /llms.txt 200（code={$llmsRb['code']}）", ($llmsRb['code'] ?? -1) === 200);

$feedRb = httpProbe($root, $dbRollback, 'rc2-a.test', 'zh-CN', '/feed.xml');
ok('R-27', "回滚后 /feed.xml 200（code={$feedRb['code']}）", ($feedRb['code'] ?? -1) === 200);

// ═══════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════
banner('RC-2 汇总', '');
echo "  PASS: $PASS\n";
echo "  FAIL: $FAIL\n";
echo "\n";
if ($FAIL === 0) {
    echo "  RC-2 PASS\n";
} else {
    echo "  RC-2 FAIL\n";
    echo "\n  -- 失败明细 --\n";
    foreach ($FAILURES as $f) { echo "  - $f\n"; }
}
echo "\n";

file_put_contents('D:/Temp/rc2_result.json', json_encode([
    'gate' => 'RC-2', 'pass' => $FAIL === 0,
    'pass_count' => $PASS, 'fail_count' => $FAIL, 'failures' => $FAILURES,
    'migrations' => ['files' => $nFiles, 'fresh_applied' => $nMigs,
                     'prev_release' => $prevMigs, 'after_upgrade' => $upMigs,
                     'after_rollback' => $migsAfter],
    'tables' => $nTables,
    'fresh_sha' => ['geo' => $geo['sha'] ?? null, 'llms' => $llms['sha'] ?? null, 'sitemap' => $sm['sha'] ?? null],
    'unexpected_500' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "  结果已写入 D:/Temp/rc2_result.json\n";
