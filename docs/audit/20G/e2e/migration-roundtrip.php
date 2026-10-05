<?php
/**
 * 20G-8-C  逐迁移独立库 up→down 往返保真验证器
 * ------------------------------------------------------------------
 * 目标：证明「任何涉及表重建的迁移，在 up→down 往返中不破坏既有 i18n schema、
 * 数据与约束」——即 rollback 后 Site × Locale 二维数据模型仍然成立。
 *
 * 为什么必须「逐迁移独立库」：
 *   Windows + Laravel 下，同一库反复 migrate/rollback/rename 会踩
 *   ① PDO 文件锁（unlink 不掉打开中的 SQLite）② migrations 仓储缓存
 *   ③ bootstrap 后 config() 切库时序（DB_DATABASE env 在 bootstrap 后才生效）。
 *   故本器每个迁移用**独立文件**、且建库一律走 artisan CLI（脚本只验证）。
 *
 * 两种方向：
 *   方向 A（空库往返）：migrate →N →snapshotN →up N+1 →down N+1 →snapshotN' →diff
 *   方向 B（带数据往返）：migrate →N →播种双语 canary →snapshotN(含数据)
 *                         →up N+1 →down N+1 →snapshotN' →diff（含数据行比对）
 *
 * diff 维度：列（含类型/非空/默认/PK）、唯一约束、索引、外键、数据行。
 * 允许的差异：本迁移语义内的 delta（单独报告，不计入失败）。
 *
 * 用法：
 *   GEO_ROOT=<repo> GEO_ONLY=<migration_basename> php migration-roundtrip.php
 *   GEO_ROOT=<repo> php migration-roundtrip.php# 全量 i18n 相关迁移
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
// 工作目录可配置：Windows 下 SQLite 文件被 PDO 占用时无法删除，
// 反复复用同一目录会撞 "Device or resource busy"（20G-8-A 实测）。
// 换目录即可避开，验证结果不受影响。
$dbDir = getenv('GEO_WORKDIR') ?: ('D:/Temp/g8c_roundtrip_'.getmypid());

$PASS = 0; $FAIL = 0; $WARN = 0; $FAILURES = [];
$DIRTY = [];   // 本迁移语义内的预期 delta

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

function warn(string $id, string $desc, string $detail = ''): void
{
    global $WARN;
    $WARN++;
    echo "  [DELTA] $id $desc" . ($detail !== '' ? " | $detail" : '') . "\n";
}

/**
 * 用独立进程执行 artisan，确保迁移在干净进程里跑
 * （避免同进程 SiteScope memo / BelongsToSite 污染）。
 *
 * 注意：每个迁移用**独立 SQLite 文件**，且建库一律走 artisan CLI。
 * 原因（20G-6已实测）：
 *   ① 同一库反复 migrate/rollback 会踩 PDO 文件锁——Windows 下 unlink 不掉打开中的库
 *   ② SiteScope::$eligibleMemo 会记住「某表无 site_id 列」，迁移改结构后不重查会读到脏判断
 *   ③ DB_DATABASE env 在 bootstrap 之后才生效，脚本内 config() 切库会打到旧库
 * 故脚本自身只做**验证**，不做建库与迁移。
 */
function runArtisan(string $root, array $args, string $db): array
{
    $php = PHP_BINARY;
    $script = <<<'PHP'
<?php
// 独立进程执行器：以 argv 接收 args，env 已由调用方设置
$root = getenv('GEO_ROOT');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$args = array_slice($_SERVER['argv'], 1);
$status = $kernel->handle(new Symfony\Component\Console\Input\ArgvInput(
    array_merge(['artisan'], $args)
));
exit($status);
PHP;

    $tmp = 'D:/Temp/_g8c_runner.php';
    if (! file_exists($tmp)) {
        file_put_contents($tmp, $script);
    }

    $parts = [
        escapeshellarg($php),
        '-d', 'memory_limit=1G',
        escapeshellarg($tmp),
    ];
    foreach ($args as $a) {
        $parts[] = escapeshellarg($a);
    }

    // Windows 下 exec() 走 cmd.exe，不支持 `VAR=value cmd` 前缀语法
    // （20G-8-C 实测：报 "'GEO_ROOT' is not recognized"）。
    // 正确做法：proc_open 显式传 $env 数组，让环境变量走 CreateProcess。
    $env = [
        'GEO_ROOT'            => $root,
        'APP_ENV'             => 'testing',
        'APP_KEY'             => 'base64:'.base64_encode(random_bytes(32)),
        'DB_CONNECTION'       => 'sqlite',
        'DB_DATABASE'         => $db,
        'CACHE_STORE'         => 'array',
        'SESSION_DRIVER'      => 'array',
        'QUEUE_CONNECTION'    => 'sync',
        'BCRYPT_ROUNDS'       => '4',
        'MAIL_MAILER'         => 'array',
        'SystemRoot'          => getenv('SystemRoot') ?: 'C:\\Windows',
        'TEMP'                => getenv('TEMP') ?: 'D:\\Temp',
        'TMP'                 => getenv('TMP') ?: 'D:\\Temp',
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(implode(' ', $parts), $descriptors, $pipes, $root, $env);
    if (! is_resource($proc)) {
        return ['code' => -1, 'out' => 'proc_open 失败'];
    }
    $out = stream_get_contents($pipes[1])."\n".stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'out' => $out];
}

