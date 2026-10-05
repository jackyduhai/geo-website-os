#!/bin/bash
# 20G-6 · 全量回滚保真度探针：逐个迁移验证 down() 是否精确回到执行前状态
cd "/d/734666/GEO OS"
OUT="D:/Temp/g6_rollback_fidelity.json"
: > /tmp/g6_fidelity.txt

TOTAL=$(ls database/migrations/*.php | wc -l)
echo "共 $TOTAL 个迁移，逐个验证 down() 保真度..."
echo ""

for s in $(seq 0 $((TOTAL-2))); do
  RES=$(GEO_STEP=$s php D:/Temp/g6_probe_all.php 2>&1)
  #提取 JSON 主体（可能前面有 warning）
  JSON=$(echo "$RES" | sed -n '/^{/,$p')
  LINE=$(echo "$JSON" | python3 -c "
import sys, json
raw = sys.stdin.read().strip()
if not raw:
    print('PARSE_FAIL'); raise SystemExit
try:
    d = json.loads(raw)
except Exception as e:
    print('PARSE_FAIL'); raise SystemExit
diffs = d.get('unexpected_diff', [])
tgt = d.get('target_migration', '?')
if not diffs:
    print(f'PASS|{tgt}')
else:
    print(f'FAIL|{tgt}|' + ' ;; '.join(diffs[:4]))
" 2>/dev/null)
  echo "$LINE" >> /tmp/g6_fidelity.txt
  NAME=$(echo "$LINE" | cut -d'|' -f2)
  STATUS=$(echo "$LINE" | cut -d'|' -f1)
  if [ "$STATUS" = "PASS" ]; then
    printf "  ✅ %-58s\n" "$NAME"
  else
    printf "  ❌ %-58s %s\n" "$NAME" "$(echo "$LINE" | cut -d'|' -f3-)"
  fi
done

echo ""
echo "═══ 汇总 ═══"
echo "PASS: $(grep -c '^PASS' /tmp/g6_fidelity.txt) / $TOTAL"
echo "FAIL: $(grep -c '^FAIL' /tmp/g6_fidelity.txt)"
echo "PARSE_FAIL: $(grep -c '^PARSE_FAIL' /tmp/g6_fidelity.txt)"
echo ""
echo "── 失败明细 ──"
grep '^FAIL' /tmp/g6_fidelity.txt | while IFS='|' read -r _ name detail; do
  echo "  · $name"
  echo "    $detail"
done
grep '^PARSE_FAIL' /tmp/g6_fidelity.txt | head -5