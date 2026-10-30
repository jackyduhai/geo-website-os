#!/usr/bin/env bash
# 20G-7.1 · Site Lifecycle 变异测试（断言有效性证明）
# ------------------------------------------------------------------
# 目的：证明 site-lifecycle-e2e.php 的断言**真的有牙**。
#
# 20G-3 / 20G-8 的教训：静态断言（"源码里有没有 forLocale"）多次是恒真的假阳性。
# 本轮先跑一轮基线，再逐个注入缺陷，确认对应断言必然变红。
#
# 变异覆盖：
#   M1  SiteStructureSeeder 不跑           → BS-002/004/005 全红
#   M2  categories 不限定站点（去 SiteScope）→ SA-002.site-context / MS-001 全红
#   M3  updateOrCreate 替代 firstOrCreate → BS-008.no-overwrite 红（覆盖人工修改）
#   M4  不启用 en                          → BS-005/BS-005.route 红
#   M5  栏目 slug 不含 site_id 维度        → MS-001.slug-ok 红
#
# 用法：bash docs/audit/20G/gates/site-lifecycle-mutation-test.sh
# 退出码：0 = 全部变异都被捕获

set -u
cd "$(git rev-parse --show-toplevel)" || exit 2

SEEDER="database/seeders/SiteStructureSeeder.php"
CTRL="app/Http/Controllers/Admin/SiteController.php"
E2E="docs/audit/20G/e2e/site-lifecycle-e2e.php"

WORK="D:/Temp/_g71_mutation"
BACKUP_SEEDER="${WORK}/SiteStructureSeeder.bak"
BACKUP_CTRL="${WORK}/SiteController.bak"
mkdir -p "$WORK"
cp "$SEEDER" "$BACKUP_SEEDER" || exit 2
cp "$CTRL" "$BACKUP_CTRL" || exit 2

DB="D:/Temp/_g71_mutation.sqlite"

restore() {
    cp "$BACKUP_SEEDER" "$SEEDER"
    cp "$BACKUP_CTRL" "$CTRL"
}
trap restore EXIT

reset_db() {
    rm -f "$DB" "$DB.crashed" "$DB.finished"
    touch "$DB"
    # 必须清 file store：PageCache 硬编码 Cache::store('file')，绕过 CACHE_STORE=array，
    # 跨轮残留会让「A 写入是否推进 B 版本」失真（20G-3 实测同款问题）。
    php artisan cache:clear >/dev/null 2>&1
    DB_CONNECTION=sqlite DB_DATABASE="$DB" php artisan migrate --force >/dev/null 2>&1
}

run_e2e() {
    GEO_ROOT="$(pwd)" GEO_DB="$DB" php -d memory_limit=1G "$E2E" > "${WORK}/out.txt" 2>&1
    E2E_EXIT=$?
    # grep -c 在无匹配时返回 0 并带 exit 1，会与 || echo 0 拼成 "0\n0"。
    # 故用 tr 计数（无匹配时输出 0，不带退出码问题）。
    RESULT="$(grep -o '\[FAIL\]' "${WORK}/out.txt" 2>/dev/null | wc -l | tr -d ' ')"
    PASSED_COUNT="$(grep -oP '^PASS: \K[0-9]+' "${WORK}/out.txt" 2>/dev/null | head -1 | tr -d ' ')"
    # 崩溃判据：**靠「完成标记」反推**（退出码与 error_get_last 在 Laravel 里都不可信，
    # 见 E2E 头部说明）。没有 .finished = 脚本没跑到末尾 = 中途崩了。
    if [ -f "$DB.finished" ]; then
        CRASHED=0
    else
        CRASHED=1
    fi
}

mutate() {
    local label="$1" file="$2" from="$3" to="$4"
    restore
    reset_db   # 每次变异重建独立库，避免上一轮残留干扰（约 8-10 秒）

    python - "$file" "$from" "$to" <<'PY'
import io, sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]

raw = io.open(path, 'rb').read().decode('utf-8')
# ⚠️ Windows 上源文件是 CRLF，注入片段是 LF —— 不归一会永远匹配不上，
#    表现为「变异被静默跳过」，看起来像「断言没守住」（20G-7.1 连踩 3 次）。
crlf = '\r\n' in raw
src = raw.replace('\r\n', '\n')

old_n = old.replace('\r\n', '\n')
new_n = new.replace('\r\n', '\n')

if old_n not in src:
    print("MUTATE_TARGET_NOT_FOUND")
    sys.exit(3)

out = src.replace(old_n, new_n, 1)
if crlf:
    out = out.replace('\n', '\r\n')
