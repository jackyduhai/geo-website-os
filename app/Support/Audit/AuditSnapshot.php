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
        $before = self::normalizeRow($before ?? []);
        $after = self::normalizeRow($after ?? []);

        if ($allowed !== []) {
            $before = array_intersect_key($before, array_flip($allowed));
            $after = array_intersect_key($after, array_flip($allowed));
        } else {
            $before = array_diff_key($before, array_flip(self::NEVER));
            $after = array_diff_key($after, array_flip(self::NEVER));
        }

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $diffBefore = [];
        $diffAfter = [];

        foreach ($keys as $key) {
            $bv = $before[$key] ?? null;
            $av = $after[$key] ?? null;
            if ($bv === $av) {
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