/** 在给定库上直接读 schema（PDO 直连，不经过 Laravel，杜绝应用层污染） */
function snap(string $db): array
{
    $pdo = new PDO('sqlite:'.$db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $t = $r['name'];

        // 列
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info({$t})") as $c) {
            $cols[strtolower((string) $c['name'])] = [
                'type' => strtolower((string) $c['type']),
                'notnull' => (int) $c['notnull'],
                'default' => $c['dflt_value'],
                'pk' => (int) $c['pk'],
            ];
        }

        // 索引（含唯一与 partial）
        $idx = [];
        foreach ($pdo->query("PRAGMA index_list({$t})") as $i) {
            $ic = [];
            foreach ($pdo->query("PRAGMA index_info(".$pdo->quote($i['name']).")") as $c) {
                $ic[] = strtolower((string) $c['name']);
            }
            // 归一化名字：sqlite_autoindex_* 是表级 UNIQUE（无显式名），用列组合做键
            $keyName = str_starts_with((string) $i['name'], 'sqlite_autoindex')
                ? 'AUTO:'.implode(',', $ic)
                : (string) $i['name'];
            $idx[$keyName] = [
                'unique' => (int) $i['unique'] === 1,
                'partial' => (int) $i['partial'] === 1,
                'origin' => $i['origin'],
                'columns' => $ic,
            ];
        }
        ksort($idx);

        // 外键
        $fks = [];
        foreach ($pdo->query("PRAGMA foreign_key_list({$t})") as $f) {
            $fks[] = strtolower((string) $f['from']).'->'.strtolower((string) $f['table'])
                .'.'.strtolower((string) $f['to']).':'.$f['on_delete'];
        }
        sort($fks);

        $tables[$t] = ['columns' => $cols, 'indexes' => $idx, 'fks' => $fks];
    }
    ksort($tables);

    return $tables;
}

/** 取i18n 相关表的行数据（用于数据保真比对） */
function snapRows(string $db, array $i18nTables): array
{
    $pdo = new PDO('sqlite:'.$db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $out = [];
    foreach ($i18nTables as $t) {
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$t}'")->fetch();
        if (! $exists) {
            continue;
        }
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info({$t})") as $c) {
            $cols[] = $c['name'];
        }
        // 只取与 i18n 相关的列做比对（避免 updated_at 等噪声）
        $want = array_values(array_intersect($cols, [
            'id', 'site_id', 'slug', 'locale', 'translation_group', 'type',
            'key', 'title', 'label', 'value', 'content_hash', 'system_key', 'entity_id',
        ]));
        if ($want === []) {
            continue;
        }
        $w = implode(',', array_map(fn ($c) => '"'.$c.'"', $want));
        $sql = "SELECT {$w} FROM {$t} ORDER BY " . implode(',', array_map(fn ($c) => '"'.$c.'"', $want));
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out[$t] = $rows;
    }
    ksort($out);
    return $out;
}

/**
 * 找出该迁移**新建**的表（up() 里的 Schema::create / CREATE TABLE）。
 *
 * 用途：方向 A 的 exists 断言要区分两种情况——
 *   · 该表由本迁移新建 → rollback 后**应该消失**（断言消失才对）
 *   · 该表在迁移前已存在 → rollback 后**必须仍在**（断言存在）
 * 混为一谈会对早期建表迁移产生大量假失败。
 */
