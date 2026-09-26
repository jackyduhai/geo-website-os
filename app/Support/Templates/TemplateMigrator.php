<?php

namespace App\Support\Templates;

use App\Models\PageBlock;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\SectionSemantic;

/**
 * Template Migrator（P-STEP 18L-4b-3，TD-131 Lite）。
 * --------------------------------------------------
 * 对一组已落地的 {@see PageBlock} 应用 {@see TemplateMigrationRegistry} 声明的
 * 规则。纯结构变换、确定性、幂等（已迁移的 block 再次运行无动作）：
 *
 *   - plan()：生成迁移计划（dry-run），不写库；
 *   - apply()：实际写入 block（type / content），调用方负责事务 / 备份 / 缓存失效。
 *
 * 不变边界：
 *   - 只改 content 的结构键、block type、variant 取值、token 标识符引用；
 *   - 不改业务文本（title / subtitle / label）、不改 URL、不改 SEO / Schema；
 *   - block type 替换后，GEO Section 语义由新 type 经 SectionSemantic 重新派生，
 *     并在计划中标注（这是替换的预期结果，而非隐藏的语义漂移）。
 */
final class TemplateMigrator
{
    /**
     * 生成迁移计划（dry-run，不写库）。
     *
     * @param  iterable<PageBlock>  $blocks
     * @return array{changes:array<int,array>,summary:array<string,int>}
     */
    public static function plan(iterable $blocks, array $rules): array
    {
        $changes = [];
        foreach ($blocks as $pb) {
            $change = self::changeFor($pb, $rules);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return ['changes' => $changes, 'summary' => self::summarize($changes)];
    }

    /**
     * 实际应用迁移（写 block）。变更结构与 plan() 一致。
     * 调用方负责 DB 事务、备份与 PageCache 失效。
     *
     * @param  iterable<PageBlock>  $blocks
     * @return array{changes:array<int,array>,summary:array<string,int>}
     */
    public static function apply(iterable $blocks, array $rules): array
    {
        $changes = [];
        foreach ($blocks as $pb) {
            $change = self::changeFor($pb, $rules);
            if ($change === null) {
                continue;
            }

            if ($change['new_type'] !== null) {
                $pb->type = $change['new_type'];
            }
            $pb->content = json_encode($change['new_content'], JSON_UNESCAPED_UNICODE);
            $pb->save();

            $changes[] = $change;
        }

        return ['changes' => $changes, 'summary' => self::summarize($changes)];
    }

    /** 计算单个 block 的变更（无任何规则命中返回 null）。 */
    private static function changeFor(PageBlock $pb, array $rules): ?array
    {
        $type = (string) $pb->type;
        $workType = $type;
        $cfg = $pb->cfg();
        $newCfg = $cfg;
        $newType = null;
        $actions = [];

        // ① replace_blocks：block type 整体替换（后续字段规则按新 type 处理）。
        foreach ((array) ($rules[TemplateMigrationRegistry::REPLACE_BLOCKS] ?? []) as $rule) {
            if ($workType === (string) ($rule['from'] ?? '')) {
                $newType = (string) ($rule['to'] ?? '');
                $actions[] = "replace block：{$workType} → {$newType}";
                $workType = $newType;
            }
        }

        // ② rename_fields：按（可能已替换后的）block 类型重命名 content 键。
        foreach ((array) ($rules[TemplateMigrationRegistry::RENAME_FIELDS] ?? []) as $rule) {
            if ((string) ($rule['block'] ?? '') !== $workType) {
                continue;
            }
            $fromKey = (string) ($rule['from'] ?? '');
            $toKey = (string) ($rule['to'] ?? '');
            if ($fromKey !== '' && array_key_exists($fromKey, $newCfg)) {
                $newCfg[$toKey] = $newCfg[$fromKey];
                unset($newCfg[$fromKey]);
                $actions[] = "rename field（{$workType}）：{$fromKey} → {$toKey}";
            }
        }

        // ③ rename_variants：按 block 重命名 content.variant 取值。
        foreach ((array) ($rules[TemplateMigrationRegistry::RENAME_VARIANTS] ?? []) as $rule) {
            if ((string) ($rule['block'] ?? '') !== $workType) {
                continue;
            }
            $fromVariant = (string) ($rule['from'] ?? '');
            $toVariant = (string) ($rule['to'] ?? '');
            if (isset($newCfg['variant']) && $newCfg['variant'] === $fromVariant) {
                $newCfg['variant'] = $toVariant;
                $actions[] = "rename variant（{$workType}）：{$fromVariant} → {$toVariant}";
            }
        }

        // ④ rename_tokens：content 中精确等于旧 token 标识符的字符串值替换。
        foreach ((array) ($rules[TemplateMigrationRegistry::RENAME_TOKENS] ?? []) as $rule) {
            $fromToken = (string) ($rule['from'] ?? '');
            $toToken = (string) ($rule['to'] ?? '');
            if ($fromToken !== '') {
                $newCfg = self::replaceTokenValue($newCfg, $fromToken, $toToken, $workType, $actions);
            }
        }

        if ($actions === []) {
            return null;
        }

        return [
            'block_id' => $pb->id,
            'slot' => (string) $pb->slot,
            'old_type' => $type,
            'new_type' => $newType,
            'old_content' => $cfg,
            'new_content' => $newCfg,
            'actions' => $actions,
            'semantic' => self::semanticNote($type, $newType),
        ];
    }

    /**
     * 递归替换 content 中**精确等于**旧 token 名的字符串值（不误伤子串）。
     *
     * @return mixed
     */
    private static function replaceTokenValue(
        mixed $value,
        string $fromToken,
        string $toToken,
        string $blockType,
        array &$actions
    ): mixed {
        if (is_string($value)) {
            if ($value === $fromToken) {
                $actions[] = "rename token（{$blockType}）：{$fromToken} → {$toToken}";

                return $toToken;
            }

            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::replaceTokenValue($v, $fromToken, $toToken, $blockType, $actions);
        }

        return $out;
    }

    /**
     * block type 替换时的 GEO 语义变化说明（旧 / 新 section）；未替换返回 null。
     *
     * @return array{old:array,new:array}|null
     */
    private static function semanticNote(string $oldType, ?string $newType): ?array
    {
        if ($newType === null) {
            return null;
        }

        $oldBlockType = BlockRegistry::get($oldType);
        $newBlockType = BlockRegistry::get($newType);

        $old = $oldBlockType ? array_values(array_filter(SectionSemantic::for($oldBlockType, []))) : [];
        $new = $newBlockType ? array_values(array_filter(SectionSemantic::for($newBlockType, []))) : [];

        return ['old' => $old, 'new' => $new];
    }

    /**
     * 汇总变更计数。
     *
     * @param  array<int,array>  $changes
     * @return array<string,int>
     */
    private static function summarize(array $changes): array
    {
        $summary = [
            'blocks_affected' => count($changes),
            TemplateMigrationRegistry::REPLACE_BLOCKS => 0,
            TemplateMigrationRegistry::RENAME_FIELDS => 0,
            TemplateMigrationRegistry::RENAME_VARIANTS => 0,
            TemplateMigrationRegistry::RENAME_TOKENS => 0,
        ];

        foreach ($changes as $change) {
            foreach ($change['actions'] as $action) {
                if (str_starts_with($action, 'replace block')) {
                    $summary[TemplateMigrationRegistry::REPLACE_BLOCKS]++;
                } elseif (str_starts_with($action, 'rename field')) {
                    $summary[TemplateMigrationRegistry::RENAME_FIELDS]++;
                } elseif (str_starts_with($action, 'rename variant')) {
                    $summary[TemplateMigrationRegistry::RENAME_VARIANTS]++;
                } elseif (str_starts_with($action, 'rename token')) {
                    $summary[TemplateMigrationRegistry::RENAME_TOKENS]++;
                }
            }
        }

        return $summary;
    }
}
