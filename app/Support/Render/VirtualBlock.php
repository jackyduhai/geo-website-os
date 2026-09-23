<?php

namespace App\Support\Render;

use App\Support\Blocks\BlockContract;

/**
 * 合成 Block（P-STEP 18G-2）：非持久化、由 Render Context 即时构造。
 * 用于 Detail 固定槽的 Entity 直驱系统块与默认可组合槽；渲染接口与 PageBlock 一致，
 * 由注册渲染器 site/blocks/{type}.blade.php 生成 HTML。
 */
class VirtualBlock implements BlockContract
{
    private static int $seq = 0;

    public readonly string|int $id;

    public function __construct(
        public readonly string $type,
        public readonly array $content = [],
        string|int|null $id = null,
    ) {
        $this->id = $id ?? ('v' . ++self::$seq);
    }

    public function cfg(): array
    {
        return $this->content;
    }
}
