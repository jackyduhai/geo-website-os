<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * 媒体文件
 */
class Media extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size'   => 'integer',
        'width'  => 'integer',
        'height' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $media) {
            if ($media->site_id === null && Schema::hasTable('sites')) {
                $media->site_id = Site::defaultId();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function url(): string
    {
        if (str_starts_with((string) $this->path, 'http')) {
            return $this->path;
        }
        return asset('storage/' . ltrim((string) $this->path, '/'));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
