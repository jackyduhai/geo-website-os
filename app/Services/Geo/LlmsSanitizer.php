<?php

namespace App\Services\Geo;

/**
 * llms.txt 输出终洗器（20F-HAT P1-3）。
 * ------------------------------------------------------------------
 * 原则与前台 Blade 的空数据守卫（RP-001）一致：
 *   有事实 → 输出；无事实 → 整行删除。
 *   AI 面向输出绝不出现：0 值统计（「0大车间」「0个生产车间」）、
 *   空键值行（「- 厂区面积：」）、悬挂标点（「：。」「。。」）。
 *
 * Builder 负责在组装期只产出有事实的行（片段化拼接）；本类是最终防线，
 * 用确定性正则兜底，防止未来模板改动重新引入污染——不做任何「智能」改写。
 *
 * 规则说明：
 *  - 零值统计对全部行生效（含链接行）。误伤概率：文章标题恰好含「0个/0大」
 *    等组合——属可接受的极小概率，代价远小于把「0个生产车间」喂给 AI。
 */
class LlmsSanitizer
{
    /** 模板计数断言中的零值（0大 / 0个 / 0种 / 0步 / 0类 / 0项）。 */
    private const ZERO_COUNT = '/(?<!\d)0(?:大|个|种|步|类|项)(?!\d)/u';

    public function clean(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);

            // 先判空值行（「- 键：」），再修悬挂标点——顺序反了会把空值行洗成无冒号标签残留
            if ($line !== '' && $this->isEmptyValueLine($line)) {
                continue;
            }

            $line = $this->normalizePunctuation($line);

            if ($line === '') {
                // 空行只保留一个（折叠连续空行）
                if ($out === [] || end($out) !== '') {
                    $out[] = '';
                }
                continue;
            }

            if (preg_match(self::ZERO_COUNT, $line) === 1) {
                continue;
            }

            $out[] = $line;
        }

        // 去尾部空行
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out) . "\n";
    }

    /** 悬挂标点修复：空值冒号、重复标点、行尾悬挂分隔符。 */
    private function normalizePunctuation(string $line): string
    {
        $line = str_replace(['：。', '。。', '、、', '，，', '：，'], ['。', '。', '、', '，', '，'], $line);
        // 行尾悬挂分隔符（冒号 / 顿号 / 逗号 / 分号）——空值行由此转空再被丢弃
        $line = preg_replace('/[：:、，,；;]\s*$/u', '', trim($line)) ?? $line;

        return $line;
    }

    /** 空键值行（「- 键：」）：剥掉 Markdown 链接后只剩标签没有值。 */
    private function isEmptyValueLine(string $line): bool
    {
        $visible = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $line) ?? $line;

        return preg_match('/^[^：:]+[：:]\s*$/u', trim($visible)) === 1;
    }
}
