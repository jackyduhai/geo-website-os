<?php

namespace App\Models;

use App\Support\BelongsToSite;
use App\Support\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 组合页面（Page Composition）。
 * --------------------------------------------------
 * Page 是"页面实例"：属于某 Site、使用某 Template、拥有 slug / 状态 / locale，
 * 并由若干 PageBlock（经 page_id）组合而成。
 *
 * 边界（用户裁定 ①，强制）：Page 只承载页面身份与结构绑定，**不存产品 / 文章 /
 * 组织等业务事实**——业务事实来自 Content / Entity / Media；Page 不是又一套内容表。
 *
 * 多语言：复用 Translatable，同一逻辑页面的中译 / 英译为同 translation_group 的
 * 两行（locale 不同，slug 可不同）；template / is_home / status 为跨语言共享列，
 * 由默认语言权威行单向同步。
 */
class Page extends Model
{
    use BelongsToSite, Translatable;

    protected $guarded = [];

    /** 跨语言共享列（结构 / 状态）；title / slug 按语言独立。 */
    protected static array $sharedTranslatableColumns = ['template', 'is_home', 'status'];

    protected $casts = [
        'is_home' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * 删除页面时级联清理其区块与页面级 SEO（无数据库外键，避免孤儿）。
     * 仅删除该语言 Page 自己的 block / seo；业务事实（Content / Entity / Media）不受影响。
     */
    protected static function booted(): void
    {
        static::deleting(function (Page $page) {
            PageBlock::where('page_id', $page->id)->delete();
            SeoMeta::where('page_id', $page->id)->delete();
        });
    }

    /** 该页面的全部 block（按 sort）。 */
    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('sort');
    }

    /** 启用中的 block。 */
    public function activeBlocks(): HasMany
    {
        return $this->blocks()->where('is_active', true);
    }

    /** 页面级 SEO。 */
    public function seo(): HasOne
    {
        return $this->hasOne(SeoMeta::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeHome($query)
    {
        return $query->where('is_home', true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
