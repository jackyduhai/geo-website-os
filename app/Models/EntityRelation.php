<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\BelongsToSite;
use Illuminate\Support\Facades\Schema;

class EntityRelation extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    // Relation types
    const TYPE_PRODUCES = 'produces';
    const TYPE_OFFERS = 'offers';
    const TYPE_USES = 'uses';
    const TYPE_LOCATED_IN = 'located_in';
    const TYPE_RELATED_TO = 'related_to';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'from_entity_id');
    }

    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'to_entity_id');
    }

    protected static function booted(): void
    {
        // Cross-site protection: both entities must belong to the same site
        static::saving(function (self $relation) {
            if (!Schema::hasTable('entities') || !Schema::hasTable('sites')) {
                return;
            }

            $from = Entity::withoutSiteScope()->find($relation->from_entity_id);
            $to = Entity::withoutSiteScope()->find($relation->to_entity_id);

            if (!$from || !$to) {
                throw new \RuntimeException(
                    "EntityRelation: from_entity_id or to_entity_id not found"
                );
            }

            if ($from->site_id !== $relation->site_id || $to->site_id !== $relation->site_id) {
                throw new \RuntimeException(
                    "Cross-site violation: relation site_id={$relation->site_id} cannot connect entities from different sites"
                );
            }
        });
    }
}
