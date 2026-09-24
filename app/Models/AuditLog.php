<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use App\Support\Audit\AuditSnapshot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 操作日志
 */
class AuditLog extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'detail' => 'array',
    ];

    

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        ?string $summary = null,
        array $detail = [],
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        static::create([
            'user_id'     => auth()->id(),
            'action'      => $action,
            'summary'     => $summary,
            'detail'      => $detail ?: null,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'ip'          => request()->ip(),
        ]);
    }

    /**
     * 记录「资源变化」审计（P-STEP 18H-3 / TD-92）：before/after 经 AuditSnapshot
     * 做白名单 / 脱敏 / 归一化，仅保留真正变化字段；无变化不产生日志（避免噪音）。
     *
     * @param array $allowed 允许进入审计的字段白名单
     */
    public static function recordChange(
        string $action,
        string $targetType,
        int $targetId,
        string $summary,
        ?array $before,
        ?array $after,
        array $allowed = []
    ): void {
        $changes = AuditSnapshot::changes($before, $after, $allowed);
        if ($changes === []) {
            return;
        }

        static::record($action, $summary, ['changes' => $changes], $targetType, $targetId);
    }
}
