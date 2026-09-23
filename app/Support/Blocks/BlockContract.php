<?php

namespace App\Support\Blocks;

/**
 * 可渲染 Block 契约（P-STEP 18G-2）。
 * 持久化块 {@see \App\Models\PageBlock} 与合成系统块 {@see \App\Support\Render\VirtualBlock}
 * 均实现本契约，{@see BlockRegistry::render} 据此统一渲染两类块。
 *
 * 约定：实现者还需提供公共属性 $type（string，块类型 key，对应 config/blocks.php）。
 */
interface BlockContract
{
    /** 块内容（结构化 JSON 解码数组）。 */
    public function cfg(): array;
}
