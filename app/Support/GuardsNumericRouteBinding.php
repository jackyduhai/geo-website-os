<?php

namespace App\Support;

/**
 * 路由隐式绑定的整型主键守卫。
 *
 * 背景（跨库行为漂移）：Laravel 的隐式路由绑定把 URL 片段原样交给模型查询。
 * 当模型主键是整型（keyType=int）而 URL 片段不是合法整数时：
 * - SQLite / MySQL 比较宽松，查不到记录 → 404
 * - PostgreSQL 严格类型转换，抛 SQLSTATE 22P02 → 500
 *
 * 同一请求在不同数据库返回不同状态码，属于真实的跨库语义漂移。
 * 本守卫在进入数据库之前判定「该片段不可能命中整型主键」，
 * 直接按「未找到」处理，与 SQLite / MySQL 的既有行为对齐（404），
 * 同时避免 PostgreSQL 把非法字面量送进类型转换。
 *
 * 判定规则（仅对 keyType=int 的主键生效）：
 * - 显式绑定字段（$field 非空）不走主键判定
 * - 已是整数的原值放行
 * - 字符串须符合 PostgreSQL 的 bigint 输入文法：可选正负号 + 十进制数字，
 *   允许首尾空白；且数值不得超出 bigint 范围（溢出在 PG 属另一种错误）
 * - 其余（字母、小数、科学计数法、空串、超范围）按未找到处理
 *
 * 之所以逐条复刻 PG 文法而不是直接用 filter_var(FILTER_VALIDATE_INT)：
 * 后者会拒绝 '010'、' 10'、'+10' 这类 PG 与 MySQL 都能正常解析的字面量，
 * 那样反而会把「三库一致」改成「三库都不一致」。
 */
trait GuardsNumericRouteBinding
{
    /**
     * 解析路由隐式绑定（非软删除）。
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($this->rejectsRouteBindingValue($value, $field)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * 解析路由隐式绑定（含软删除记录）。
     */
    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        if ($this->rejectsRouteBindingValue($value, $field)) {
            return null;
        }

        return parent::resolveSoftDeletableRouteBinding($value, $field);
    }

    /**
     * 判断该绑定值是否不可能命中当前模型的整型主键。
     */
    protected function rejectsRouteBindingValue(mixed $value, ?string $field): bool
    {
        // 显式绑定到其它字段时，主键类型判定不适用。
        if ($field !== null) {
            return false;
        }

        // 只有整型主键才会因非数字片段产生跨库分歧。
        if ($this->getKeyType() !== 'int') {
            return false;
        }

        if (is_int($value)) {
            return false;
        }

        if (! is_string($value)) {
            return true;
        }

        return ! $this->looksLikeIntegerForDatabase($value);
    }

    /**
     * 判断字符串是否是数据库能安全解析为整数的字面量。
     *
     * 文法与 PostgreSQL 的 bigint 输入一致：
     *   [空白] [+|−] 数字 [空白]
     * 并且数值必须落在 bigint 范围内（PHP_INT_MAX 与 PG bigint 上限一致）。
     */
    protected function looksLikeIntegerForDatabase(string $value): bool
    {
        // PG 接受首尾空白；先剥掉再判定，避免把 ' 10' 误判为非法。
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        // 可选符号 + 纯十进制数字。拒绝小数点、科学计数法、十六进制等。
        if (preg_match('/^[+-]?\d+$/', $trimmed) !== 1) {
            return false;
        }

        // 数值必须落在 bigint 范围内（溢出在 PG 会变成 22003 而非 22P02）。
        $normalized = ltrim($trimmed, '+');
        $negative = str_starts_with($normalized, '-');
        $digits = ltrim($negative ? substr($normalized, 1) : $normalized, '0');

        if ($digits === '') {
            // 值为 0：合法。
            return true;
        }

        $limit = $negative
            ? '9223372036854775808'   // PHP_INT_MIN 的绝对值
            : '9223372036854775807';  // PHP_INT_MAX

        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }

        return strcmp($digits, $limit) <= 0;
    }
}
