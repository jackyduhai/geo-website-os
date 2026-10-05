#!/bin/bash
# 20G-6 · G6-UPGRADE 一键流程：构造 L0(40迁移) → 播种 → 快照 → 升级 → 验证
set -e
cd "/d/734666/GEO OS"
DB="D:/Temp/g6_up.sqlite"
M41="database/migrations/2026_10_02_000001_add_external_id_unique_to_contents.php"

echo "═══ [1/6] 构造 L0：临时移走第 41 个迁移 ═══"
rm -f "$DB"; touch "$DB"
mv "$M41" /tmp/hold_41.php
DB_DATABASE="$DB" php artisan migrate --force 2>&1 | tail -1
mv /tmp/hold_41.php "$M41"
php -r '$d=new PDO("sqlite:D:/Temp/g6_up.sqlite");
echo "  L0 迁移数: ".$d->query("SELECT COUNT(*) FROM migrations")->fetchColumn()."\n";
$r=$d->query("SELECT sql FROM sqlite_master WHERE tbl_name=\"contents\" AND type=\"table\"")->fetch();
preg_match_all("/UNIQUE\s*\([^)]*\)/",$r["sql"],$m);
echo "  contents UNIQUE（应为 2，缺 external_id）: ".implode("; ",$m[0])."\n";'

echo "═══ [2/6] geo:install + seed ═══"
DB_DATABASE="$DB" php artisan geo:install --no-interaction >/dev/null 2>&1
for s in StructureSeeder FactSeeder SettingSeeder; do
  DB_DATABASE="$DB" php artisan db:seed --class=$s --force >/dev/null 2>&1
done
echo "  完成"

echo "═══ [3/6] 播 L0 业务数据（双站同 slug 不同内容）═══"
GEO_DB="$DB" php D:/Temp/g6_seed_l0.php 2>&1 | tail -4

echo "═══ [4/6] 生成 L0 基线快照 ═══"
GEO_DB="$DB" GEO_DB_L0="$DB" php D:/Temp/g6_upgrade.php snapshot 2>&1 | tail -2

echo "═══ [5/6] 执行升级 40 → 41 ═══"
DB_DATABASE="$DB" php artisan migrate --force 2>&1 | tail -2
php -r '$d=new PDO("sqlite:D:/Temp/g6_up.sqlite"); echo "  L1 迁移数: ".$d->query("SELECT COUNT(*) FROM migrations")->fetchColumn()."\n";'

echo "═══ [6/6] 升级后验证 ═══"
GEO_DB="$DB" GEO_DB_L0="$DB" php D:/Temp/g6_upgrade.php verify 2>&1