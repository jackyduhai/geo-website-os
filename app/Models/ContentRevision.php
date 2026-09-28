<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 内容版本快照
 *
 * 人工修改前自动存一份，GEOFlow 推送覆盖时也留痕，便于回溯。
 */
class ContentRevision extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
    ];

    

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * 触发该版本的用户（人工修改）。
     *
     * user_id 可为 null（种子 / 系统 / GEOFlow 生成的版本），withDefault 兜底
     * 避免视图访问 $revision->user->name 时因关系缺失或用户不存在而 500。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)
            ->withDefault(['name' => '系统']);
    }

    }
