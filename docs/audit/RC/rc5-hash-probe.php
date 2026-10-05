<?php
/**
 * RC-5 · hash 失败根因探针
 * 目的：C-20 加了 hashable 字段 locale 后，GF-HASH-001/002/004/006/011 变红。
 * 本探针只做一件事：对比「含locale」与「不含 locale」的 hashableFields 与 hash。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ContentFieldContract;

$out = [];

// 1) hashableFields 是否含 locale
$hf = ContentFieldContract::hashableFields();
$out['hashableFields'] = $hf;
$out['has_locale'] = in_array('locale', $hf, true);

// 2) locale 的契约定义
$all = ContentFieldContract::all();
$out['locale_spec'] = $all['locale'] ?? 'MISSING';

// 3) audit 是否报冲突
$out['audit'] = ContentFieldContract::audit();

// 4) HASH_SCHEMA_VERSION
$out['hash_schema_version'] = App\Models\Content::HASH_SCHEMA_VERSION;

// 5) 模拟：两个只差 status 的模型，hash 是否相同
$mk = function (array $attrs) {
    $c = new App\Models\Content();
    foreach ($attrs as $k => $v) { $c->setAttribute($k, $v); }
    return $c;
};
$a = $mk(['locale' => 'zh-CN', 'status' => 'published', 'title' => 'T', 'slug' => 's']);
$b = $mk(['locale' => 'zh-CN', 'status' => 'draft',    'title' => 'T', 'slug' => 's']);
$out['hash_status_differs'] = ($a->computeHash() !== $b->computeHash());

// 6) locale 不同应改变 hash（这是 C-20 的设计意图）
$c = $mk(['locale' => 'en', 'status' => 'published', 'title' => 'T', 'slug' => 's']);
$out['hash_locale_differs'] = ($a->computeHash() !== $c->computeHash());

// 7) 关键：clone 场景（模拟 GF-HASH-006）
$base = $mk(['locale' => 'zh-CN', 'status' => 'published', 'title' => 'T', 'slug' => 's']);
$baseHash = $base->computeHash();
$mutated = clone $base;
$mutated->synced_at = '2026-01-01';
$mutated->external_source = 'other';
$mutated->status = 'draft';
$out['clone_base_hash'] = substr($baseHash, 0, 16);
$out['clone_mutated_hash'] = substr($mutated->computeHash(), 0, 16);
$out['clone_same'] = ($baseHash === $mutated->computeHash());

// 8) 若 clone 后 locale 丢失 → 说明 Translatable/boot 有副作用
$out['clone_locate_after'] = var_export($mutated->getAttribute('locale'), true);
$out['base_locale'] = var_export($base->getAttribute('locale'), true);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);