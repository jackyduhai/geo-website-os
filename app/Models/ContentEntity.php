<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\BelongsToSite;

/**
 * Content ↔ Entity 类型化关系（18R-2c）。
 * relation_type: about（核心产品/行业/场景）/ mention（顺带提及）。
 */
class ContentEntity extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $table = 'content_entity';

    public $timestamps = true;

    public const RELATION_ABOUT = 'about';
    public const RELATION_MENTION = 'mention';

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
