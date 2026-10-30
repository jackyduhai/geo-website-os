<?php
// 校验变异脚本里所有注入片段是否真能在源文件中匹配。
// 用途：避免「注入片段写错」被误判成「断言没守住」（20G-7.1 连续踩了 3 次）。
// 定位项目根：优先用 git，其次回退到 cwd。
// 不用 dirname(__DIR__, N) —— 层数易错（20G-7.1 连续试了 4/5/6 都不对）。
$root = trim((string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' rev-parse --show-toplevel'));
if ($root === '') {
    $root = getcwd();
}
// ⚠️ 必须做换行归一：Windows 上源文件是 CRLF，而注入片段是 LF。
//    变异脚本里已做同样归一；这里也做，否则校验器与实际行为不一致
//    （20G-7.1 连踩 3 次「变异被静默跳过」的坑）。
$raw = file_get_contents($root.'/database/seeders/SiteStructureSeeder.php');
$seeder = str_replace("\r\n", "\n", $raw);

$cases = [
    'M1' => [
        "        \$this->seedCategories();\n        \$this->disableIndustrialNav();\n        \$this->enableBilingual();",
    ],
    'M2' => [
        "            \$parent = Category::firstOrCreate(\n                ['slug' => \$node['slug']],",
    ],
    'M3' => [
        "            \$parent = Category::firstOrCreate(",
    ],
    'M4' => [
        "        if (! in_array('en', \$locales, true)) {\n            \$locales[] = 'en';\n        }",
    ],
    'M5' => [
        "                        'name'      => \$g['name'],\n                        'sort'      => \$g['sort'],",
    ],
];

$bad = 0;
foreach ($cases as $id => $frags) {
    foreach ($frags as $i => $frag) {
        $ok = str_contains($seeder, $frag);
        if (! $ok) {
            $bad++;
        }
        printf("  %s frag%d: %s\n", $id, $i + 1, $ok ? 'OK' : 'MISS');
    }
}

echo $bad === 0
    ? "所有注入片段均可匹配\n"
    : "有 {$bad} 个片段无法匹配 —— 变异会被跳过（SKIP），不是断言问题\n";

exit($bad === 0 ? 0 : 1);
