#!/usr/bin/env bash
# 20G-3 · FAC 变异测试（断言有效性证明）
# ------------------------------------------------------------------
# 目的：证明 FactLocaleIntegrityTest 的断言**真的有牙**，不是恒真的假阳性。
#
# 20G-8 的教训：旧 G6-RB-005断言「迁移源码含 UNIQUE(site_id, slug)」，
# 而该字符串只出现在注释里 —— 断言恒真，什么都没守住。
# 因此凡新增契约，必须做变异测试：故意破坏，确认断言必然变红。
#
# 用法：bash docs/audit/20G/gates/fac-mutation-test.sh
# 退出码：0 = 全部变异都被捕获（契约有效）

set -u
cd "$(git rev-parse --show-toplevel)" || exit 2

FACT="app/Models/Fact.php"
BACKUP="D:/Temp/_fac_mutation_backup.php"
TEST="tests/Feature/FactLocaleIntegrityTest.php"

cp "$FACT" "$BACKUP" || exit 2

restore() { cp "$BACKUP" "$FACT"; }
trap restore EXIT

# 判定：跑测试，把结论写进 $RESULT
run_test() {
    php -d memory_limit=1G vendor/bin/phpunit "$TEST" --no-coverage > /tmp/_fac_mut.txt 2>&1
    if grep -qE "^(OK|OK, but there were issues)" /tmp/_fac_mut.txt; then
        RESULT="PASS"
    else
        RESULT="FAIL"
    fi
}

PASSED=0
TOTAL=0

mutate() {
    local label="$1"; shift
    local from="$1"; shift
    local to="$1"; shift

    TOTAL=$((TOTAL + 1))
    restore
    python - "$FACT" "$from" "$to" <<'PY'
import io, sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = io.open(path, encoding='utf-8').read()
if old not in s:
    print("MUTATE_TARGET_NOT_FOUND")
    sys.exit(3)
io.open(path, 'w', encoding='utf-8').write(s.replace(old, new, 1))
PY
    if [ $? -ne 0 ]; then
        echo "  [SKIP] $label — 变异目标未找到（代码已变？）"
        return
    fi

    run_test
    if [ "$RESULT" = "FAIL" ]; then
        echo "  [CAUGHT] $label"
        PASSED=$((PASSED + 1))
    else
        echo "  [MISSED] $label  ← 断言没守住，需补强"
    fi
}

echo "== 变异测试开始 =="

# 1) publicRows 去掉 forLocale
mutate "publicRows 去掉 forLocale" \
'            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('"'"'is_public'"'"', true)
                ->orderBy('"'"'sort'"'"')
                ->get();' \
'            self::$memo[$memoKey] = static::query()
                ->where('"'"'is_public'"'"', true)
                ->orderBy('"'"'sort'"'"')
                ->get();'

# 2) publicMap 去掉 forLocale（P1 不可妥协项）
mutate "publicMap 去掉 forLocale" \
'            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('"'"'is_public'"'"', true)
                ->orderBy('"'"'sort'"'"')
                ->pluck('"'"'value'"'"', '"'"'key'"'"')
                ->toArray();' \
'            self::$memo[$memoKey] = static::query()
                ->where('"'"'is_public'"'"', true)
                ->orderBy('"'"'sort'"'"')
                ->pluck('"'"'value'"'"', '"'"'key'"'"')
                ->toArray();'

# 3) publicRows 硬编码默认语言（还原 GEO 缺陷的形态）
mutate "publicRows 硬编码 zh-CN（模拟忽略 locale）" \
'    public static function publicRows(?string $locale = null): Collection
    {
        $locale = self::resolveLocale($locale);' \
'    public static function publicRows(?string $locale = null): Collection
    {
        $locale = '"'"'zh-CN'"'"';'

# 4) memo 不分片（模拟串档）
mutate "memo 去掉 locale 维度（模拟语言串档）" \
"        \$memoKey = 'rows:'.\$locale;" \
"        \$memoKey = 'rows';"

# 5) translation_group 改为随机 UUID（不再按 key 派生）
mutate "translation_group 不再按 key 派生" \
'                $fact->translation_group = self::groupForKey($key);' \
'                $fact->translation_group = (string) \Illuminate\Support\Str::uuid();'

# 6) parent::boot() 提前（trait 的 creating钩子先注册先执行 → UUID 先生成）
#
#    正确写法是「先注册自己的钩子，parent::boot() 放最后」。
#    本变异把 parent::boot() 挪到最前，模拟 20G-3 实测踩到的那个坑：
#    translation_group 拿到 `703e84d7-...` 而不是 `TG-FACT-{key}`。
mutate "parent::boot() 提前（钩子顺序错→ UUID 先生成）" '    protected static function boot(): void
    {
        /**
         * translation_group 按 key 派生确定性值' '    protected static function boot(): void
    {
        parent::boot();
        /**
         * translation_group 按 key 派生确定性值'

echo ""
echo "捕获率: $PASSED / $TOTAL"
if [ "$PASSED" -eq "$TOTAL" ]; then
    echo "✅ 契约有效：全部变异都被捕获"
    exit 0
fi
echo "⚠️  有变异未被捕获 —— 断言强度不足，需补强后再判PASS"
exit 1
