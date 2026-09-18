<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 首页区块
 *
 * sort 即前台呈现顺序，后台可拖拽调整、单独开关。
 * 这是「官网可独立运维」的体现：改首页结构不需要动代码。
 */
class PageBlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
        'limit'     => 'integer',
        'page'      => 'string',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $block) {
            if ($block->site_id === null && Schema::hasTable('sites')) {
                $block->site_id = Site::defaultId();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPage($query, string $page)
    {
        return $query->where('page', $page)->orderBy('sort');
    }

    /**
     * content 列统一存 JSON 配置。返回解码后的数组（容错空/非 JSON）。
     */
    public function cfg(): array
    {
        $raw = trim((string) $this->content);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** 可增删条目（能力点/车间/流程）：[['icon'=>..,'title'=>..,'text'=>..], ...] */
    public function items(): array
    {
        $items = $this->cfg()['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, fn ($it) => is_array($it) && ! empty($it['title']))) : [];
    }

    /** 数据来源型区块手动指定的内容 ID（空则按来源栏目取最新） */
    public function pickedIds(): array
    {
        $ids = $this->cfg()['ids'] ?? [];

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids))) : [];
    }

    public function kind(): string
    {
        return config('home_blocks.types.' . $this->type . '.kind', 'simple');
    }

    public function typeLabel(): string
    {
        return config('home_blocks.types.' . $this->type . '.label', $this->type);
    }
}
