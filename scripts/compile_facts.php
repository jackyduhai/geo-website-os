<?php

/**
 * 事实源编译脚本（可复现构建工具）
 * ------------------------------------------------------------------
 * 输入（全站唯一事实源，交付包）：
 *   Example Website开发交付包/02-data/facts.yaml
 *   Example Website开发交付包/02-data/copy-global.json
 *
 * 输出（Laravel 配置，代码与 Schema 的唯一读取入口）：
 *   config/facts.php   -> config('facts....')
 *   config/copy.php    -> config('copy....')
 *
 * 运行：
 *   php scripts/compile_facts.php [交付包02-data目录]
 *
 * 铁律：页面 / Schema / feeds 一律读 config('facts')，不得再硬编码；
 *      事实更新只改 facts.yaml，然后重跑本脚本。
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root    = dirname(__DIR__);
$dataDir = $argv[1] ?? 'D:/73466/2026-09-15-08-43-26/Example Website开发交付包/02-data';
$dataDir = rtrim(str_replace('\\', '/', $dataDir), '/');

$factsPath = $dataDir . '/facts.yaml';
$copyPath  = $dataDir . '/copy-global.json';

if (! is_file($factsPath)) {
    fwrite(STDERR, "找不到 facts.yaml: {$factsPath}\n");
    exit(1);
}
if (! is_file($copyPath)) {
    fwrite(STDERR, "找不到 copy-global.json: {$copyPath}\n");
    exit(1);
}

// ---------- facts.yaml ----------
$facts = Yaml::parseFile($factsPath, Yaml::PARSE_CUSTOM_TAGS);
if (! is_array($facts)) {
    fwrite(STDERR, "facts.yaml 解析失败\n");
    exit(1);
}

// ---------- copy-global.json（去掉 $ 开头的说明键） ----------
$copy = json_decode((string) file_get_contents($copyPath), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($copy) as $k) {
    if (str_starts_with((string) $k, '$')) {
        unset($copy[$k]);
    }
}

/**
 * 把数组渲染成带缩进的 PHP return 数组（保留 UTF-8 中文可读）。
 */
$render = function (array $data, string $varName, string $source) use (&$render): string {
    $exported = var_export($data, true);
    $header = "<?php\n\n"
        . "/**\n"
        . " * 自动生成，请勿手改。\n"
        . " * 来源：{$source}\n"
        . " * 生成：scripts/compile_facts.php（修改事实后重跑该脚本）\n"
        . " */\n\n"
        . "return ";
    return $header . $exported . ";\n";
};

$factsOut = $root . '/config/facts.php';
$copyOut  = $root . '/config/copy.php';

file_put_contents($factsOut, $render($facts, 'facts', 'facts.yaml'));
file_put_contents($copyOut, $render($copy, 'copy', 'copy-global.json'));

// ---------- 自检统计 ----------
$productCount = is_array($facts['products'] ?? null) ? count($facts['products']) : 0;
$sceneCount   = is_array($facts['scenes'] ?? null) ? count($facts['scenes']) : 0;
$lineCount    = is_array($facts['product_lines'] ?? null) ? count($facts['product_lines']) : 0;

printf(
    "facts.php 已生成：%d 产品体系 / %d 产品 / %d 场景\n",
    $lineCount,
    $productCount,
    $sceneCount
);
echo "copy.php 已生成\n";

// 校验：每个产品的 line 必须存在、related/scenes 引用必须可解析
// 网站 URL 与交叉引用一律以 slug 为键（facts 中 id 与 slug 仅一个产品不同，引用侧统一用 slug）
$lineIds  = array_column($facts['product_lines'], 'slug');
$prodIds  = array_column($facts['products'], 'slug');
$sceneIds = array_column($facts['scenes'], 'slug');
$errors   = [];

foreach ($facts['products'] as $p) {
    if (! in_array($p['line'], $lineIds, true)) {
        $errors[] = "产品 {$p['id']} 的 line={$p['line']} 不在产品体系中";
    }
    foreach (($p['related'] ?? []) as $r) {
        if (! in_array($r, $prodIds, true)) {
            $errors[] = "产品 {$p['id']} related 引用不存在：{$r}";
        }
    }
    foreach (($p['scenes'] ?? []) as $s) {
        if (! in_array($s, $sceneIds, true)) {
            $errors[] = "产品 {$p['id']} scenes 引用不存在：{$s}";
        }
    }
}
foreach ($facts['scenes'] as $s) {
    foreach (($s['combo'] ?? []) as $c) {
        if (! in_array($c, $prodIds, true)) {
            $errors[] = "场景 {$s['id']} combo 引用不存在：{$c}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, "\n[引用校验失败]\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "引用一致性校验通过（line / related / scenes / combo 全部可解析）\n";
exit(0);
