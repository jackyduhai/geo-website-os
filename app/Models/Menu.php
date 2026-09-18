<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 导航菜单
 *
 * 与栏目的关系：默认导航直接由 categories.is_nav 生成；
 * 需要额外链接或调整顺序时，用本表覆盖。
 */
class Menu extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
        'target'    => 'integer',
        'parent_id' => 'integer',
    ];

    

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAt($query, string $position)
    {
        return $query->where('position', $position)->orderBy('sort');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function link(): string
    {
        if ($this->category) {
            return $this->category->url();
        }
        return $this->url ?: '#';
    }
}