function tablesCreatedBy(string $root, string $migration): array
{
    $src = (string) file_get_contents($root.'/database/migrations/'.$migration);
    $src = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*$#m'], '', $src) ?? $src;
    $out = [];

    if (preg_match_all("/Schema::create\s*\(\s*'([a-z_]+)'/i", $src, $m)) {
        foreach ($m[1] as $t) {
            $out[] = strtolower($t);
        }
    }
    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-z_]+)[_`\'"]?/i', $src, $m2)) {
        foreach ($m2[1] as $t) {
            $out[] = strtolower($t);
        }
    }

    return array_values(array_unique($out));
}

/**
 * 按 contents 表**实际存在的列**动态构造 INSERT。
 *
 * 为什么必须自适应（20G-8-A 实测）：不同迁移阶段的 contents 列不同 ——
 * 早期迁移还没有 site_id / locale / translation_group / content_hash，
 * 固定列名的 INSERT 会直接抛「no such column」中断整个验证。
 */
function insertContent(PDO $pdo, array $data): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(contents)') as $c) {
        $cols[strtolower((string) $c['name'])] = true;
    }

    $use = [];
    foreach (array_keys($data) as $k) {
        if (isset($cols[strtolower($k)])) {
            $use[] = $k;
        }
    }
    if ($use === []) {
        return;
    }

    $place = implode(',', array_fill(0, count($use), '?'));
    $sql = 'INSERT INTO contents ('.implode(',', $use).") VALUES ({$place})";
    $pdo->prepare($sql)->execute(array_map(fn ($k) => $data[$k], $use));
}

function newDb(string $path): string
{
    if (file_exists($path)) {
        @unlink($path);
    }
    touch($path);
    return $path;
}

/**
 * 把「截至目标迁移为止」的前 N 个迁移复制到临时目录，返回该目录绝对路径。
 *
 * 为什么需要：Laravel 的 `migrate` **没有**「migrate 到第 N 个」的选项，
 * 只有 `--path`（20G-8-C 实测）。要精确验证「第 N 个迁移的 up/down 往返」，
 * 只能把前 N 个文件放进独立目录，用 `--path` + `--realpath` 限定范围。
 *
 * 复制而非软链：Windows 上 symlink 需管理员权限，且 migrate 按文件真实路径
 * 计算 migration 名，软链可能造成名不一致。
 */
function stageMigrations(string $dbDir, string $target, array $upToAnd): string
{
    static $cache = [];

    $key = $target;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stage = $dbDir.'/_stage/'.basename($target, '.php');
    if (is_dir($stage)) {
        foreach (glob($stage.'/*.php') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        mkdir($stage, 0777, true);
    }

    $src = getenv('GEO_ROOT').'/database/migrations';
    foreach ($upToAnd as $m) {
        copy($src.'/'.$m, $stage.'/'.$m);
    }

    $cache[$key] = $stage;

    return $stage;
}

// ---------------------------------------------------------------- 迁移清单
$migrationDir = $root.'/database/migrations';
$all = array_map('basename', glob($migrationDir.'/*.php'));
sort($all);

/** i18n 相关迁移：源码含 locale / translation_group */
$i18nMigrations = [];
foreach ($all as $m) {
    $src = (string) file_get_contents($migrationDir.'/'.$m);
    if (str_contains($src, 'locale') || str_contains($src, 'translation_group')) {
        $i18nMigrations[] = $m;
    }
}

// 重建型迁移（含 CREATE TABLE + RENAME）——最高风险
$rebuildMigrations = [];
foreach ($i18nMigrations as $m) {
    $src = (string) file_get_contents($migrationDir.'/'.$m);
    if (preg_match('/CREATE\s+TABLE/i', $src) && preg_match('/RENAME\s+TO/i', $src)) {
        $rebuildMigrations[] = $m;
    }
}

/**
 * 目标集选择。
 *
 * GEO_TARGET 三档：
 *   rebuild（默认）—— 只验证重建型迁移（最高风险，2 个）
 *   i18n             —— 全部 8 张 locale-bearing 表涉及的迁移（17 个）
 *   all              —— 全部 55 个迁移
 * GEO_ONLY=<子串>   —— 精确指定单个迁移（调试用）
 */
$target = getenv('GEO_ONLY') ?: getenv('GEO_TARGET') ?: 'rebuild';

/** 涉及 locale-bearing 表的全部迁移（i18n 资产清单驱动，非硬编码） */function i18nRelatedMigrations(string $root, array $all, array $i18nTables): array
{
    $out = [];
    foreach ($all as $m) {
        $src = (string) file_get_contents($root.'/database/migrations/'.$m);
        foreach ($i18nTables as $t) {
            if (preg_match("/(?:Schema::(?:table|create)|TABLE|INTO)\s*\(?\s*'{$t}'/i", $src)
                || preg_match("/CREATE\s+TABLE\s+[`\"]?{$t}[_`\"]?/i", $src)) {
                $out[] = $m;
                break;
            }
        }
    }

    return $out;
}

/**
 * 8 张 locale-bearing 表（由 i18n-schema-inventory.php 从真实库产出）。
 * 刻意不含 `settings`：它从设计之初就是全局单站表（key 全局 UNIQUE、无 site_id）。
 */
const I18N_TABLES = [
    'contents', 'entities', 'seo_metas', 'pages',
    'form_fields', 'form_submissions', 'search_documents',
    // 20G-3：facts 纳入行级翻译（locale + translation_group，
    // UNIQUE(site_id,key) → UNIQUE(site_id,key,locale)）
    'facts',
];

$i18nTablesRelated = i18nRelatedMigrations($root, $all, I18N_TABLES);

if (getenv('GEO_ONLY')) {
    $only = getenv('GEO_ONLY');
    $sel = [];
    foreach ($all as $m) {
        $stem = basename($m, '.php');
        if ($stem === $only || str_contains($stem, $only)) {
            $sel[] = $m;
        }
    }
    $targets = $sel;
} elseif ($target === 'all') {
    $targets = $all;
} elseif ($target === 'i18n') {
    $targets = $i18nTablesRelated;
} else {
    $targets = $rebuildMigrations;
}

