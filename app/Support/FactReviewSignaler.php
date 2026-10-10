<?php

namespace App\Support;

use App\Models\Entity;
use App\Models\FactReviewMarker;

/**
 * WP-8 事实复核信号器（Purge → Re-review signal）。
 * ------------------------------------------------------------------
 * Entity physical purge 时，在 owning site / business-subject 粒度产生一条
 * open marker，并由 AuditLog 记录一次性事件。
 *
 * 能力边界（冻结）：
 *  - 没有 fact_entity_refs，系统不声称能自动定位受影响的具体 Fact；
 *    故当前信号统一落在 site 粒度（scope='site'，scope_key=NULL）。
 *  - marker 是当前复核状态权威；AuditLog 仅事件证据。
 *  - 信号只产生治理状态，绝不删除 / 改写 / 抑制 Fact 行。
 *
 * 幂等：同一 (site, scope, scope_key, triggered_by_entity_id, open) 只保留一条，
 * 重复触发不产生重复 open marker。
 */
class FactReviewSignaler
{
    /**
     * Entity purge 后产生一条 site 粒度 open marker。
     */
    public function signalForPurge(Entity $entity): FactReviewMarker
    {
        return FactReviewMarker::withoutSiteScope()->updateOrCreate(
            [
                'site_id'                  => $entity->site_id,
                'scope'                    => FactReviewMarker::SCOPE_SITE,
                'scope_key'                => null,
                'triggered_by_entity_id'   => $entity->id,
                'status'                   => FactReviewMarker::STATUS_OPEN,
            ],
            [
                'reason' => 'entity_purged:'.$entity->type,
            ]
        );
    }
}
