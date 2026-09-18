<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 内容版本快照
 *
 * 人工修改前自动存一份，GEOFlow 推送覆盖时也留痕，便于回溯。
 */
class ContentRevision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $revision) {
            if ($revision->site_id === null && Schema::hasTable('sites')) {
                $revision->site_id = Site::defaultId();
            }
        });
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
