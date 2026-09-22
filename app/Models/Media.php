<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use App\Support\PublicUrl;
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



    /**
     * 媒体公开 URL。
     *
     * 本地文件统一经 PublicUrl 裁决绝对 host（TD-09）：真实 HTTP 下等于请求 origin
     * （与 asset() 同源、前台 <img> 仍可达），CLI / 队列 / SubRequest 下回退站点规范
     * domain，避免 og:image / JSON-LD image 在不同入口产出不同 host（http://localhost 分叉）。
     * 外链（http 开头）原样返回。
     */
    public function url(): string
    {
        if (str_starts_with((string) $this->path, 'http')) {
            return $this->path;
        }
        return PublicUrl::base() . '/storage/' . ltrim((string) $this->path, '/');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
