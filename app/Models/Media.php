<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 媒体文件
 */
class Media extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'size'   => 'integer',
        'width'  => 'integer',
        'height' => 'integer',
    ];

    

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
