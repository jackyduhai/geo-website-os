<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 栏目
 */
class Category extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_nav' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'parent_id' => 'integer',
    ];

    // ---------- 关系 ----------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class)->orderBy('sort');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    // ---------- 作用域 ----------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInNav($query)
    {
        return $query->where('is_active', true)->where('is_nav', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    // ---------- 辅助 ----------

    /** 栏目前台地址（自动拼接完整层级路径） */
    public function url(): string
    {
        $segs = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segs, $node->slug);
            $node = $node->parent;
        }
        return url('/' . implode('/', $segs) . '/');
    }

    /** 该栏目在导航中的层级深度（0 为顶级） */
    public function depth(): int
    {
        $d = 0;
        $node = $this;
        while ($node->parent_id) {
            $d++;
            $node = $node->parent;
            if ($d > 10) {
                break;
            }
        }
        return $d;
    }

    /** 面包屑：从根到当前 */
    public function breadcrumbs(): array
    {
        $crumbs = [];
        $node = $this;
        while ($node) {
            array_unshift($crumbs, ['name' => $node->name, 'url' => $node->url()]);
            $node = $node->parent;
        }
        return $crumbs;
    }
}
