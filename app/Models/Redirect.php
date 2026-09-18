<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 301 / 302 跳转
 *
 * 现站改造后会有一批 URL 变化，全部走这里，避免权重丢失。
 */
class Redirect extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'code'      => 'integer',
        'hits'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $redirect) {
            if ($redirect->site_id === null && Schema::hasTable('sites')) {
                $redirect->site_id = Site::defaultId();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHit($query)
    {
        return $query->increment('hits');
    }
}
