#!/usr/bin/env bash
# Discovery 交付物完整性核验。
#
# 背景：d5-*.md 曾三次被对话引用文本覆盖（读取上一份文档后，
#      下一次写入把它替换成引用块内容）。.gitattributes 的 -merge=ours
#      只防 merge 冲突，**不防直接 Write 覆盖**。
#      本脚本用于提交前核验：这些文档必须是架构基线，不能被引用文本替换。
#
# 用法：bash tools/check-discovery-docs.sh
# 退出码：0 = 全部通过；1 = 发现异常

set -uo pipefail
cd "$(dirname "$0")/.."

FAIL=0

# 1. 文件必须存在且行数达标（引用文本通常 100-500 行但缺少章节结构）
check() {
  local f="$1" min_lines="$2"
  if [[ ! -f "$f" ]]; then
    echo "  ✗ $f 缺失"
    FAIL=1; return
  fi
  local lines; lines=$(wc -l < "$f")
  if (( lines < min_lines )); then
    echo "  ✗ $f 行数不足（$lines < $min_lines）"
    FAIL=1; return
  fi
  # 架构基线文档必须带状态头
  if ! head -20 "$f" | grep -qE '状态|裁决|交付物|DECIDED|SURVEY|COMPLETE'; then
    echo "  ✗ $f 缺少状态头（疑似被替换为对话文本）"
    FAIL=1; return
  fi
  echo "  ✓ $f（$lines 行）"
}

echo "Discovery 交付物核验："
check docs/product/d1-lifecycle-domain-model.md      300
check docs/product/d2-public-surface-inventory.md     250
check docs/product/d4-deletion-recovery-semantics.md 400
check docs/product/d5-entity-purge-knowledge-decisions.md 300
check docs/product/d5-2-entity-lifecycle-class-matrix.md 250
check docs/product/d5-3-relation-lifecycle.md        200
check docs/product/d5-4-semantic-reference-degradation.md 200

# 2. 关键取证点必须在位（防止内容被替换但行数仍达标）
key() {
  local f="$1"; shift
  for kw in "$@"; do
    if ! grep -q "$kw" "$f"; then
      echo "  ✗ $(basename "$f") 缺关键取证点：$kw"
      FAIL=1
    fi
  done
}

key docs/product/d5-2-entity-lifecycle-class-matrix.md \
    "Aggregate Root" "is_site_organization" "P11"
key docs/product/d5-3-relation-lifecycle.md \
    "structural assertion" "dangling"
key docs/product/d5-4-semantic-reference-degradation.md \
    "fact_refs" "ContentGate" "P13"

if (( FAIL )); then
  echo ""
  echo "⚠️  Discovery 交付物异常。若文件被对话引用文本覆盖："
  echo "    git checkout HEAD -- docs/product/d5-*.md"
  exit 1
fi

echo ""
echo "✓ 全部 Discovery 交付物完整"
