<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 操作日志
 */
class AuditLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'detail' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if ($log->site_id === null && Schema::hasTable('sites')) {
                $log->site_id = Site::defaultId();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

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
