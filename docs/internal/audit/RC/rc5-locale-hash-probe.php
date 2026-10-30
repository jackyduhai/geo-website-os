<?php
/**
 * RC-5 · locale 参与 hash 的首次/二次推送一致性探针
 *
 * 事实：payload 不含 locale；DB 列默认 'zh-CN'。
 * 疑点：首次创建时 hash 用「模型属性值」，二次推送时用「库中值」，
 *      若两者不同 → hash 变 → 第二次误判 changed 而非 skip。
 *
 * 本探针在**同一个内存库**里模拟 GeoflowSync 的取值路径。
 */
$root = getenv('GEO_ROOT');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Content;
use App\Support\ContentFieldContract;
use Illuminate\Support\Facades\DB;

$out = [];

// 用内存库模拟（与 phpunit 一致）
config(['database.connections.sqlite.database' => ':memory:']);
DB::purge('sqlite');

// 建最小表（只需要 contents 的相关列）
DB::statement("CREATE TABLE contents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id INTEGER NOT NULL DEFAULT 1,
  type VARCHAR(255) NOT NULL DEFAULT 'article',
  title VARCHAR(255) NULL, slug VARCHAR(255) NULL,
  locale VARCHAR(16) NOT NULL DEFAULT 'zh-CN',
  status VARCHAR(255) NOT NULL DEFAULT 'draft',
  external_id VARCHAR(255) NULL, content_hash VARCHAR(255) NULL,
  deleted_at DATETIME NULL, slot VARCHAR(255) NULL,
  translation_group VARCHAR(36) NULL, category_id INTEGER NULL, group_id INTEGER NULL,
  created_at DATETIME NULL, updated_at DATETIME NULL
)");

$hf = ContentFieldContract::hashableFields();
$out['hashable_has_locale'] = in_array('locale', $hf, true);
$out['locale_spec'] = ContentFieldContract::all()['locale'];

// ── 第一次推送：payload 无 locale ──
$c1 = new Content();
$c1->setAttribute('site_id', 1);
$c1->setAttribute('title', 'T1');
$c1->setAttribute('slug', 's1');
$c1->setAttribute('external_id', 'HASH-001');
// 关键：模拟「payload 未提供 locale」→ 模型属性缺省
$hash1 = $c1->computeHash();
$out['push1_model_locale'] = var_export($c1->getAttribute('locale'), true);
$out['push1_hash'] = substr($hash1, 0, 16);

// 落库（DB 默认 'zh-CN'）
DB::table('contents')->insert([
  'site_id' => 1, 'title' => 'T1', 'slug' => 's1', 'external_id' => 'HASH-001',
  'locale' => 'zh-CN', 'status' => 'draft', 'content_hash' => $hash1,
  'created_at' => now(), 'updated_at' => now(),
]);

// ── 第二次推送：从库中读回，计算新 hash ──
$c2 = Content::where('external_id', 'HASH-001')->first();
$hash2 = $c2->computeHash();
$out['push2_model_locale'] = var_export($c2->getAttribute('locale'), true);
$out['push2_hash'] = substr($hash2, 0, 16);

$out['hash_stable'] = ($hash1 === $hash2);
$out['verdict'] = $hash1 === $hash2
    ? 'OK：locale 参与 hash 后仍稳定'
    : 'BUG：首次 hash 用「缺省值」，二次用「库中值」→ 不一致';

// 进一步：若 payload 显式传 locale='zh-CN'，首次是否就一致？
$c3 = new Content();
$c3->setAttribute('site_id', 1);
$c3->setAttribute('title', 'T1');
$c3->setAttribute('slug', 's1');
$c3->setAttribute('locale', 'zh-CN');
$out['push1_explicit_locale_hash'] = substr($c3->computeHash(), 0, 16);
$out['explicit_matches_db'] = ($c3->computeHash() === $hash2);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);