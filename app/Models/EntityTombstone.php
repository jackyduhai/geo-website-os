<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\BelongsToSite;

/**
 * WP-4 实体墓碑（append-only 身份记录）。
 * ------------------------------------------------------------------
 * Entity physical purge 时保留一条原实体身份快照，供语义引用在实体行已不存在时
 * 判定「KNOWN-DELETED（曾存在，已清除 → WARNING）」vs「UNKNOWN-ID（从未登记 → BLOCK）」。
 *
 * 边界（冻结）：
 *  - 仅作身份判定，不复活实体、不进入 PKS、不充当发现面 / 审计证据；
 *  - 跨站隔离由 BelongsToSite 的 SiteScope 与 (site_id, entity_id) 唯一索引共同保证。
 */
class EntityTombstone extends BaseModel
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'purged_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
