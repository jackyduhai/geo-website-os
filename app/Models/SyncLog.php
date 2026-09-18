<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GEOFlow 对接日志
 *
 * 每条推送都留痕：新建 / 更新 / 跳过 / 冲突 / 拒绝，含原始报文。
 * 出问题时先看这里，不用翻服务器日志。
 */
class SyncLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days))->latest();
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['warn', 'error']);
    }

    public static function write(string $direction, string $action, array $data = []): self
    {
        return static::create([
            'direction'   => $direction,
            'action'      => $action,
            'external_id' => $data['external_id'] ?? null,
            'content_id'  => $data['content_id'] ?? null,
            'status'      => $data['status'] ?? 'ok',
            'message'     => $data['message'] ?? null,
            'payload'     => $data['payload'] ?? null,
        ]);
    }
}