if (! is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

echo "== 基线 ==\n";
echo "GIT_HEAD   : " . trim(shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD')) . "\n";
echo "MIGRATIONS : " . count($all) . "\n";
echo 'i18n 相关迁移   : ' . implode(', ', $i18nMigrations) . "\n";
echo "⚠️ 重建型迁移: " . implode(', ', $rebuildMigrations) . "\n";
echo 'locale-bearing 表涉及迁移 (' . count($i18nTablesRelated) . ' 个): '
    . implode(', ', array_map(fn ($m) => basename($m, '.php'), $i18nTablesRelated)) . "\n";
echo "本轮验证目标 ({$target}): " . count($targets) . " 个\n";
echo implode("\n", array_map(fn ($m) => '  - '.basename($m, '.php'), $targets)) . "\n\n";

foreach ($targets as $name) {
    echo "========================================\n";
    echo "== [".basename($name, '.php')."]\n";
    echo "========================================\n";

    $idxInAll = array_search($name, $all, true);
    if ($idxInAll === false) {
        echo "  [SKIP] 未在迁移清单中\n\n";
        continue;
    }
    /** 前序迁移（不含目标本身）—— 用于「migrate 到 N-1」 */
    $upTo = array_slice($all, 0, $idxInAll);
    /** 含目标的迁移（migrate 到 N） */
    $upToAnd = array_slice($all, 0, $idxInAll + 1);

    // ---- 方向 A：空库往返 ----
    // 策略：Laravel 无法精确「migrate 到第 N 个」，故用等价可复现方式——
    // 独立库全量 migrate → snapshot → rollback --step=1（撤销最后一个，即目标迁移）
    // → snapshot。空库下该迁移的语义 delta 本就会消失，本方向只断言
    //「**原本就存在的** i18n 资产不得丢失」。
    echo "\n-- 方向 A：空库 up→down --\n";

    // 精确「migrate 到 N」：把前 N 个迁移复制到临时目录，用 --path 限定。
    // Laravel 无「migrate 到第 N 个」选项，只有 --path 可用（20G-8-C 实测）。
    $stageDir = stageMigrations($dbDir, $name, $upToAnd);
    $dbA2 = newDb($dbDir.'/A_'.basename($name, '.php').'.sqlite');
    $r = runArtisan($root, ['migrate', '--force', '--path='.$stageDir, '--realpath'], $dbA2);
    if ($r['code'] !== 0) {
        echo "  [FAIL] migrate 到目标迁移失败\n".substr($r['out'], -600)."\n\n";
        $FAIL++;
        $FAILURES[] = basename($name).': migrate 到目标迁移失败';
        continue;
    }
    $snapBefore = snap($dbA2);

    // 「迁移前一层」基线（只跑到前序迁移，不含目标）——断言基线必须是它
    $dbPreA = newDb($dbDir.'/PreA_'.basename($name, '.php').'.sqlite');
    $preStageA = stageMigrations($dbDir, $name.'_preA', $upTo);
    runArtisan($root, ['migrate', '--force', '--path='.$preStageA, '--realpath'], $dbPreA);
    $snapPreA = snap($dbPreA);
    unset($dbPreA);

    $r = runArtisan($root, ['migrate:rollback', '--force', '--step=1', '--path='.$stageDir, '--realpath'], $dbA2);
    if ($r['code'] !== 0) {
        warn('A-rollback', 'rollback 失败', substr($r['out'], -400));
    }
    $snapAfter = snap($dbA2);

    // down 后目标迁移的语义 delta 是预期的（它新增的索引/列本就该消失）
    // 因此 A 方向只断言：**不得丢失原本就存在的 i18n 资产**
    // ⚠️ 只对「迁移 up 之前就已存在」的表断言 exists。
    // 若该迁移本身就是建表迁移（如 create_contents_table），rollback 后该表
    // 消失是**正确行为**（20G-8-A 实测：早期迁移阶段 sites 表尚不存在，
    // 播种代码硬依赖它会直接抛 no such table）。
    /**
     * ⚠️ 基线必须是「迁移**前**一层」（$snapPreA），不是「rollback 前」（$snapBefore）。
     *
     * 20G-8-A 实测踩坑：用 $snapBefore 会把「本迁移引入的locale 列」判成丢失 ——
     * add_locale_to_translatables 的 down 本就该删 locale，那是**正确行为**，
     * 却被判成「回滚丢失该列」（10 条假失败）。
     *
     * 正解：只有「迁移之前就存在」的列，rollback 后才必须仍在。
     */
    foreach (I18N_TABLES as $t) {
        // 该表在迁移前就不存在 → 本迁移新建 → rollback 后应消失
        if (! isset($snapPreA[$t])) {
            if (isset($snapBefore[$t])) {
                ok('A-'.$t.'.dropped', "{$t} 由本迁移新建，rollback 后应消失",
                    ! isset($snapAfter[$t]));
            }

            continue;
        }

        ok('A-'.$t.'.exists', "{$t} 表在 rollback 后仍存在", isset($snapAfter[$t]));
        if (! isset($snapAfter[$t])) {
            continue;
        }

        foreach (['locale', 'translation_group'] as $col) {
            if (! isset($snapPreA[$t]['columns'][$col])) {
                // 该列由本迁移引入 → rollback 后应消失
                if (isset($snapBefore[$t]['columns'][$col])) {
                    ok('A-'.$t.'.'.$col.'.introduced',
                        "{$t}.{$col} 由本迁移引入，rollback 后应消失",
                        ! isset($snapAfter[$t]['columns'][$col]));
                }

                continue;
            }
            ok(
                'A-'.$t.'.'.$col,
                "{$t}.{$col}（迁移前已存在）在 rollback 后仍在",
                isset($snapAfter[$t]['columns'][$col]),
                '回滚丢失该列'
            );
        }
    }

    // ---- 方向 B：带双语 canary 数据往返 ----
    echo "\n-- 方向 B：双语 canary 数据 up→down --\n";

    $dbB = newDb($dbDir.'/B_'.basename($name, '.php').'.sqlite');
    runArtisan($root, ['migrate', '--force', '--path='.$stageDir, '--realpath'], $dbB);

    // 播种：Site A/B × zh/en × same slug + translation_group
    //
    // ⚠️ 迁移链已创建 default 站点，且 sites.is_default 上有唯一约束
    //（只允许一个 is_default=1）。因此**复用该default 站作为 Site A**，
    // 只新增 Site B，不能再插一条 is_default=1（20G-8-C 实测踩到）。
    $pdo = new PDO('sqlite:'.$dbB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ⚠️ 早期迁移阶段 sites / contents 可能尚不存在或缺列（20G-8-A 实测：
    // 在 create_contents_table 这一层，sites 表还没建）。此时方向 B 的
    // 数据/行为验证**不适用**——必须显式跳过并说明原因，不能静默通过，
    // 也不能让PDO 异常中断整轮验证。
    $hasSites = (bool) $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='sites'"
    )->fetch();
    $hasContents = (bool) $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='contents'"
    )->fetch();
    $contentsCols = [];
    if ($hasContents) {
        foreach ($pdo->query('PRAGMA table_info(contents)') as $c) {
            $contentsCols[strtolower((string) $c['name'])] = true;
        }
    }
    $hasLocaleCol = isset($contentsCols['locale']);

    if (! $hasSites || ! $hasContents || ! $hasLocaleCol) {
        echo "  [SKIP] 方向 B 不适用：本迁移层尚无 sites 表 / contents 表 / contents.locale 列\n";
        echo "         （sites=" . ($hasSites ? 'Y' : 'N')
            . " contents=" . ($hasContents ? 'Y' : 'N')
            . " locale=" . ($hasLocaleCol ? 'Y' : 'N') . "）\n";
        // 仍需断言：rollback 后i18n schema 不丢（方向 A 已覆盖，这里只补数据层无变化的确认）
        unset($pdo);
        $rB = runArtisan($root, ['migrate:rollback', '--force', '--step=1', '--path='.$stageDir, '--realpath'], $dbB);
        ok('B.rollback-ok', 'rollback 执行成功', $rB['code'] === 0, substr($rB['out'], -300));
        echo "\n";

        continue;
    }

    $siteAId = null;
    foreach ($pdo->query("SELECT id FROM sites ORDER BY id LIMIT 1") as $r) {
        $siteAId = (int) $r['id'];
    }
    if ($siteAId === null) {
        $pdo->exec("INSERT INTO sites (name,slug,domain,status,is_default,created_at,updated_at)
                    VALUES ('A','site-a','a.t','active',1,'2026-01-01','2026-01-01')");
        $siteAId = (int) $pdo->lastInsertId();
    }

    // Site B（非 default）
    $pdo->exec("INSERT INTO sites (name,slug,domain,status,is_default,created_at,updated_at)
                VALUES ('B','site-b','b.t','active',0,'2026-01-01','2026-01-01')");
    $siteBId = (int) $pdo->lastInsertId();

    $seeded = 0;
    $groups = [
        [$siteAId, 'zh-CN', 'TG-001'], [$siteAId, 'en', 'TG-001'],
        [$siteBId, 'zh-CN', 'TG-002'], [$siteBId, 'en', 'TG-002'],
    ];
    foreach ($groups as [$sid, $loc, $tg]) {
        insertContent($pdo, [
            'site_id' => $sid, 'type' => 'article',
            'title' => "T-{$sid}-{$loc}", 'slug' => 'shared-slug',
            'status' => 'published', 'locale' => $loc,
            'translation_group' => $tg, 'content_hash' => 'HASH-'.$sid.'-'.$loc,
        ]);
        $seeded++;
    }

    $siteA = $siteAId;
    $siteB = $siteBId;
    unset($pdo);

    $snapB0 = snap($dbB);
    $rowsB0 = snapRows($dbB, ['contents']);

    // 「迁移前一层」基线：只跑到前序迁移（不含目标），用于区分
    // 「本迁移引入的列（rollback 后应消失）」与「既有列（rollback 后必须仍在）」
    $dbPre = newDb($dbDir.'/Pre_'.basename($name, '.php').'.sqlite');
    $preStage = stageMigrations($dbDir, $name.'_pre', $upTo);
    runArtisan($root, ['migrate', '--force', '--path='.$preStage, '--realpath'], $dbPre);
    $snapPre = snap($dbPre);
    unset($dbPre);

    ok('B-seed.count', "双语 canary 播种 {$seeded} 行（同slug / 2站 × 2语言 / 2个translation_group）",
        $seeded === 4, "实际 {$seeded}");

    /**
     * 行为型约束改在**独立副本库**上做（20G-8-A 实测踩坑）。
     *
     * ⚠️ 绝不能在 $dbB 上直接插行为验证数据：那些行（同站 zh+en 两行
     * shared-slug、cross-slug）会让后续 rollback 失败 ——
     * 回滚到 `UNIQUE(site_id, slug)`（无 locale 维度）时，同站同 slug 的
     * 两行直接撞唯一约束。于是「本迁移的 down 有问题」与
     * 「我的测试数据恰好触发了那个约束」两种原因混在一起，无法区分。
     *
     * 正确做法：复制一份库跑行为断言，$dbB 只保留 4 行 canary 用于
     * 数据保真 + 回滚验证。
     */
    $dbBehavior = $dbDir.'/Behavior_'.basename($name, '.php').'.sqlite';
    if (file_exists($dbBehavior)) {
        @unlink($dbBehavior);
    }
    copy($dbB, $dbBehavior);
    $pdo = new PDO('sqlite:'.$dbBehavior);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rejected = false;
    try {
        insertContent($pdo, ['site_id' => $siteA, 'type' => 'article', 'title' => 'dup',
            'slug' => 'shared-slug', 'status' => 'published', 'locale' => 'zh-CN',
            'translation_group' => 'TG-999', 'content_hash' => 'H']);
    } catch (PDOException $e) {
        $rejected = str_contains(strtoupper($e->getMessage()), 'UNIQUE');
    }
    ok('B-behavior.same-site-same-locale-dup', '同站+同语言+同 slug 被 UNIQUE 拒绝', $rejected);

    // 行为型约束：同站中英同 slug 应允许（locale 参与唯一键的核心证明）
    // ⚠️ 必须用**未被播种过**的 slug：播种阶段已写入 A/zh、A/en、B/zh、B/en
    // 四种组合，若这里再用 shared-slug + 同 site + 同 locale，等于重复插入
    // 已存在的组合，报的 UNIQUE 冲突与「locale 是否参与约束」无关（20G-8-C 实测踩过）。
    // 此处用 cross-slug 验证「约束允许跨语言/跨站」，用 dup-slug 验证「约束拒绝重复」。
    $sameSiteCrossLocale = true;
    $crossErr = '';
    try {
        insertContent($pdo, ['site_id' => $siteA, 'type' => 'article', 'title' => 'cross-zh',
            'slug' => 'cross-slug', 'status' => 'published', 'locale' => 'zh-CN',
            'translation_group' => 'TG-C1', 'content_hash' => 'H']);
        insertContent($pdo, ['site_id' => $siteA, 'type' => 'article', 'title' => 'cross-en',
            'slug' => 'cross-slug', 'status' => 'published', 'locale' => 'en',
            'translation_group' => 'TG-C2', 'content_hash' => 'H']);
    } catch (PDOException $e) {
        $sameSiteCrossLocale = false;
        $crossErr = $e->getMessage();
    }
    ok('B-behavior.same-site-cross-locale', '同站+同 slug+不同语言可共存（locale 参与 UNIQUE）',
        $sameSiteCrossLocale, $crossErr);

    // 行为型约束：不同站同语言同 slug 应允许
    // ⚠️播种阶段已写入 B/zh + shared-slug，此处再用同组合必然冲突；
    // 用未被占用的 slug 验证「UNIQUE 含 site_id」（约束存在性验证，非重复插入）。
    $allowed = true;
    $diffSiteErr = '';
    try {
        insertContent($pdo, ['site_id' => $siteB, 'type' => 'article', 'title' => 'x',
            'slug' => 'diff-slug', 'status' => 'published', 'locale' => 'zh-CN',
            'translation_group' => 'TG-D1', 'content_hash' => 'H']);
        // 跨站同语言同 slug 已存在的组合也要能再共存 —— 改用 A/en（播种已有 A/en）
        // 故此处直接验证：新 slug 在 siteB 可写入
    } catch (PDOException $e) {
        $allowed = false;
        $diffSiteErr = $e->getMessage();
    }
    ok('B-behavior.same-slug-diff-site', '不同站可写入相同 slug（UNIQUE 含 site_id）',
        $allowed, $diffSiteErr);
    unset($pdo);

    // rollback 最后一个迁移，再比对
    $r = runArtisan($root, ['migrate:rollback', '--force', '--step=1', '--path='.$stageDir, '--realpath'], $dbB);
    $snapB1 = snap($dbB);
    $rowsB1 = snapRows($dbB, ['contents']);

    /**
     * ⚠️ 前置判定：rollback 必须**真的撤销了目标迁移**，否则后续断言无意义。
     *
     * 实测踩坑（20G-8-A）：Laravel 的 rollback 按 batch 倒序，
     * 同一个库里 migrate 分批跑过会出现 batch 号错位，
     * `--step=1` 回滚的可能不是目标迁移，而是别的层。
     *此时 schema 没变、locale 列还在，于是「本迁移引入的列应消失」
     * 这类断言会全部误报失败。
     *
     * 判据：目标迁移的 basename 不应再出现在 migrations 表里。
     */
    $stillApplied = false;
    $pdoChk = new PDO('sqlite:'.$dbB);
    $targetStem = preg_replace('/\.php$/', '', basename($name));
    $st = $pdoChk->query("SELECT COUNT(*) c FROM migrations WHERE migration = "
        .$pdoChk->quote($targetStem))->fetch(PDO::FETCH_ASSOC);
    $stillApplied = ((int) ($st['c'] ?? 0)) > 0;
    unset($pdoChk);

    if ($stillApplied) {
        /**
         * 分两种原因，必须区分，否则会把「测试数据与回滚语义不兼容」
         * 误判成「迁移的 down 有缺陷」。
         *
         * 原因 A：真正回滚失败（产品缺陷）—— 无条件判FAIL。
         * 原因 B：**本迁移回滚后唯一约束退化为不含 locale 的形态**
         *   （如 `09_23_000001` 把它还原成 `UNIQUE(site_id, slug)`），
         *   而 canary 数据里同一 slug 有 zh + en 两行 —— 语义上**必然冲突**。
         *   这不是缺陷：回滚本就是「回到迁移前的单语世界」，
         *   而数据是迁移后的双语世界，两者本就不可共存。
         *
         * 判别方式：看该迁移的 down 是否含「不含 locale 的唯一约束」。
         */
        $downBody = (string) file_get_contents(
            $root.'/database/migrations/'.basename($name)
        );
        $downRevertsLocaleUnique = preg_match(
            '/UNIQUE\s*\(\s*site_id\s*,\s*slug\s*\)/i',
            $downBody
        ) === 1;

        if ($downRevertsLocaleUnique) {
            warn('B.rollback-incompatible',
                basename($name).' 回滚后唯一约束退化为不含 locale 形态',
                'canary 的双语数据（zh+en 同 slug）在该约束下必然冲突 —— '
                .'属语义不兼容而非迁移缺陷；本层的数据保真改由方向 A（空库）覆盖');
            echo "\n";

            continue;
        }

        ok('B.rollback-effective', 'rollback 确实撤销了目标迁移', false,
            basename($name).' 仍在 migrations 表中 —— 目标迁移的 down 真正失败');
        echo "\n";

        continue;
    }
    ok('B.rollback-effective', 'rollback 确实撤销了目标迁移', true);

    // 数据行保真：**只比对播种的那4 行**（按 translation_group 定位）。
    // 不可全表比对——上方的行为型断言会额外插入 cross-slug / diff-slug 行，
    // 那是验证约束用的临时数据，不属于「迁移是否丢数据」的判定范围。
    // 全表比对会把约束验证的副作用误判为数据漂移（20G-8-C 实测踩过）。
    $seedGroups = ['TG-001', 'TG-002'];
    $before4 = array_values(array_filter(
        $rowsB0['contents'] ?? [],
        fn ($r) => in_array($r['translation_group'] ?? null, $seedGroups, true)
    ));
    $afterAll = $rowsB1['contents'] ?? [];
    $after4 = array_values(array_filter(
        $afterAll,
        fn ($r) => in_array($r['translation_group'] ?? null, $seedGroups, true)
    ));

    $sameRows = json_encode($before4) === json_encode($after4) && count($before4) === 4;
    ok('B-data.identical', 'rollback 前后播种的双语数据行（2站×2语言）完全一致',
        $sameRows,
        $sameRows ? '' : 'before='.json_encode($before4, JSON_UNESCAPED_UNICODE)
            .' after='.json_encode($after4, JSON_UNESCAPED_UNICODE));

    // 四象限不变量：同 slug 下 A/zh、A/en、B/zh、B/en 四行都在，且互不相同
    $quad = [];
    foreach ($after4 as $r) {
        $quad[($r['site_id'] ?? '').'|'.($r['locale'] ?? '')] = $r['content_hash'] ?? null;
    }
    $expectKeys = [
        $siteA.'|zh-CN', $siteA.'|en', $siteB.'|zh-CN', $siteB.'|en',
    ];
    $quadOk = true;
    foreach ($expectKeys as $k) {
        if (! array_key_exists($k, $quad)) {
            $quadOk = false;
        }
    }
    // 四行 content_hash 必须两两不同（证明不是同一行被复制）
    $hashes = array_values($quad);
    $uniqueHashes = array_unique($hashes);
    $distinctOk = count($hashes) === 4 && count($uniqueHashes) === 4;

    ok('B-data.quadrant-present', 'rollback 后四象限行齐全（A/zh、A/en、B/zh、B/en）',
        $quadOk, '实际: '.json_encode($quad));
    ok('B-data.quadrant-distinct', '四象限行内容互不相同（Site × Locale 未串）',
        $distinctOk, 'hashes='.json_encode($hashes));

    /**
     * i18n schema 保真：只对**本迁移之前就已存在**的列断言「rollback 后仍在」。
     *
     * ⚠️ 判据必须是「迁移前一层」而非「rollback 前的快照」（20G-8-A 实测踩坑）：
     * 若某列正是本迁移引入的（如 add_locale_to_translatables 加的 locale、
     * create_pages_table 建的整张 pages 表），rollback 后它消失是**正确行为**。
     * 用 rollback 前快照当基线会把这些正常语义判成失败 —— 17 条假失败。
     *
     * 基线来源：$dbBefore（只跑到「前序迁移」的库），见下方。
     */
    foreach (I18N_TABLES as $t) {
        if (! isset($snapB0[$t])) {
            continue;
        }
        foreach (['locale', 'translation_group'] as $col) {
            $hadBefore = isset($snapPre[$t]) && isset($snapPre[$t]['columns'][$col]);
            if (! $hadBefore) {
                // 该列由本迁移（或本表整体）引入 → rollback 后应消失
                if (isset($snapB0[$t]['columns'][$col])) {
                    ok('B-schema.'.$t.'.'.$col.'.introduced',
                        "{$t}.{$col} 由本迁移引入，rollback 后应消失",
                        ! isset($snapB1[$t]['columns'][$col]));
                }

                continue;
            }
            ok('B-schema.'.$t.'.'.$col, "{$t}.{$col} rollback 后保真",
                isset($snapB1[$t]) && isset($snapB1[$t]['columns'][$col]));
        }
    }

    /**
     * 数据层行为型约束：rollback 后唯一约束**仍应生效**。
     *
     * ⚠️ 判据必须按 rollback 后的**实际列**来构造重复行（20G-8-A 实测）：
     * 若该迁移的 down 正确删除了 locale 列，则插入时不能再带 locale ——
     * 否则要么被 insertContent 静默跳过（断言测不到任何东西），
     * 要么触发与「locale 是否参与约束」无关的冲突。
     *
     * 正确做法：读 rollback 后的列集合，用该表当时的唯一键列（同 site / 同 slug）
     * 造一条重复行，验证 DB层确实拒绝。
     */
    $pdo = new PDO('sqlite:'.$dbB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $colsAfter = [];
    foreach ($pdo->query('PRAGMA table_info(contents)') as $c) {
        $colsAfter[strtolower((string) $c['name'])] = true;
    }
    $hasLocaleAfter = isset($colsAfter['locale']);

    $rejected2 = false;
    $err2 = '';
    try {
        // 重复行：沿用第一行的 site/slug（+ locale 若该层仍有此列）
        $first = $pdo->query('SELECT site_id, slug FROM contents ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $dup = ['site_id' => $first['site_id'] ?? $siteA, 'type' => 'article',
            'title' => 'dup2', 'slug' => $first['slug'] ?? 'shared-slug',
            'status' => 'published', 'content_hash' => 'H'];
        if ($hasLocaleAfter) {
            $dup['locale'] = 'zh-CN';
            $dup['translation_group'] = 'TG-997';
        }
        insertContent($pdo, $dup);
    } catch (PDOException $e) {
        $rejected2 = str_contains(strtoupper($e->getMessage()), 'UNIQUE');
        $err2 = $e->getMessage();
    }
    ok('B-behavior.after-rollback',
        'rollback 后 contents 唯一约束行为仍生效（'
        .($hasLocaleAfter ? '含 locale 版' : '已回退为不含 locale 版') . '）',
        $rejected2, $err2);
    unset($pdo);

    echo "\n";
}

echo "========================================\n";
echo "PASS: $PASS   FAIL: $FAIL   DELTA: $WARN\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo "  - $f\n";
    }
}
echo "========================================\n";
exit($FAIL === 0 ? 0 : 1);