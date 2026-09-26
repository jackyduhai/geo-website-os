<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\BelongsToSite;

/**
 * 标签（18R-2c Content Hub Lite）。
 * 站点隔离的扁平标签；与层级栏目 Category 正交，不作为独立 GEO 实体。
 */
class Tag extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    public $timestamps = true;

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_tag');
    }
}
