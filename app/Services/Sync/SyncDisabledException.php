<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * GEOFlow 接收开关未开启（C-2 · Kill Switch）。
 *
 * 语义：这是「运维主动关闭写入能力」的业务拒绝，不是服务端故障。
 * 映射为 HTTP 403 + code=disabled，便于上游区分「被拒绝」与「系统坏了」。
 */
class SyncDisabledException extends RuntimeException
{
    public function errorCode(): string
    {
        return 'disabled';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
