<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * GEOFlow 载荷校验失败（C-5 · C-7 · C-11 · C-12）。
 *
 * 语义：这是「上游数据不符合契约」的业务拒绝，不是服务端故障。
 * 映射为 HTTP 422 + code=validation，errors 数组给出字段级原因，
 * 让上游能定位到具体哪个字段不合法，而不是收到一个泛化的 500。
 */
class SyncValidationException extends RuntimeException
{
    /** @param array<int,string> $errors 字段级错误列表 */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode('；', $errors));
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function errorCode(): string
    {
        return 'validation';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
