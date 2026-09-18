<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\BelongsToSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * 分组（栏目内的二级归集）
 */
class Group extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected static function booted(): void
    {
        // Cross-site isolation: group's category must belong to the same site
        static::creating(function (self $group) {
            if ($group->category_id !== null && Schema::hasTable('sites')) {
                $category = Category::withoutSiteScope()->find($group->category_id);
                if ($category && $category->site_id !== $group->site_id) {
                    throw new \RuntimeException(
                        "Cross-site violation: group site_id={$group->site_id} cannot reference category_id={$group->category_id} belonging to site_id={$category->site_id}"
                    );
                }
            }
        });

        static::updating(function (self $group) {
            // Cross-site isolation on update: prevent changing category to another site's category
            if ($group->isDirty('category_id') && $group->category_id !== null && Schema::hasTable('sites')) {
                $category = Category::withoutSiteScope()->find($group->category_id);
                if ($category && $category->site_id !== $group->site_id) {
                    throw new \RuntimeException(
                        "Cross-site violation: group site_id={$group->site_id} cannot reference category_id={$group->category_id} belonging to site_id={$category->site_id}"
                    );
                }
            }
        });
    }

    /** 请求级缓存：知识子栏目在一个请求内被控制器/导航/sitemap/llms 多处复用 */
    private static ?Collection $knowledgeMemo = null;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 知识中心启用中的子栏目（category=knowledge、is_active，按 sort）。
     * 这是知识频道、主菜单下拉、sitemap、llms.txt 的唯一数据来源，禁止再写死三栏目。
     */
    public static function knowledgeChannels(): Collection
    {
        if (self::$knowledgeMemo === null) {
            $cat = Category::where('slug', 'knowledge')->first();
            self::$knowledgeMemo = $cat
                ? self::where('category_id', $cat->id)->where('is_active', true)->orderBy('sort')->get()
                : collect();
        }

        return self::$knowledgeMemo;
    }

    /** 后台写入分组后调用，保证同进程不读到旧值 */
    public static function flushKnowledgeMemo(): void
    {
        self::$knowledgeMemo = null;
    }
}
