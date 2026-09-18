<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\BelongsToSite;

class Entity extends Model
{
    use BelongsToSite;

    protected $guarded = [];

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
