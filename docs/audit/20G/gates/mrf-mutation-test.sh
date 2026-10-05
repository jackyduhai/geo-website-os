#!/bin/bash
# MRF 契约变异测试（20G-8-B）
# 逐个注入缺陷 → 确认 phpunit 变红 → 恢复 → 确认变绿
set -u
cd "D:/GEO-OS-rewrite/geo-website-os" || exit 1

T=tests/Feature/MigrationRollbackFidelityTest.php
M1=database/migrations/2026_09_23_000001_add_locale_to_translatables.php
M2=database/migrations/2026_10_02_000001_add_external_id_unique_to_contents.php
cp "$M1" /tmp/v1.bak
cp "$M2" /tmp/v2.bak

chk() {
  php -d memory_limit=1G vendor/bin/phpunit "$T" --no-coverage > /tmp/mrf_out.txt 2>&1
  if grep -qE "^OK" /tmp/mrf_out.txt; then echo "GREEN"; else echo "RED ($(grep -cE '\[FAIL\]|Failures: [1-9]' /tmp/mrf_out.txt) fail)"; fi
}

restore() { cp /tmp/v1.bak "$M1"; cp /tmp/v2.bak "$M2"; }

echo -n "baseline                : "; chk

python - <<'PY'
import io
p='database/migrations/2026_09_23_000001_add_locale_to_translatables.php'
s=io.open(p,encoding='utf-8').read().replace("['locale', 'translation_group']","['locale']",1)
io.open(p,'w',encoding='utf-8').write(s)
PY
echo -n "mut1 drop translation_grp: "; chk; restore

python - <<'PY'
import io
p='database/migrations/2026_09_23_000001_add_locale_to_translatables.php'
s=io.open(p,encoding='utf-8').read().replace("['locale', 'translation_group']","['translation_group']",1)
io.open(p,'w',encoding='utf-8').write(s)
PY
echo -n "mut2 drop locale         : "; chk; restore

python - <<'PY'
import io
p='database/migrations/2026_10_02_000001_add_external_id_unique_to_contents.php'
s=io.open(p,encoding='utf-8').read()
old = "            locale VARCHAR(16) NOT NULL DEFAULT \\'zh-CN\\',\n            translation_group VARCHAR(36),\n            UNIQUE(site_id, slug, locale),"
i = s.rfind(old)
assert i > 0, 'fragment not found'
s = s[:i] + "            UNIQUE(site_id, slug)," + s[i+len(old):]
io.open(p,'w',encoding='utf-8').write(s)
PY
echo -n "mut3 down lose i18n cols : "; chk; restore

python - <<'PY'
import io
p='database/migrations/2026_10_02_000001_add_external_id_unique_to_contents.php'
s=io.open(p,encoding='utf-8').read()
old = "            UNIQUE(site_id, slug, locale),"
i = s.find(old)
assert i > 0
s = s[:i] + "            UNIQUE(site_id, slug)," + s[i+len(old):]
io.open(p,'w',encoding='utf-8').write(s)
PY
echo -n "mut4 up lose locale in UNQ: "; chk; restore

echo -n "restored                : "; chk
