<?php

namespace App\Support\Audit;

/**
 * 审计安全快照（P-STEP 18H-3 / TD-92）。
 *
 * AuditLog 不是数据库镜像：before/after 必须经过
 *   whitelist（只记录允许字段）→ redact（敏感值脱敏）→ normalize（限长 / 类型标记），
 * 且只保留真正发生变化的字段。
 *
 * 明确不记录：password / api token / secret / smtp 密码 / 第三方 secret /
 * 表单提交 payload / 隐私字段；数组 / 对象只记录类型与大小，不展开内容。
 */
class AuditSnapshot
{
    /** 字段名命中（子串）即脱敏。 */
    public const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'private_key',
        'api_key', 'apikey', 'smtp', 'credentials', '_key',
    ];

    /** 即使变化也不进入审计。 */
    public const NEVER = ['remember_token', 'email_verified_at', 'updated_at', 'created_at'];

    public const MAXLEN = 500;

    /**
     * @param array $allowed 字段白名单；为空时用行内键（仍脱敏 / 剔除 NEVER）
     * @return array{before:array, after:array}，无变化返回 []
     */
    public static function changes(?array $before, ?array $after, array $allowed = []): array
    {
        $rawBefore = $before ?? [];
        $rawAfter = $after ?? [];
        $normBefore = self::normalizeRow($rawBefore);
        $normAfter = self::normalizeRow($rawAfter);

        if ($allowed !== []) {
            $rawBefore = array_intersect_key($rawBefore, array_flip($allowed));
            $rawAfter = array_intersect_key($rawAfter, array_flip($allowed));
            $normBefore = array_intersect_key($normBefore, array_flip($allowed));
            $normAfter = array_intersect_key($normAfter, array_flip($allowed));
        } else {
            $rawBefore = array_diff_key($rawBefore, array_flip(self::NEVER));
            $rawAfter = array_diff_key($rawAfter, array_flip(self::NEVER));
            $normBefore = array_diff_key($normBefore, array_flip(self::NEVER));
            $normAfter = array_diff_key($normAfter, array_flip(self::NEVER));
        }

        $keys = array_unique(array_merge(
            array_keys($rawBefore),
            array_keys($rawAfter),
            array_keys($normBefore),
            array_keys($normAfter)
        ));

        $diffBefore = [];
        $diffAfter = [];

        foreach ($keys as $key) {
            $bv = $normBefore[$key] ?? null;
            $av = $normAfter[$key] ?? null;
            // 变化判定以「原始值」为准：否则纯敏感字段脱敏后 before/after 同为
            // [REDACTED]，会被误判无变化而漏审计（TD-98）。
            if ($bv === $av && self::rawSame($rawBefore[$key] ?? null, $rawAfter[$key] ?? null)) {
                continue;
            }
            $diffBefore[$key] = $bv;
            $diffAfter[$key] = $av;
        }

        if ($diffBefore === [] && $diffAfter === []) {
            return [];
        }

        return ['before' => $diffBefore, 'after' => $diffAfter];
    }

    /** 原始（未脱敏）值是否相同；数组 / 对象松散比较，标量转字符串比较。 */
    private static function rawSame($a, $b): bool
    {
        if (is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return $a == $b;
        }

        return (string) $a === (string) $b;
    }

    private static function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $lk = strtolower($key);

            if (is_array($value)) {
                $out[$key] = '[array:'.count($value).']';

                continue;
            }
            if (is_object($value)) {
                $out[$key] = '['.class_basename($value).']';

                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (is_null($value)) {
                $out[$key] = null;

                continue;
            }

            $value = (string) $value;
            if (self::isSensitive($lk)) {
                $out[$key] = '[REDACTED]';

                continue;
            }
            if (mb_strlen($value) > self::MAXLEN) {
                $value = mb_substr($value, 0, self::MAXLEN).'…';
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function isSensitive(string $lowerKey): bool
    {
        foreach (self::SENSITIVE as $needle) {
            if (strpos($lowerKey, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
