<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 301 / 302 跳转
 *
 * 现站改造后会有一批 URL 变化，全部走这里，避免权重丢失。
 */
class Redirect extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'code'      => 'integer',
        'hits'      => 'integer',
    ];

    

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHit($query)
    {
        return $query->increment('hits');
    }
}
