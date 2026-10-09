<?php

namespace App\Support;

use App\Models\Entity;
use App\Models\EntityTombstone;

/**
 * WP-4 墓碑解析器（身份判定的查询权威）。
 * ------------------------------------------------------------------
 * 仅在「实体行已不存在」时由语义引用校验（WP-3）调用：
 *   - 本站存在墓碑 → KNOWN-DELETED（曾存在，已永久清除 → WARNING）
 *   - 本站无墓碑   → UNKNOWN-ID（从未被系统确认存在 → BLOCK）
 *
 * 跨站隔离：所有查询显式带 site_id（不依赖当前 SiteScope），墓碑绝不跨站命中。
 * AuditLog 是事件证据，不是身份登记；本解析器只认 entity_tombstones 表。
 */
class TombstoneResolver
{
    /** 身份判定常量（仅用于实体行不存在时）。 */
    public const KNOWN_DELETED = 'KNOWN-DELETED';
    public const UNKNOWN_ID = 'UNKNOWN-ID';

    /**
     * 返回 (site_id, entity_id) 对应的墓碑（显式 site 过滤，跨站隔离），
     * 无则 null（UNKNOWN-ID）。
     */
    public function find(int $siteId, int $entityId): ?EntityTombstone
    {
        return EntityTombstone::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('entity_id', $entityId)
            ->first();
    }

    /**
     * 当前站点上下文下查询墓碑。
     */
    public function findForCurrentSite(int $entityId): ?EntityTombstone
    {
        return $this->find(SiteContext::currentSiteId(), $entityId);
    }

    /**
     * 身份判定（仅在实体行已不存在时调用）：
     * 有本站墓碑 → KNOWN-DELETED；否则 UNKNOWN-ID。
     */
    public function classifyMissing(int $siteId, int $entityId): string
    {
        return $this->find($siteId, $entityId) !== null
            ? self::KNOWN_DELETED
            : self::UNKNOWN_ID;
    }

    /**
     * 在 Entity purge 流程中写入一条墓碑（幂等：同一 site+entity 只保留一条）。
     * 返回写入 / 已存在的墓碑。
     */
    public function record(Entity $entity): EntityTombstone
    {
        return EntityTombstone::withoutSiteScope()->updateOrCreate(
            [
                'site_id'   => $entity->site_id,
                'entity_id' => $entity->id,
            ],
            [
                'type'      => $entity->type,
                'slug'      => $entity->slug,
                'name'      => $entity->name,
                'role'      => $entity->role,
                'purged_at' => now(),
                'purged_by' => auth()->id(),
            ]
        );
    }
}
