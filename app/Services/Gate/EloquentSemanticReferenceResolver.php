<?php

namespace App\Services\Gate;

use App\Models\Entity;
use App\Models\SeoMeta;
use App\Services\Gate\Contracts\SemanticReferenceResolver;
use App\Support\TombstoneResolver;

/**
 * 语义引用解析的具体实现（WP-3）。
 * ------------------------------------------------------------------
 * 判定顺序（G-7 五分支）：
 *   1. 实体行存在（含软删，显式 site 隔离）：
 *        软删（deleted_at 非空）              → SOFT_DELETED
 *        草稿（status=draft）                 → DRAFT
 *        已发布但被 SeoMeta 标记 noindex      → NOINDEX
 *        已发布且非 noindex                   → VALID
 *   2. 实体行不存在 → 交给 WP-4 TombstoneResolver：
 *        本站有墓碑（曾存在，已永久清除）      → KNOWN_DELETED
 *        本站无墓碑（从未被系统确认存在）      → UNKNOWN_ID
 *
 * 边界：只查实体表与墓碑表，不解析自由文本、不以 AuditLog 充当身份登记；
 * 墓碑不复活实体、不进入 PKS。
 */
class EloquentSemanticReferenceResolver implements SemanticReferenceResolver
{
    public function __construct(protected TombstoneResolver $tombstones)
    {
    }

    public function resolve(int $siteId, int $entityId): string
    {
        // 含软删 + 显式 site 隔离（不依赖当前 SiteScope）。
        $entity = Entity::withoutSiteScope()
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('id', $entityId)
            ->first();

        if ($entity !== null) {
            // 软删优先：一个被软删的行，无论其原 status 如何，都属 SOFT_DELETED。
            if ($entity->trashed()) {
                return self::SOFT_DELETED;
            }

            if ($entity->status === Entity::STATUS_DRAFT) {
                return self::DRAFT;
            }

            if ($this->isNoindex($siteId, $entityId)) {
                return self::NOINDEX;
            }

            return self::VALID;
        }

        // 行不存在：凭墓碑区分「已知曾存在但已清除」与「从未登记」。
        return $this->tombstones->classifyMissing($siteId, $entityId) === TombstoneResolver::KNOWN_DELETED
            ? self::KNOWN_DELETED
            : self::UNKNOWN_ID;
    }

    /**
     * 实体是否被 SeoMeta 标记 noindex（站点隔离）。
     * 实体 noindex 的唯一来源是 SeoMeta.entity_id + noindex（与 PublicIndex 同口径）。
     */
    protected function isNoindex(int $siteId, int $entityId): bool
    {
        return SeoMeta::withoutSiteScope()
            ->where('site_id', $siteId)
            ->where('entity_id', $entityId)
            ->where('noindex', true)
            ->exists();
    }
}
