<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\BelongsToSite;

/**
 * WP-8 事实复核 marker（当前复核状态权威）。
 * ------------------------------------------------------------------
 * Entity physical purge 后，在 owning site / business-subject 粒度产生一条 marker，
 * 表示「该 scope 的站点事实需要人工重新核定」。
 *
 * 职责边界（冻结）：
 *  - marker 是当前复核状态的唯一权威：可查询该 scope 是否存在未决（open）复核；
 *  - AuditLog 只记录一次性事件（purge / 信号 / 复核完成），不用于反推当前状态；
 *  - marker 不删除 / 不改写 / 不抑制 Fact 行，不改变 Fact 的 is_public；
 *  - 没有 fact_entity_refs，不声称能自动定位受影响的具体 Fact；
 *  - 人工复核完成后将 status 置为 resolved（并写 resolved_at）。
 */
class FactReviewMarker extends BaseModel
{
    use BelongsToSite;

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public const SCOPE_SITE = 'site';
    public const SCOPE_BUSINESS_SUBJECT = 'business_subject';

    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
        'triggered_by_entity_id' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resolve(): void
    {
        if ($this->status === self::STATUS_OPEN) {
            $this->forceFill([
                'status' => self::STATUS_RESOLVED,
                'resolved_at' => now(),
            ])->save();
        }
    }
}
