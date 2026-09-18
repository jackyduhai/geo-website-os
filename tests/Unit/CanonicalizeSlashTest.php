<?php

namespace Tests\Unit;

use App\Http\Middleware\CanonicalizeSlash;
use Tests\TestCase;

/**
 * 尾斜杠规范化规则单测（纯规则层）。
 *
 * Laravel 功能测试客户端会 trim 掉请求目标的尾斜杠，无法在 Feature 层复现
 * 「目录型补斜杠 / 详情型去斜杠」，故直接对纯方法 targetFor 做断言；
 * 真实 HTTP 行为（浏览器 / curl 保留 REQUEST_URI 尾斜杠）已由 curl 回归覆盖。
 */
class CanonicalizeSlashTest extends TestCase
{
    public function test_directory_url_without_slash_gets_slash(): void
    {
        $this->assertSame('/solutions/', CanonicalizeSlash::targetFor('/solutions', true));
        $this->assertSame('/solutions/fried-chicken-shop/', CanonicalizeSlash::targetFor('/solutions/fried-chicken-shop', true));
    }

    public function test_directory_url_with_slash_is_unchanged(): void
    {
        $this->assertNull(CanonicalizeSlash::targetFor('/solutions/', true));
    }

    public function test_detail_url_with_slash_has_slash_removed(): void
    {
        $this->assertSame('/products/orleans-801', CanonicalizeSlash::targetFor('/products/orleans-801/', false));
    }

    public function test_detail_url_without_slash_is_unchanged(): void
    {
        $this->assertNull(CanonicalizeSlash::targetFor('/products/orleans-801', false));
    }

    public function test_root_never_redirects(): void
    {
        $this->assertNull(CanonicalizeSlash::targetFor('/', true));
        $this->assertNull(CanonicalizeSlash::targetFor('/', false));
    }
}
