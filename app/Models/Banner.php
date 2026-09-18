<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Banner 轮播
 *
 * position 区分投放位置（首页顶部、栏目顶部等），后台可自定义。
 */
class Banner extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
        'target'    => 'integer',
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
    ];

    

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function scopeActive($query)
    {
        $now = now();
        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', $now);
            });
    }

    public function scopeAt($query, string $position)
    {
        return $query->where('position', $position)->orderBy('sort');
    }

    public function imageUrl(): ?string
    {
        return $this->image?->url();
    }
}
