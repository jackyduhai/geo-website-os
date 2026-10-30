<?php
/**
 * 20G-8-B · 55 migrations 全链路验证（Fresh / Upgrade / Rollback 全量）
 * ------------------------------------------------------------------
 * 20G-8-C 只验证了「最后一步的 rollback」，本脚本补齐全链路：
 *
 *   B1Fresh      0 → 55 独立库跑满，断言 i18n schema/约束/表数/索引齐全
 *   B2  Idempotent  重复 migrate 幂等（第二次无新增、schema 不变）
 *   B3  Upgrade   旧库（停在 N-1）→ 升到 55，断言零丢失 + i18n 保持
 *   B4  Rollback  55 → 0 全量回滚，断言每步都成功（回滚链完整可用）
 *   B5  Re-up     全量回滚后再跑满，断言 schema 与首次 fresh 完全一致
 *                 （证明「回滚→重装」不产生漂移）
 *
 * 用法：GEO_ROOT=<repo> php migration-fullpath.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
$workDir = 'D:/Temp/g8b_fullpath';
$migrationSrc = $root.'/database/migrations';

$PASS = 0; $FAIL = 0; $FAILURES = [];

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

function fail(string $id, string $desc, string $detail): void
{
    ok($id, $desc, false, $detail);
}

/** 独立进程执行 artisan（建库一律走 CLI，脚本只验证） */
function runArtisan(string $root, array $args, string $db): array
{
    static $runner = null;
    if ($runner === null) {
        $runner = 'D:/Temp/_g8b_runner.php';
        if (! file_exists($runner)) {
            file_put_contents($runner, <<<'PHP'
<?php
$root = getenv('GEO_ROOT');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArgvInput(
    array_merge(['artisan'], array_slice($_SERVER['argv'], 1))
);
exit($kernel->handle($input));
PHP);
        }
    }

    $env = [
        'GEO_ROOT'         => $root,
        'APP_ENV'          => 'testing',
        'APP_KEY'          => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION'    => 'sqlite',
        'DB_DATABASE'      => $db,
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'BCRYPT_ROUNDS'    => '4',
        'MAIL_MAILER'      => 'array',
        'SystemRoot'       => getenv('SystemRoot') ?: 'C:\\Windows',
        'TEMP'             => getenv('TEMP') ?: 'D:\\Temp',
        'TMP'              => getenv('TMP') ?: 'D:\\Temp',
    ];

    $parts = [escapeshellarg(PHP_BINARY), '-d', 'memory_limit=1G', escapeshellarg($runner)];
    foreach ($args as $a) {
        $parts[] = escapeshellarg($a);
    }

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(implode(' ', $parts), $desc, $pipes, $root, $env);
    if (! is_resource($proc)) {
        return ['code' => -1, 'out' => 'proc_open 失败'];
    }
    $out = stream_get_contents($pipes[1])."\n".stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($proc), 'out' => $out];
}

function newDb(string $p): string
{
    if (file_exists($p)) {
        @unlink($p);
    }
    touch($p);

    return $p;
}

/** 把前 N 个迁移复制到独立目录（用于精确 migrate 到 N） */
function stage(string $migrationSrc, int $count, string $stageDir): string
{
    if (is_dir($stageDir)) {
        foreach (glob($stageDir.'/*.php') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        mkdir($stageDir, 0777, true);
    }
    $files = glob($migrationSrc.'/*.php') ?: [];
    sort($files);
    foreach (array_slice($files, 0, $count) as $f) {
        copy($f, $stageDir.'/'.basename($f));
    }

    return $stageDir;
}

/** schema 快照（PDO 直连，不经 Laravel） */
function snap(string $db): array
{
    $pdo = new PDO('sqlite:'.$db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $tb = $r['name'];
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info({$tb})") as $c) {
            $cols[strtolower((string) $c['name'])] = strtolower((string) $c['type'])
                .($c['notnull'] ? ' NN' : '')
                .($c['pk'] ? ' PK' : '');
        }
        $idx = [];
        foreach ($pdo->query("PRAGMA index_list({$tb})") as $i) {
            $ic = [];
            foreach ($pdo->query("PRAGMA index_info(".$pdo->quote($i['name']).")") as $c) {
                $ic[] = strtolower((string) $c['name']);
            }
            $k = str_starts_with((string) $i['name'], 'sqlite_autoindex')
                ? 'AUTO:'.implode(',', $ic) : (string) $i['name'];
            $idx[$k] = ((int) $i['unique'] === 1 ? 'U' : 'I').':'.implode(',', $ic);
        }
        ksort($idx);
        $t[$tb] = ['cols' => $cols, 'idx' => $idx];
    }
    ksort($t);

    return $t;
}

