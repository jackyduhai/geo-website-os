<?php

namespace App\Models;

use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoMeta extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'content_id',
        'entity_id',
        'page_id',
        'locale',
        'title',
        'description',
        'keywords',
        'canonical',
        'og_title',
        'og_description',
        'og_image_path',
        'og_type',
        'twitter_card',
        'noindex',
        'nofollow',
        'robots',
        'schema_type',
        'metadata',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'content_id' => 'integer',
        'entity_id' => 'integer',
        'page_id' => 'integer',
        'keywords' => 'array',
        'noindex' => 'boolean',
        'nofollow' => 'boolean',
        'robots' => 'array',
        'metadata' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function isSiteLevel(): bool
    {
        return $this->content_id === null && $this->entity_id === null && $this->page_id === null;
    }

    public function isPageLevel(): bool
    {
        return $this->page_id !== null;
    }

    public function isContentLevel(): bool
    {
        return $this->content_id !== null;
    }

    public function isEntityLevel(): bool
    {
        return $this->entity_id !== null;
    }
}
