<?php
/**
 * 20G-8-C-2i18n schema 资产全量扫描
 * ------------------------------------------------------------------
 * 从**真实迁移后的库**提取 i18n schema 资产（不读源码推测）。
 *
 * 输出维度（每张 locale-bearing 表）：
 *   表名 / 列 / 唯一约束 / 索引 / 外键 / 由哪个迁移引入 / 哪些迁移重建了它
 *
 * 「重建」判定：该迁移源码中出现 CREATE TABLE（重建）而非仅 ADD COLUMN。
 * 重建是 i18n 列丢失的高风险动作，必须单独列出。
 *
 * 用法：GEO_ROOT=<repo> php i18n-schema-inventory.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
$db   = 'D:/Temp/g8c_inventory.sqlite';

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE=$db");

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------- 迁移到最新
if (file_exists($db)) {
    @unlink($db);
}
touch($db);
Artisan::call('migrate', ['--force' => true]);

// ⚠️迁移后不要触碰 Eloquent 模型（如 Setting::allCached()）：
// View composer / BelongsToSite 的全局 Scope 会在无播种数据时抛
//「no such column: settings.site_id」—— settings 表**设计上就是全局单站表**
// （key全局 UNIQUE、无 site_id），SiteScope 与 BelongsToSite 均已hasColumn 防护，
// 但静态扫描不该依赖应用层。本脚本一律走 DB::select 直读 sqlite。

echo "== 基线 ==\n";
$rows = DB::table('migrations')->orderBy('id')->get();
echo 'applied: ' . $rows->count() . "\n";
echo 'head   : ' . $rows->last()->migration . "\n\n";

// ---------------------------------------------------------------- schema 提取
function tables(): array
{
    return DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
}

function columnsOf(string $table): array
{
    $out = [];
    foreach (DB::select("PRAGMA table_info({$table})") as $c) {
        $out[] = [
            'name' => $c->name,
            'type' => $c->type,
            'notnull' => (int) $c->notnull,
            'default' => $c->dflt_value,
            'pk' => (int) $c->pk,
        ];
    }
    return $out;
}

function indexesOf(string $table): array
{
    $out = [];
    foreach (DB::select("PRAGMA index_list({$table})") as $i) {
        $cols = [];
        foreach (DB::select("PRAGMA index_info('{$i->name}')") as $c) {
            $cols[] = $c->name;
        }
        $out[] = [
            'name' => $i->name,
            'unique' => (int) $i->unique === 1,
            'origin' => $i->origin,   // c=CREATE INDEX, u=UNIQUE(表级), pk=主键
            'partial' => (int) $i->partial,
            'columns' => $cols,
        ];
    }
    return $out;
}

function fksOf(string $table): array
{
    $out = [];
    foreach (DB::select("PRAGMA foreign_key_list({$table})") as $f) {
        $out[] = $f->from.' -> '.$f->table.'.'.$f->to
            .($f->on_delete && $f->on_delete !== 'NO ACTION' ? ' ON DELETE '.$f->on_delete : '');
    }
    return $out;
}

// ---------------------------------------------------------------- i18n 资产判定
$I18N_TOKENS = ['locale', 'translation_group'];

echo "== i18n schema 资产清单 ==\n\n";

$assets = [];
foreach (tables() as $t) {
    $table = $t->name;
    $cols = columnsOf($table);
    $i18nCols = array_values(array_filter(
        $cols,
        fn ($c) => in_array($c['name'], $I18N_TOKENS, true)
    ));

    $idx = indexesOf($table);
    $i18nIdx = array_values(array_filter(
        $idx,
        fn ($i) => array_intersect($i18nCols ? array_column($i18nCols, 'name') : [], $i['columns'])
    ));

    if ($i18nCols === [] && $i18nIdx === []) {
        continue;
    }

    $assets[$table] = [
        'columns' => $i18nCols,
        'indexes' => $i18nIdx,
        'all_indexes' => $idx,
        'fks' => fksOf($table),
    ];
}

foreach ($assets as $table => $a) {
    echo "### {$table}\n";
    echo "  i18n 列:\n";
    foreach ($a['columns'] as $c) {
        $dflt = $c['default'] === null ? '' : " DEFAULT {$c['default']}";
        echo "    - {$c['name']} {$c['type']}"
            .($c['notnull'] ? ' NOT NULL' : '')
            .($c['pk'] ? ' PK' : '')
            .$dflt."\n";
    }
    echo "  i18n 相关索引:\n";
    foreach ($a['indexes'] as $i) {
        $u = $i['unique'] ? 'UNIQUE ' : '';
        $p = $i['partial'] ? ' PARTIAL' : '';
        echo "    - [{$i['origin']}] {$u}{$i['name']} (" . implode(',', $i['columns']) . "){$p}\n";
    }
    echo "  全部唯一约束:\n";
    $uniques = array_values(array_filter($a['all_indexes'], fn ($i) => $i['unique']));
    if ($uniques === []) {
        echo "    （无）\n";
    }
    foreach ($uniques as $i) {
        echo "    - [{$i['origin']}] {$i['name']} (" . implode(',', $i['columns']) . ")\n";
    }
    echo "  外键:\n";
    if ($a['fks'] === []) {
        echo "    （无）\n";
    }
    foreach ($a['fks'] as $f) {
        echo "    - {$f}\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------- 迁移归属
echo "== 迁移归属与重建风险 ==\n\n";

$files = glob($root . '/database/migrations/*.php');
sort($files);

$intro = [];   // 表 => 引入 locale 的迁移
$rebuild = []; // 表 => 重建该表的迁移（高风险）

foreach ($files as $f) {
    $name = basename($f, '.php');
    $src = (string) file_get_contents($f);

    if (! str_contains($src, 'locale') && ! str_contains($src, 'translation_group')) {
        continue;
    }

    // 涉及的目标表
    preg_match_all("/(?:Schema::(?:table|create)|TABLE|INTO)\s*\(?\s*'([a-z_]+)'/i", $src, $m);
    $targets = array_values(array_unique($m[1]));

    // 重建判定：CREATE TABLE（CTAS 或显式建表后 RENAME）
    $isRebuild = (bool) preg_match('/CREATE\s+TABLE/i', $src);
    $hasRename = (bool) preg_match('/ALTER\s+TABLE\s+\S+\s+RENAME\s+TO/i', $src);

    foreach ($targets as $t) {
        if (! isset($assets[$t])) {
            continue; // 该表最终无 i18n 列（如中途加过又删）
        }
        $intro[$t][] = $name;
        if ($isRebuild) {
            $rebuild[$t][] = ['migration' => $name, 'rename' => $hasRename];
        }
    }
}

foreach ($assets as $table => $a) {
    $ins = $intro[$table] ?? [];
    $rbs = $rebuild[$table] ?? [];

    echo "### {$table}\n";
    echo "  引入/变更 i18n 的迁移:\n";
    foreach ($ins as $i) {
        echo "    - {$i}\n";
    }
    if ($rbs === []) {
        echo "  重建该表的迁移: 无\n";
    } else {
        echo "  ⚠️重建该表的迁移（i18n 列丢失高风险）:\n";
        foreach ($rbs as $r) {
            echo "    - {$r['migration']}" . ($r['rename'] ? '（含 RENAME 换表）' : '') . "\n";
        }
    }
    echo "\n";
}

echo "== 汇总 ==\n";
echo 'locale-bearing 表: ' . implode(', ', array_keys($assets)) . "\n";
echo '重建风险表      : ' . (count($rebuild) ? implode(', ', array_keys($rebuild)) : '无') . "\n";