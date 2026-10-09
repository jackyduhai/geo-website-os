<?php

namespace App\Services\Gate\Contracts;

/**
 * 语义引用解析契约（WP-3）。
 * ------------------------------------------------------------------
 * ContentGate 依赖本接口而非具体实现，从而门禁只关心「一个语义引用当前指向什么状态」，
 * 不关心状态是如何查出来的。真实实现先查实体行（含软删），行不存在时再查墓碑
 * （WP-4 TombstoneResolver），不以 AuditLog 充当身份登记。
 *
 * 六类身份状态（G-7 五分支在门禁侧归并为 PASS / WARNING / BLOCK）：
 *   VALID          实体行存在、已发布、非 noindex         → PASS
 *   DRAFT          实体行存在、草稿                       → PASS + WARNING
 *   SOFT_DELETED   实体行存在但已软删                     → PASS + WARNING
 *   NOINDEX        实体行存在、已发布但被标记 noindex     → PASS + WARNING
 *   KNOWN_DELETED  实体行不存在、本站有墓碑（曾存在）     → PASS + WARNING
 *   UNKNOWN_ID     实体行不存在、本站无墓碑（从未登记）   → BLOCK
 */
interface SemanticReferenceResolver
{
    public const VALID = 'VALID';
    public const DRAFT = 'DRAFT';
    public const SOFT_DELETED = 'SOFT-DELETED';
    public const NOINDEX = 'NOINDEX';
    public const KNOWN_DELETED = 'KNOWN-DELETED';
    public const UNKNOWN_ID = 'UNKNOWN-ID';

    /**
     * 解析单个语义引用（显式 site 隔离）。
     *
     * @param  int  $siteId    归属站点（引用方所在站）
     * @param  int  $entityId  被引用实体（默认语言锚点）ID
     * @return string 上述六类状态常量之一
     */
    public function resolve(int $siteId, int $entityId): string;
}