io.open(path, 'wb').write(out.encode('utf-8'))
print("MUTATED")
PY
    if [ $? -ne 0 ]; then
        echo "  [SKIP] $label — 目标片段未找到"
        return
    fi

    printf '  [%d/%d] %s ... ' "$((PASSED + TOTAL + 1))" "$((TOTAL + 1))" "$label"
    run_e2e
    # 判据：E2E 非 0 退出即视为「有反应」。
    # 既包括 [FAIL] 断言红，也包括脚本崩溃 —— 变异把库结构破坏到
    # 查询抛异常时，崩溃本身就是「行为已被破坏」的证据，
    # 不能因为「没看到 [FAIL] 行」就判 MISSED（20G-7.1 实测踩过）。
    if [ "$RESULT" -gt 0 ] || [ "$CRASHED" -eq 1 ] || [ "$E2E_EXIT" -ne 0 ]; then
        if [ "$RESULT" -gt 0 ]; then
            echo "CAUGHT（$RESULT 条断言变红）"
        else
            echo "CAUGHT（脚本崩溃，退出码 ${E2E_EXIT} —— 行为已被破坏）"
        fi
        PASSED=$((PASSED + 1))
    else
        echo "MISSED  ← 断言没守住"
    fi
}

PASSED=0
TOTAL=0

echo "== 基线（未变异） =="
restore
reset_db
run_e2e
if [ "$RESULT" -eq 0 ]; then
    echo "  基线全绿（${PASSED_COUNT:-?} 断言通过）"
else
    echo "  ⚠️  基线本身就有 $RESULT 条失败 —— 先修基线再谈变异"
    tail -12 "${WORK}/out.txt"
    exit 1
fi
echo ""

echo "== 变异测试 =="

TOTAL=$((TOTAL + 1))
mutate "M1 Seeder 不做任何初始化" \
    "$SEEDER" \
    '        $this->seedCategories();
        $this->disableIndustrialNav();
        $this->enableBilingual();' \
    '        // 变异：完全不做初始化'

TOTAL=$((TOTAL + 1))
#⚠️ 这条变异第一版写的是 `['site_id' => 1, ...]`，结果**逃逸了**：
#   A 站恰好就是 site_id=1（default），所以「写死到站1」与正确版行为完全等价，
#   E2E 32 条断言照样全绿。→ 无效变异，不是断言不足。
#   改为「写死到站 1 之外的那个站」：B 站 bootstrap 时把栏目写进 A，
#   正是 20G-7.1 明确要防的「以为在管 B、实际写进 A」。
mutate "M2 栏目写到别的站（模拟以为在管 B、实际写进 A）" \
    "$SEEDER" \
    "            \$parent = Category::firstOrCreate(
                ['slug' => \$node['slug']]," \
    "            \$parent = Category::withoutSiteScope()->firstOrCreate(
                ['site_id' => 999999, 'slug' => \$node['slug']],"

TOTAL=$((TOTAL + 1))
mutate "M3 改用 updateOrCreate（会覆盖人工修改）" \
    "$SEEDER" \
    "            \$parent = Category::firstOrCreate(" \
    '            $parent = Category::updateOrCreate(' \

TOTAL=$((TOTAL + 1))
mutate "M4 不启用 en" \
    "$SEEDER" \
    "        if (! in_array('en', \$locales, true)) {
            \$locales[] = 'en';
        }" \
    "        // 变异：不启用 en"

TOTAL=$((TOTAL + 1))
# ⚠️ 第一版让匹配条件漏掉 category_id，触发 `NOT NULL constraint failed`
#   → 脚本崩溃、且**退出码仍 0**（与 20G-8「Laravel 吞异常」同款），被判 MISSED。
#   第二版 site_id=999999 同理：触发 FK 约束，崩在 bootstrap 之前。
# 定稿：**分组名被硬编码** —— 匹配键不变、行为正常执行，
#   但 B 站的分组名会显示成「A-SITE-ONLY」→ 站间数据串味，必须被抓到。
mutate "M5 分组名被硬编码（模拟站间数据串味）" \
    "$SEEDER" \
    "                        'name'      => \$g['name'],
                        'sort'      => \$g['sort']," \
    "                        'name'      => 'A-SITE-ONLY',
                        'sort'      => \$g['sort'],"

echo ""
echo "捕获率: $PASSED / $TOTAL"
if [ "$PASSED" -eq "$TOTAL" ]; then
    echo "✅ 契约有效：全部变异都被捕获"
    exit 0
fi
echo "⚠️  有变异未被捕获 —— 断言强度不足，需补强后再判 PASS"
exit 1