function countMigrations(string $db): int
{
    $pdo = new PDO('sqlite:'.$db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");
    if (! $st->fetch()) {
        return 0;
    }
    $row = $pdo->query('SELECT COUNT(*) AS c FROM migrations')->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['c'] ?? 0);
}

function appliedHead(string $db): string
{
    $pdo = new PDO('sqlite:'.$db);

    return (string) ($pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetch()['migration'] ?? '');
}

// ---------------------------------------------------------------- 准备
if (! is_dir($workDir)) {
    mkdir($workDir, 0777, true);
}
$all = glob($migrationSrc.'/*.php') ?: [];
sort($all);
$TOTAL = count($all);
$HEAD = basename((string) end($all), '.php');

echo "== 基线 ==\n";
echo 'ROOT      : '.$root."\n";
echo 'GIT_HEAD  : '.trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD'))."\n";
echo "MIGRATIONS: {$TOTAL}\n";
echo "HEAD      : {$HEAD}\n\n";

/** i18n 资产（来自 i18n-schema-inventory.php 实测） */
const I18N_TABLES = [
    'contents', 'entities', 'seo_metas', 'pages',
    'form_fields', 'form_submissions', 'search_documents',
];
const I18N_COLS = ['locale', 'translation_group'];

// ================================================================ B1 Fresh
echo "========================================\n";
echo "== B1  Fresh: 0 → {$TOTAL}\n";
echo "========================================\n";

$dbFresh = newDb($workDir.'/fresh.sqlite');
$r = runArtisan($root, ['migrate', '--force'], $dbFresh);
ok('B1.migrate', "全量 migrate 0 → {$TOTAL}", $r['code'] === 0, substr($r['out'], -500));
ok('B1.count', "迁移记录数 = {$TOTAL}", countMigrations($dbFresh) === $TOTAL,
    '实际 '.countMigrations($dbFresh));
ok('B1.head', "HEAD = {$HEAD}", appliedHead($dbFresh) === $HEAD, '实际 '.appliedHead($dbFresh));

$snapFresh = snap($dbFresh);
ok('B1.tables', '表数量 > 25', count($snapFresh) > 25, '实际 '.count($snapFresh));

foreach (I18N_TABLES as $t) {
    if (! isset($snapFresh[$t])) {
        fail("B1.i18n.{$t}", "表 {$t} 存在", '表不存在');

        continue;
    }
    $hasLocale = isset($snapFresh[$t]['cols']['locale']);
    ok("B1.locale.{$t}", "{$t}.locale 存在", $hasLocale);
    // translation_group 只在有行级翻译的表上
    if (in_array($t, ['contents', 'entities', 'pages'], true)) {
        ok("B1.tg.{$t}", "{$t}.translation_group 存在", isset($snapFresh[$t]['cols']['translation_group']));
    }
}

// 关键唯一约束
$pdoF = new PDO('sqlite:'.$dbFresh);
$uniqContents = [];
foreach ($pdoF->query("PRAGMA index_list(contents)") as $i) {
    if ((int) $i['unique'] === 1) {
        $ic = [];
        foreach ($pdoF->query("PRAGMA index_info(".$pdoF->quote($i['name']).")") as $c) {
            $ic[] = (string) $c['name'];
        }
        $uniqContents[] = implode(',', $ic);
    }
}
ok('B1.unique.contents', 'contents 唯一约束含 (site_id,slug,locale)',
    in_array('site_id,slug,locale', $uniqContents, true), '实际: '.implode(' | ', $uniqContents));
ok('B1.unique.contents.external', 'contents 唯一约束含 (site_id,external_id)',
    in_array('site_id,external_id', $uniqContents, true), '实际: '.implode(' | ', $uniqContents));
$fkRows = $pdoF->query('PRAGMA foreign_key_list(contents)')->fetchAll(PDO::FETCH_ASSOC);
ok('B1.fk.contents', 'contents.site_id 外键存在',
    in_array('site_id', array_column($fkRows, 'from'), true), json_encode($fkRows));
unset($pdoF);

// ================================================================ B2 幂等
echo "\n========================================\n";
echo "== B2  幂等：重复 migrate\n";
echo "========================================\n";

$before2 = snap($dbFresh);
$r = runArtisan($root, ['migrate', '--force'], $dbFresh);
ok('B2.rerun', '重复 migrate 不报错', $r['code'] === 0, substr($r['out'], -300));
ok('B2.count-unchanged', '迁移记录数不变', countMigrations($dbFresh) === $TOTAL,
    '实际 '.countMigrations($dbFresh));
