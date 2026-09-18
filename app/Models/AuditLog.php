<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
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
}
