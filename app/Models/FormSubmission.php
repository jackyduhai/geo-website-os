<?php

namespace App\Models;

use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 表单提交（P-STEP 18H-2）——完整事实源。
 * --------------------------------------------------
 * payload(JSON) 保存全部业务字段原始结构；归因列记录首次落地 / 外部来源 / UTM /
 * 设备（服务端 UA）。Inquiry 由其投影（inquiry()），字段有限也不丢数据。
 */
class FormSubmission extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public const STATUS_NEW      = 'new';
    public const STATUS_READ     = 'read';
    public const STATUS_ARCHIVED = 'archived';

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** 投影出的留言（一条提交至多一条）。 */
    public function inquiry(): HasOne
    {
        return $this->hasOne(Inquiry::class, 'submission_id');
    }

    public function payloadArray(): array
    {
        return is_array($this->payload) ? $this->payload : [];
    }
}
