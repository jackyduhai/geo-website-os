<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 内容版本快照
 *
 * 人工修改前自动存一份，GEOFlow 推送覆盖时也留痕，便于回溯。
 */
class ContentRevision extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
    ];

    

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    }
