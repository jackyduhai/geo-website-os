<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 客户留言/询盘
 */
class Inquiry extends Model
{
    use BelongsToSite;
    protected $guarded = [];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    /** 来源提交（投影关系；历史 / 兼容留言为空）。 */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    /** 来源表单。 */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public const STATUS_LABEL = [
        'new'      => '待跟进',
        'handled'  => '已跟进',
        'archived' => '已归档',
    ];

    public const DEVICE_LABEL = [
        'mobile'  => '手机',
        'tablet'  => '平板',
        'desktop' => '电脑',
        'bot'     => '爬虫/其他',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABEL[$this->status] ?? $this->status;
    }

    public function deviceLabel(): string
    {
        return self::DEVICE_LABEL[$this->device_type] ?? ($this->device_type ?: '未知');
    }

    /**
     * 以服务端 UA 解析设备类型（不信任前端传值）。
     */
    public static function deviceFromUserAgent(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return 'desktop';
        }
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/(mobile|iphone|ipod|android.*mobile|harmony|blackberry|windows phone)/i', $ua)) {
            return 'mobile';
        }
        if (preg_match('/(bot|crawler|spider|slurp)/i', $ua)) {
            return 'bot';
        }

        return 'desktop';
    }
}
