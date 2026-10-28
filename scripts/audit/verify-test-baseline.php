<?php

/**
 * GEO-OS 测试计数门禁（verify-test-baseline，只读校验，不改业务数据）。
 * ---------------------------------------------------------------------------
 * 用途：解析 PHPUnit 生成的 JUnit XML，把实际的 tests / assertions / failures /
 *       errors / skipped 与基线文件（scripts/audit/test-baseline.json）做精确比对。
 *
 * 为什么需要它：
 *  - 历史上 release-manifest 把测试数/断言数硬编码（1225/6534），测试规模随开发
 *    变化后会失真；并且没有任何机制能在“测试被意外删除 / 未被发现 / 未执行”时
 *    让 CI 失败。
 *  - 本门禁对 tests、assertions 做**精确相等**判定（不是 >=）：无论新增还是删除
 *    测试，只要实际计数与基线不一致就失败，强制开发者重新执行全量回归并有意更新
 *    基线文件，杜绝“悄悄少了测试却仍然绿灯”。
 *  - failures / errors / skipped 必须为 0（以基线为准）。
 *
 * 判定口径（与 PHPUnit 输出一致）：
 *  - tests     = JUnit XML 中 <testcase> 元素总数（按叶子用例计数，避免嵌套
 *                 testsuite 重复累计）。
 *  - assertions = 所有 <testcase assertions="..."> 之和。
 *  - failures   = 含 <failure> 子节点的 <testcase> 数。
 *  - errors     = 含 <error> 子节点的 <testcase> 数。
 *  - skipped    = 含 <skipped> 子节点的 <testcase> 数。
 *  - risky      = JUnit XML 不直接统计 risky；risky 由 PHPUnit 在 failOnRisky 下
 *                 以非零退出码体现，本脚本不重复统计（见 phpunit.xml / CI）。
 *
 * 用法：
 *   # 门禁模式（CI / 本地）：精确比对基线，退出码 0=通过，1=计数不符
 *   php scripts/audit/verify-test-baseline.php --junit=storage/logs/junit.xml
 *
 *   # 仅导出实际计数 JSON（release job 用它动态填充 manifest，不硬编码）
 *   php scripts/audit/verify-test-baseline.php --junit=... --summary=test-summary.json \
 *       --no-baseline
 *
 *   # 无基线场景（如 PostgreSQL 子集）加测试数量下限，防止空跑假绿
 *   php scripts/audit/verify-test-baseline.php --junit=... --no-baseline --min-tests=170
 *
 * --min-tests 的存在理由：
 *   --no-baseline 模式下原本只导出计数，是否零失败由调用方另行判定。若套件因
 *   未被发现、路径写错、环境缺失而「一条都没跑」，JUnit XML 里 tests=0 且
 *   failures/errors/skipped 全为 0，调用方的零失败判定会把这种空跑判成通过。
 *   下限把「没跑」与「跑了且全过」区分开。下限值由该套件的实测规模决定，
 *   不等于精确比对，允许正常的测试增删。
 *
 * 维护说明（重要）：
 *  1) 增加 / 删除 / 重命名测试，或改变断言行数后，先跑 `php artisan test`
 *     （建议同时 --log-junit=storage/logs/junit.xml）。
 *  2) 确认计数变化是预期的，且没有 skipped/risky/incomplete。
 *  3) 有意更新 scripts/audit/test-baseline.json 的 tests / assertions。
 *  4) 再跑本门禁，确认退出码 0。
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 参数解析
// ---------------------------------------------------------------------------
$opts = [
    'junit'     => null,
    'baseline'  => dirname(__FILE__) . '/test-baseline.json',
    'summary'   => null,
    'no-baseline' => false,
    'min-tests' => null,
];
foreach ($argv as $a) {
    if (str_starts_with($a, '--junit='))    $opts['junit'] = substr($a, 8);
    if (str_starts_with($a, '--baseline=')) $opts['baseline'] = substr($a, 11);
    if (str_starts_with($a, '--summary='))  $opts['summary'] = substr($a, 10);
    if ($a === '--no-baseline')             $opts['no-baseline'] = true;
    if (str_starts_with($a, '--min-tests=')) {
        $rawMin = substr($a, 12);
        if (! ctype_digit($rawMin)) {
            fwrite(STDERR, "[error] --min-tests 需要整数，收到：{$rawMin}\n");
            exit(2);
        }
        $opts['min-tests'] = (int) $rawMin;
    }
}

if ($opts['junit'] === null || ! is_file($opts['junit'])) {
    fwrite(STDERR, "[error] 找不到 JUnit XML：请用 --junit=path/to/junit.xml 指定"
        . "（先用 php artisan test --log-junit=... 生成）。\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// 解析 JUnit XML（按叶子 <testcase> 聚合）
// ---------------------------------------------------------------------------
$xml = @simplexml_load_file($opts['junit']);
if ($xml === false) {
    fwrite(STDERR, "[error] JUnit XML 无法解析：{$opts['junit']}\n");
    exit(2);
}

$actual = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
foreach ($xml->xpath('//testcase') ?: [] as $case) {
    $actual['tests']++;
    $actual['assertions'] += (int) ($case['assertions'] ?? 0);
    // SimpleXML 对缺失子节点返回空元素而非 null，必须用 count() 判断是否存在。
    if (count($case->failure) > 0)  $actual['failures']++;
    if (count($case->error) > 0)    $actual['errors']++;
    if (count($case->skipped) > 0)  $actual['skipped']++;
}

// ---------------------------------------------------------------------------
// 可选：导出实际计数 JSON（供 release job 动态填充 manifest）
// ---------------------------------------------------------------------------
if ($opts['summary'] !== null) {
    $dir = dirname($opts['summary']);
    if (! is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $enc = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($opts['summary'], $enc . "\n");
}

// ---------------------------------------------------------------------------
// 可选：测试数量下限（适用于 --no-baseline 的子集门禁）
// ---------------------------------------------------------------------------
if ($opts['min-tests'] !== null) {
    $min = $opts['min-tests'];
    if ($actual['tests'] < $min) {
        fwrite(STDERR, "[FAIL] 实际执行用例数 {$actual['tests']} 低于下限 {$min}："
            . "空跑、套件未被发现或路径写错都会落到这里。\n");
        exit(1);
    }
    echo "[min-tests] actual={$actual['tests']} >= floor={$min} OK\n";
}

// ---------------------------------------------------------------------------
// 门禁：精确比对基线
// ---------------------------------------------------------------------------
if ($opts['no-baseline']) {
    echo "[summary] tests={$actual['tests']} assertions={$actual['assertions']} "
        . "failures={$actual['failures']} errors={$actual['errors']} skipped={$actual['skipped']}\n";
    exit(0);
}

if (! is_file($opts['baseline'])) {
    fwrite(STDERR, "[error] 找不到基线文件：{$opts['baseline']}\n");
    exit(2);
}

$raw = json_decode((string) file_get_contents($opts['baseline']), true);
if (! is_array($raw)) {
    fwrite(STDERR, "[error] 基线 JSON 无法解析：{$opts['baseline']}\n");
    exit(2);
}

$keys = ['tests', 'assertions', 'failures', 'errors', 'skipped'];
$mismatches = [];
foreach ($keys as $k) {
    $expected = (int) ($raw[$k] ?? 0);
    if ($actual[$k] !== $expected) {
        $mismatches[] = ['key' => $k, 'expected' => $expected, 'actual' => $actual[$k]];
    }
}

echo "GEO-OS 测试计数门禁（基线文件：scripts/audit/test-baseline.json）\n";
echo str_repeat('-', 72) . "\n";
foreach ($keys as $k) {
    $expected = (int) ($raw[$k] ?? 0);
    $mark = $actual[$k] === $expected ? 'OK ' : 'DIFF';
    echo sprintf("  [%s] %-10s expected=%-6d actual=%d\n", $mark, $k, $expected, $actual[$k]);
}

if ($mismatches !== []) {
    echo "\n[FAIL] 实际计数与基线不一致。可能原因：测试被新增/删除/重命名、测试未被发现、"
        . "或断言数发生变化。\n";
    echo "       若为有意变更：重新执行全量回归并有意更新 test-baseline.json；"
        . "若为意外：修复测试发现/执行问题。\n";
    exit(1);
}

echo "\n[PASS] 实际计数与基线完全一致，且无 failures/errors/skipped。\n";
exit(0);
