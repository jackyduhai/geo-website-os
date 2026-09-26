<?php

namespace Tests\Unit;

use App\Support\SafeUrl;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * TD-135 Safe Runtime Boundary — SafeUrl scheme 白名单防回归。
 *
 * 第三方 / AI 模板、后台 Block、菜单等为不可信输入，必须经 SafeUrl 裁决：
 * 允许 http(s)/mailto/tel 与站内相对 URL，拒绝 javascript/data/vbscript/file，
 * 并阻断大小写、HTML 实体、嵌入空白、零宽字符等绕过。
 */
class SafeUrlTest extends TestCase
{
    public function test_allowed_schemes_pass(): void
    {
        foreach (['http://example.com', 'https://example.com/x', 'mailto:a@example.com', 'tel:+8613800000000'] as $u) {
            $this->assertTrue(SafeUrl::isSafe($u), "should allow: {$u}");
        }
    }

    public function test_dangerous_schemes_are_rejected(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
        ] as $u) {
            $this->assertFalse(SafeUrl::isSafe($u), "should reject: {$u}");
        }
    }

    public function test_scheme_is_case_insensitive(): void
    {
        $this->assertFalse(SafeUrl::isSafe('JaVaScRiPt:alert(1)'));
        $this->assertFalse(SafeUrl::isSafe('DATA:text/plain,x'));
    }

    public function test_html_entity_encoded_scheme_is_rejected(): void
    {
        $this->assertFalse(SafeUrl::isSafe('&#106;avascript:alert(1)'));
        $this->assertFalse(SafeUrl::isSafe('java&#115;cript:alert(1)'));
        $this->assertFalse(SafeUrl::isSafe('javascript&colon;alert(1)'));
    }

    public function test_embedded_whitespace_is_stripped_then_judged(): void
    {
        $this->assertFalse(SafeUrl::isSafe("java\tscript:alert(1)"));
        $this->assertFalse(SafeUrl::isSafe("java\nscript:alert(1)"));
        $this->assertFalse(SafeUrl::isSafe('  javascript:alert(1)'));
        $this->assertFalse(SafeUrl::isSafe("javascript\u{00A0}:alert(1)"));
    }

    public function test_zero_width_bypass_is_rejected(): void
    {
        $this->assertFalse(SafeUrl::isSafe("java\u{200B}script:alert(1)"));
        $this->assertFalse(SafeUrl::isSafe("\u{FEFF}javascript:alert(1)"));
    }

    public function test_relative_protocol_relative_and_anchor_pass(): void
    {
        foreach (['/products/', '#section', '//example.com/x', 'products/list', '?q=1'] as $u) {
            $this->assertTrue(SafeUrl::isSafe($u), "should allow: {$u}");
        }
    }

    public function test_empty_value_is_unsafe(): void
    {
        $this->assertFalse(SafeUrl::isSafe(''));
        $this->assertSame('#', SafeUrl::sanitize(''));
    }

    public function test_sanitize_returns_fallback_for_dangerous(): void
    {
        $this->assertSame('#', SafeUrl::sanitize('javascript:alert(1)'));
        $this->assertSame('/safe', SafeUrl::sanitize('javascript:alert(1)', '/safe'));
        $this->assertSame('/products/', SafeUrl::sanitize('/products/'));
    }

    public function test_validate_throws_for_dangerous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SafeUrl::validate('javascript:alert(1)');
    }

    public function test_validate_returns_value_for_safe(): void
    {
        $this->assertSame('/products/', SafeUrl::validate('/products/'));
    }
}