$after2 = snap($dbFresh);
ok('B2.schema-unchanged', '重复 migrate 后 schema 完全一致',
    json_encode($before2) === json_encode($after2));

// ================================================================ B3 Upgrade
echo "\n========================================\n";
echo "== B3  Upgrade: 停在 N-1 → 升到 {$TOTAL}\n";
echo "========================================\n";

// 停在倒数第 2 个迁移
$stageN1 = stage($migrationSrc, $TOTAL - 1, $workDir.'/_stage_n1');
$dbUp = newDb($workDir.'/upgrade.sqlite');
$r = runArtisan($root, ['migrate', '--force', '--path='.$stageN1, '--realpath'], $dbUp);
ok('B3.seed', '旧库建到 '.($TOTAL - 1).' 个迁移', $r['code'] === 0, substr($r['out'], -400));
ok('B3.seed-count', '旧库迁移数 = '.($TOTAL - 1), countMigrations($dbUp) === $TOTAL - 1,
    '实际 '.countMigrations($dbUp));

// 播种双语数据（若表已存在 locale 列）
$snapUp0 = snap($dbUp);
$seeded = 0;
if (isset($snapUp0['contents']['cols']['locale'])) {
    $pdoU = new PDO('sqlite:'.$dbUp);
    $pdoU->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sites = $pdoU->query('SELECT id FROM sites ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $siteA = (int) $sites[0];
    $pdoU->exec("INSERT INTO sites (name,slug,domain,status,is_default,created_at,updated_at)
                VALUES ('B','site-b','b.t','active',0,'2026-01-01','2026-01-01')");
    $siteB = (int) $pdoU->lastInsertId();
    foreach ([[$siteA, 'zh-CN', 'TG-U1'], [$siteA, 'en', 'TG-U1'],
              [$siteB, 'zh-CN', 'TG-U2'], [$siteB, 'en', 'TG-U2']] as [$sid, $loc, $tg]) {
        $pdoU->prepare('INSERT INTO contents (site_id,type,title,slug,status,locale,translation_group,content_hash,created_at,updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$sid, 'article', "U-{$sid}-{$loc}", 'up-slug', 'published', $loc, $tg,
                       'UH-'.$sid.'-'.$loc, '2026-01-01', '2026-01-01']);
        $seeded++;
    }
    unset($pdoU);
}
ok('B3.seed', "旧库播种 {$seeded} 行双语数据", $seeded === 4, "实际 {$seeded}");
$snapUpBefore = snap($dbUp);

// 升级到全量
$r = runArtisan($root, ['migrate', '--force'], $dbUp);
ok('B3.upgrade', "Upgrade → {$TOTAL} 成功", $r['code'] === 0, substr($r['out'], -500));
ok('B3.count', "升级后迁移数 = {$TOTAL}", countMigrations($dbUp) === $TOTAL,
    '实际 '.countMigrations($dbUp));
$snapUpAfter = snap($dbUp);

// 表零丢失
$lostTables = array_diff(array_keys($snapUpBefore), array_keys($snapUpAfter));
ok('B3.no-table-loss', '升级过程无表丢失', $lostTables === [],
    '丢失: '.implode(', ', $lostTables));

// i18n 列零丢失
foreach (I18N_TABLES as $t) {
    if (! isset($snapUpBefore[$t])) {
        continue;
    }
    foreach (I18N_COLS as $c) {
        $had = isset($snapUpBefore[$t]['cols'][$c]);
        $has = isset($snapUpAfter[$t]) && isset($snapUpAfter[$t]['cols'][$c]);
        if ($had) {
            ok("B3.i18n.{$t}.{$c}", "升级后 {$t}.{$c} 保持", $has);
        }
    }
}

// 数据零丢失
$pdoU2 = new PDO('sqlite:'.$dbUp);
$pdoU2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rowsAfter = $pdoU2->query("SELECT site_id,slug,locale,translation_group,content_hash
                           FROM contents WHERE translation_group IN ('TG-U1','TG-U2')
                           ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
ok('B3.data-preserved', "升级后双语数据 {$seeded} 行全部保留",
    count($rowsAfter) === $seeded && $seeded === 4,
    '实际 '.count($rowsAfter).' 行: '.json_encode($rowsAfter, JSON_UNESCAPED_UNICODE));
$distinct = array_unique(array_column($rowsAfter, 'content_hash'));
ok('B3.data-distinct', '四象限数据互不相同', count($distinct) === count($rowsAfter) && $rowsAfter !== [],
    json_encode(array_values($distinct)));
unset($pdoU2);

// ================================================================ B4 全量回滚
echo "\n========================================\n";
echo "== B4  Rollback: {$TOTAL} → 0（全量回滚链）\n";
echo "========================================\n";

$dbRb = newDb($workDir.'/rollback.sqlite');
runArtisan($root, ['migrate', '--force'], $dbRb);
ok('B4.seed', "回滚测试库建到 {$TOTAL}", countMigrations($dbRb) === $TOTAL);

/**
 * ⚠️ 回滚成功判据**不能只看进程退出码**（20G-8-B 实测）：
 * Laravel 的异常处理器会把迁移里的 `dropUnique(['col'])` 之类错误
 * 渲染成 HTML 错误页输出到 stdout，而**进程退出码仍是 0**。
 * 于是「code === 0」为真、实际已回滚失败 → 回滚链表面完整、实际残留索引。
 *
 * 必须同时判定：
 *   ① 退出码为 0
 *   ② 输出里没有异常特征（DONE / INFO / Nothing to rollback）
 *   ③ **migrations 表行数确实减少了 1**（最硬的判据）
 */
function rollbackSucceeded(string $out, int $code, int $before, int $after): bool
{
    if ($code !== 0) {
        return false;
    }
    if (str_contains($out, 'Rolling back') && ! str_contains($out, 'DONE')
        && ! str_contains($out, 'Nothing to rollback')) {
        return false;
    }
    // 异常特征：HTML 错误页 / 常见异常类型名
    foreach (['SQLSTATE', 'Exception', 'ErrorException', 'Illuminate\\', '<title>', 'Whoops'] as $sig) {
        if (stripos($out, $sig) !== false) {
            return false;
        }
    }

    return $after < $before;
}

$step = 0;
$fails = [];
while (countMigrations($dbRb) > 0) {
    $step++;
    if ($step > $TOTAL + 8) {
        $fails[] = "超过 ".($TOTAL + 8)." 步仍未回滚完（共 ".countMigrations($dbRb)." 条），存在不可回滚迁移";
        break;
    }
    $before = countMigrations($dbRb);
    $r = runArtisan($root, ['migrate:rollback', '--force', '--step=1'], $dbRb);
    $after = countMigrations($dbRb);

    if (! rollbackSucceeded($r['out'], $r['code'], $before, $after)) {
        // 定位是哪个迁移卡住
        $pdo = new PDO('sqlite:'.$dbRb);
        $pending = $pdo->query('SELECT migration FROM migrations ORDER BY batch DESC, id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        unset($pdo);
        $fails[] = "回滚卡在 ".($pending['migration'] ?? '?')
            ."（第 {$step} 步，{$before} → {$after}，code={$r['code']}）: "
            .trim(preg_replace('/\s+/', ' ', substr($r['out'], -260)));
        break;
    }
}
ok('B4.chain', "全量回滚链完整（{$step} 步）", $fails === [], implode('; ', $fails));
ok('B4.to-zero', "回滚到 0 迁移", countMigrations($dbRb) === 0, '实际 '.countMigrations($dbRb));

$snapRb = snap($dbRb);
$leftTables = array_diff(array_keys($snapFresh), array_keys($snapRb));
echo "  [INFO] 回滚后残留表: ".(implode(', ', $leftTables) ?: '无')."\n";

// ================================================================ B5 重装一致性
echo "\n========================================\n";
echo "== B5  重装：回滚后重新跑满，schema 应与首次 fresh 一致\n";
echo "========================================\n";

$r = runArtisan($root, ['migrate', '--force'], $dbRb);
ok('B5.reup', "回滚后重新 migrate 到 {$TOTAL}", $r['code'] === 0, substr($r['out'], -400));
ok('B5.count', "重装后迁移数 = {$TOTAL}", countMigrations($dbRb) === $TOTAL,
    '实际 '.countMigrations($dbRb));

$snapReup = snap($dbRb);
$drift = [];
foreach ($snapFresh as $t => $meta) {
    if (! isset($snapReup[$t])) {
        $drift[] = "{$t}: 表在重装后消失";

        continue;
    }
    if (json_encode($meta['cols']) !== json_encode($snapReup[$t]['cols'])) {
        $drift[] = "{$t}: 列定义漂移";
    }
    if (json_encode($meta['idx']) !== json_encode($snapReup[$t]['idx'])) {
        $drift[] = "{$t}: 索引漂移";
    }
}
ok('B5.no-drift', '回滚→重装 无 schema 漂移（回滚可逆）', $drift === [],
    implode('; ', array_slice($drift, 0, 5)));

// ================================================================ 汇总
echo "\n========================================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo "  - {$f}\n";
    }
}
echo "========================================\n";
exit($FAIL === 0 ? 0 : 1);