<?php

namespace App\Support\Blocks;

use App\Models\Media;
use App\Models\PageBlock;

/**
 * 组合块媒体引用提取器（B3 第 8 路）。
 * --------------------------------------------------
 * 领域职责：在物理删除 Media 前，反查 PageBlock（页面组合块）中的媒体引用。
 *
 * 确定性提取（不依赖 NLP / 字符串扫描）：
 *  - 遍历 BlockRegistry 声明的字段 schema；
 *  - 顶层 type='media' 字段直接从 cfg 读取；
 *  - type='items' 字段按 item_fields 中 type='media' 逐行读取（含嵌套）。
 *
 * 保守兜底（不确定时保护，宁可不放行）：
 *  - 未注册的 block 类型：递归检索解码后的 JSON，任一值等于该媒体 ID 即视为引用；
 *  - 已注册但存在 schema 之外的 ID 路径（契约外残留）：同样捕获。
 *  - schema 内已知的非媒体字段（如 source.ids、buttons、items 非媒体列）不计入。
 *
 * 覆盖范围：含 inactive（is_active=false）块——其为可恢复的持有态，可再激活，
 * 因此其中的媒体引用同样受删除保护。
 *
 * 本类只读取 PageBlock 并报告引用，不修改任何区块 / 引用方 / 媒体。
 */
class PageBlockMediaRefExtractor
{
    /**
     * 反查某媒体被哪些 PageBlock 引用。
     *
     * @return array<int, array{model: class-string, id: int|string, field: string, label: string}>
     */
    public static function refs(Media $media): array
    {
        $refs = [];
        $id = (int) $media->id;

        // 含 inactive 块（持有态，可再激活）；BelongsToSite 天然按当前站点隔离。
        foreach (PageBlock::get(['id', 'page', 'type', 'is_active', 'content']) as $block) {
            $type = BlockRegistry::get($block->type);
            $cfg = $block->cfg();

            // 递归收集 JSON 中所有「值 == 媒体 ID」的点路径。
            $idPaths = self::collectIdPaths($cfg, $id);
            if ($idPaths === []) {
                continue;
            }

            $mediaPaths = [];
            $residualPaths = [];

            foreach ($idPaths as $path) {
                if ($type === null) {
                    $residualPaths[] = $path;
                    continue;
                }

                $kind = self::classifyPath($path, $type);
                if ($kind === 'media') {
                    $mediaPaths[] = $path;
                } elseif ($kind === 'residual') {
                    $residualPaths[] = $path;
                }
                // 'known'：schema 内非媒体字段（source.ids / buttons / items 非媒体列），忽略。
            }

            // 确定性：每个声明式 media 路径产出一条精确引用。
            foreach (array_unique($mediaPaths) as $path) {
                $refs[] = self::makeRef($block, $type, $path, self::mediaLabel($path, $type));
            }

            // 保守兜底：契约外残留 / 未注册块，每个 block 产出一条。
            if ($residualPaths !== []) {
                $refs[] = self::makeResidualRef($block, $type, $residualPaths);
            }
        }

        return $refs;
    }

    /**
     * 依据 schema 判定一个 ID 路径的性质。
     *
     * @return 'media'|'known'|'residual'
     */
    private static function classifyPath(string $path, BlockType $type): string
    {
        $parts = explode('.', $path);
        $top = $parts[0];
        $field = $type->field($top);

        if ($field === null) {
            return 'residual'; // 顶层键未声明
        }

        $ft = $field['type'] ?? '';

        if ($ft === 'media') {
            return count($parts) === 1 ? 'media' : 'residual';
        }

        if ($ft === 'items') {
            if (count($parts) < 3) {
                return 'known';
            }
            $subKey = $parts[2];
            $itemField = null;
            foreach (($field['item_fields'] ?? []) as $if) {
                if (($if['key'] ?? '') === $subKey) {
                    $itemField = $if;
                    break;
                }
            }
            if ($itemField === null) {
                return 'residual'; // items 行内出现未声明的列
            }
            if (($itemField['type'] ?? '') === 'media') {
                return count($parts) === 3 ? 'media' : 'residual';
            }

            return 'known';
        }

        // scalar（text/number/select/checkbox/textarea/markdown）/ buttons / source：
        // 其内部 ID（如 source.ids、button 索引）均非媒体引用。
        return 'known';
    }

    /** 解析声明式 media 路径的字段标签。 */
    private static function mediaLabel(string $path, BlockType $type): string
    {
        $parts = explode('.', $path);
        $field = $type->field($parts[0]);

        if (count($parts) === 1) {
            return $field['label'] ?? $parts[0];
        }

        // items 嵌套：取 item_fields 中对应列的标签。
        $subKey = $parts[2] ?? '';
        foreach (($field['item_fields'] ?? []) as $if) {
            if (($if['key'] ?? '') === $subKey) {
                return $if['label'] ?? $subKey;
            }
        }

        return $subKey;
    }

    /** 递归收集数组中值等于媒体 ID 的所有点路径。 */
    private static function collectIdPaths(array $data, int $id, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $paths = array_merge($paths, self::collectIdPaths($v, $id, $path));
            } elseif (self::isId($v, $id)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** 判断值是否为目标媒体 ID（容忍 int 或纯数字字符串）。 */
    private static function isId(mixed $v, int $id): bool
    {
        if (is_int($v)) {
            return $v === $id;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v === $id;
        }

        return false;
    }

    private static function makeRef(PageBlock $block, BlockType $type, string $path, string $fieldLabel): array
    {
        return [
            'model' => PageBlock::class,
            'id'    => $block->id,
            'field' => $path,
            'label' => '页面区块「' . $type->label . '」（' . self::loc($block) . '）引用图片：' . $fieldLabel,
        ];
    }

    private static function makeResidualRef(PageBlock $block, ?BlockType $type, array $residual): array
    {
        $where = $type === null
            ? '未注册区块类型「' . $block->type . '」'
            : '区块「' . $type->label . '」存在契约外残留引用（' . implode(', ', array_slice($residual, 0, 3)) . '）';

        return [
            'model' => PageBlock::class,
            'id'    => $block->id,
            'field' => 'content',
            'label' => '页面区块（' . self::loc($block) . '）' . $where,
        ];
    }

    private static function loc(PageBlock $block): string
    {
        return 'page=' . ($block->page ?: '-') . ($block->is_active ? '' : '，未启用');
    }
}
