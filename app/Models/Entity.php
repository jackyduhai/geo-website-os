<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\BelongsToSite;
use App\Support\PageCache;
use App\Support\Translatable;

class Entity extends Model
{
    use BelongsToSite, Translatable;

    protected $guarded = [];

    /** 跨语言共享列（默认语言权威行单向同步）；name/slug/summary/description 按语言独立。 */
    protected static array $sharedTranslatableColumns = [
        'type', 'status', 'published_at', 'metadata', 'sort_order',
    ];

    protected static function booted(): void
    {
        // P-STEP 18C / TD-08b：Entity（产品 / 服务 / 组织 / 地点……）是前台目录、
        // Schema、GEO、Sitemap 的权威数据源（17B 起 Product 正式成为 Entity）。任何
        // 写入 / 删除（后台、tinker、import、Seeder）都必须失效整页静态壳，否则匿名
        // 访客仍命中旧 SSR HTML。与 EntityRelation（18A）对称，挂模型层覆盖全写入路径。
        static::saved(function (self $entity): void {
            PageCache::flush();
        });
        static::deleted(function (self $entity): void {
            PageCache::flush();
        });
    }

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    // Entity types (frozen)
    const TYPE_ORGANIZATION = 'organization';
    const TYPE_PRODUCT = 'product';
    const TYPE_SERVICE = 'service';
    const TYPE_PERSON = 'person';
    const TYPE_LOCATION = 'location';
    const TYPE_TOPIC = 'topic';

    // Status
    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED = 'archived';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function relationsFrom(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'from_entity_id');
    }

    public function relationsTo(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'to_entity_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
