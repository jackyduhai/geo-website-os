<?php

namespace App\Support;

/**
 * SafeUrl（P-STEP 18L-4 / TD-135 — P0 Safe Runtime Boundary）。
 * ------------------------------------------------------------------
 * **单一 URL 安全原语**：所有 URL 入口（模板 recipe / 后台 Block / CTA / Menu /
 * Media 链接）在「保存 + 渲染」两侧统一经过本类，拒绝可执行 / 危险 scheme。
 *
 * 允许：
 *   - http:// https:// 及协议相对 //
 *   - 站点内绝对路径 /、页内锚点 #、无 scheme 相对路径 / query
 *   - mailto: tel:（安全联系动作）
 *
 * 拒绝（fail-closed）：
 *   - javascript: vbscript: data: file: 及其他一切自定义 scheme
 *
 * 判定前先归一化（HTML entity 解码 → 去控制字符 / 零宽 / 内嵌空白），
 * 阻断大小写、嵌入 tab / 换行、实体编码等 scheme 绕过。
 *
 * 本类不负责链接的 rel / target（由视图处理），只裁决「URL 是否可安全输出」。
 */
final class SafeUrl
{
    /** 明确允许的 scheme（小写）。 */
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** 危险 scheme（小写；显式列出便于安全日志 / 测试）。 */
    public const DANGEROUS_SCHEMES = ['javascript', 'vbscript', 'data', 'file'];

    /**
     * 判定 URL 是否安全（保存 / 校验 / 渲染通用）。
     */
    public static function isSafe(?string $url): bool
    {
        $normalized = self::normalize($url);
        if ($normalized === '') {
            return false;
        }

        // 协议相对 //（外部跳转）允许
        if (str_starts_with($normalized, '//')) {
            return true;
        }

        // 站内绝对路径 /、页内锚点 # 允许
        if (in_array($normalized[0], ['/', '#'], true)) {
            return true;
        }

        // 提取 scheme（归一化后，字母开头）
        if (! preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $m)) {
            // 无 scheme：相对路径（contact/）、query（?x=1）等允许
            return true;
        }

        return in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true);
    }

    /**
     * 输出用：返回安全 URL，并把握有当前 origin 的**绝对地址转成根相对路径**。
     *
     * 为什么需要（RC-11 实测）：
     *   运营者在区块编辑器里粘贴 `http://localhost/products/` 这类绝对地址后，
     *   部署到真实域名时这些链接会**静默指向错误主机**（前台 CTA /按钮点不动），
     *   而且后台看不出问题 —— 页面能渲染，只是链接是坏的。
     *
     * 处置：同源（scheme+host+port 与当前请求一致）或指向 APP_URL 的绝对地址，
     * 一律降级为 `/path` 根相对路径 —— 换域名零维护，且不会被第三方域名劫持。
     * 跨站外链保持原样（不篡改外部链接）。
     */
    public static function sanitize(?string $url, string $fallback = '#'): string
    {
        $raw = trim((string) $url);

        if (! self::isSafe($raw)) {
            return $fallback;
        }

        return self::relativize($raw);
    }

    /**
     * 同源绝对 URL → 根相对路径；其余原样返回。
     */
    public static function relativize(string $url): string
    {
        if ($url === '' || ! preg_match('~^https?://~i', $url)) {
            return $url;                       // 已是相对路径 / 协议相对 / 非 http
        }

        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $url;
        }

        // 当前请求的 origin
        $currentHost = strtolower((string) request()->getHost());
        if ($currentHost === '') {
            $currentHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        }

        // APP_URL 的 host 也要视作同源（部署时改过 APP_URL 但历史内容仍是旧值）
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $isSameOrigin = $host === $currentHost || $host === $appHost;

        if (! $isSameOrigin) {
            return $url;                       // 外部链接，不动
        }

        $path  = (string) ($parts['path'] ?? '');
        $path  = $path === '' ? '/' : $path;
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $frag  = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $path . $query . $frag;
    }

    /**
     * 保存 / 校验用：返回安全 URL；不安全抛 InvalidArgumentException，
     * 由调用方（Controller / Service）转换为校验错误。
     */
    public static function validate(?string $url): string
    {
        $raw = trim((string) $url);
        if (! self::isSafe($raw)) {
            throw new \InvalidArgumentException('URL contains a disallowed protocol.');
        }

        return $raw;
    }

    /**
     * 归一化（仅用于安全裁决）：
     *   1. 解码 HTML / HTML5 实体（阻断 &#106; / &colon; 等绕过）
     *   2. 删除 C0/C1 控制字符、零宽字符、tab / 换行 / 不间断空格（scheme 内嵌绕过）
     *   3. trim
     */
    public static function normalize(?string $url): string
    {
        $s = (string) $url;

        $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== '') {
            $s = $decoded;
        }

        $s = preg_replace(
            '/[\x00-\x20\x7F\x{00A0}\x{2000}-\x{200F}\x{2028}\x{2029}\x{FEFF}]/u',
            '',
            $s
        ) ?? $s;

        return trim($s);
    }
}
