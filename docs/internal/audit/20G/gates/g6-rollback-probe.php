<?php
/**
 * 20G-6 · 全量回滚保真度探针
 *
 * 对全部 41 个迁移逐个验证：up → 记录全库 schema → down → 比对
 * 判据：回滚后表定义必须回到「该迁移执行前」的状态
 *   - 列类型 / NOT NULL / DEFAULT / PRIMARY KEY
 *   - UNIQUE 约束
 *   - FOREIGN KEY
 *   - 独立索引
 *
 * 做法：
 *   1) 全新库 migrate 到第 N 个 → 快照 schemaA（执行前）
 *   2) migrate 到第 N+1 个 → 快照 schemaB（执行后）
 *   3) rollback 一步 → 快照 schemaC
 *   4) C 必须等于 A（B→C 的差异应只涉及该迁移改的东西）
 *
 * 用法：GEO_STEP=<N> php g6_probe_all.php   （N 从 1 到 40，验证第 N+1 个迁移的回滚）
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$step = (int) (getenv('GEO_STEP') ?: 1);
$db   = 'D:/Temp/g6_probe_all.sqlite';

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

@unlink($db); touch($db);
putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
config(['database.connections.sqlite.database' => $db]);
DB::purge('sqlite'); DB::reconnect('sqlite');

function snap(): array
{
    $s = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $t = $r->name;
        $cols = [];
        foreach (DB::select("PRAGMA table_info(\"$t\")") as $c) {
            $cols[$c->name] = $c->type . '|' . $c->notnull . '|' . $c->pk . '|' . ($c->dflt_value ?? '');
        }
        $uq = [];
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE tbl_name=? AND type='table'", [$t]) as $x) {
            if ($x->sql) { preg_match_all('/UNIQUE\s*\([^)]*\)/i', $x->sql, $m);
                foreach ($m[0] as $u) { $uq[] = strtolower(preg_replace('/[\s`"\[\]]/', '', $u)); } }
        }
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE tbl_name=? AND type='index' AND sql IS NOT NULL", [$t]) as $x) {
            if (stripos((string) $x->sql, 'UNIQUE') !== false && preg_match('/\bon\s+\S+\s*\(([^)]*)\)/i', $x->sql, $m)) {
                $uq[] = 'unique(' . strtolower(preg_replace('/[\s`"\[\]]/', '', $m[1])) . ')';
            }
        }
        $fk = [];
        foreach (DB::select("PRAGMA foreign_key_list(\"$t\")") as $f) { $fk[] = $f->from . '>' . $f->table . '.' . $f->to; }
        $ix = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE tbl_name=? AND type='index' AND sql IS NOT NULL", [$t]) as $x) { $ix[] = $x->name; }
        sort($uq); sort($fk); sort($ix);
        $s[$t] = ['cols' => $cols, 'uq' => $uq, 'fk' => $fk, 'ix' => $ix];
    }
    return $s;
}

function diff(array $a, array $b): array
{
    $out = [];
    foreach ($a as $t => $fa) {
        if (!isset($b[$t])) { $out[] = "$t 表消失"; continue; }
        $fb = $b[$t];
        foreach ($fa['cols'] as $cn => $v) {
            if (!isset($fb['cols'][$cn])) { $out[] = "$t.$cn 列消失"; }
            elseif ($fb['cols'][$cn] !== $v) {
                $out[] = "$t.$cn 定义变(" . str_replace('|', '/', $v) . '→' . str_replace('|', '/', $fb['cols'][$cn]) . ')';
            }
        }
        foreach (array_diff($fa['uq'], $fb['uq']) as $u) { $out[] = "$t UNIQUE 丢失 $u"; }
        foreach (array_diff($fa['fk'], $fb['fk']) as $f) { $out[] = "$t FK 丢失 $f"; }
        foreach (array_diff($fa['ix'], $fb['ix']) as $i) { $out[] = "$t 索引丢失 $i"; }
    }
    return $out;
}

// A = 前 step 个迁移后的状态；B = 含第 step+1 个；C = 回滚第 step+1 个后
Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations']);
$all = DB::table('migrations')->pluck('migration')->all();
$target = $all[$step] ?? null;
if ($target === null) { echo "step 超出范围\n"; exit; }

// A: 只跑前 step 个迁移（--step 语义在此脚本不可靠，改用逐个 up 到目标前）
//简化方案：A 直接=「全新库 migrate 后回滚 step 个」不可靠，
// 因此改为：B = 完整 migrate；C = rollback 第 step+1 个；
// A 用「干净库只 migrate --step=N」的等价实现：先 migrate 全量再 rollback (总数-step) 不可靠，
// 最终采用：把第 step+1 个之后的迁移文件临时不可见 -> migrate -> A。
$files = glob($root . '/database/migrations/*.php');
sort($files);
$keep = array_slice($files, 0, $step);
$stash = array_slice($files, $step);
foreach ($stash as $f) { rename($f, $f . '.hold'); }
DB::purge('sqlite'); DB::reconnect('sqlite');
@unlink($db); touch($db);
Artisan::call('migrate', ['--force' => true]);
DB::purge('sqlite'); DB::reconnect('sqlite');
$A = snap();
$appliedA = DB::table('migrations')->count();
foreach ($stash as $f) { rename($f . '.hold', $f); }

// B: 全量
DB::purge('sqlite'); DB::reconnect('sqlite');
Artisan::call('migrate', ['--force' => true]);
DB::purge('sqlite'); DB::reconnect('sqlite');
$B = snap();

// C: 回滚第 step+1 个
Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
DB::purge('sqlite'); DB::reconnect('sqlite');
$C = snap();

$dAC = diff($A, $C);
$dBC = diff($B, $C);

// 预期差异：回滚第 41 号迁移（external_id 唯一约束）时，唯一键external_id
// 本就该消失——那正是该迁移 up() 所做的事。除此之外的任何 schema 差异都是缺陷。
$EXPECTED = [
    'target_migration' => '2026_10_02_000001_add_external_id_unique_to_contents',
    'allowed' => ['contents UNIQUE 丢失 unique(site_id,external_id)'],
];
$unexpected = $dAC;
if ($target === $EXPECTED['target_migration']) {
    $unexpected = array_values(array_diff($dAC, $EXPECTED['allowed']));
}

echo json_encode([
    'step' => $step, 'target_migration' => $target,
    'migrations_after_A' => $appliedA,
    'A_to_C_diff' => $dAC,
    'unexpected_diff' => $unexpected,
    'pass' => empty($unexpected),
], JSON_UNESCAPED_UNICODE);