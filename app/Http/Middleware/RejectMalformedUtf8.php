<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 拒绝包含非法 UTF-8 的输入（web + api 全局生效）。
 *
 * 背景：数组字段（geo_evidence / geo_faq）会被 cast 为 JSON 存储，非法
 * UTF-8 让 json_encode 抛 JsonEncodingException → 500。合法浏览器请求
 * 永远是 UTF-8；触发者只会是构造错误的脚本 / 第三方对接方——对它们
 * 422 才是正确契约。
 */
class RejectMalformedUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        // JSON 请求体：Laravel 用无标志的 json_decode 解析，非法 UTF-8 会让整个 payload
        // 解析为 null（$request->input() 为空），导致下面的递归检查“看不到”原始坏字节。
        // 因此对 JSON 请求直接校验原始内容的 UTF-8 合法性，保证编码拦截先于鉴权与控制器。
        if ($request->isJson() && $request->getContent() !== '' &&
            ! mb_check_encoding($request->getContent(), 'UTF-8')) {
            abort(422, 'Request input contains invalid UTF-8.');
        }

        if (! $this->isClean($request->input())) {
            abort(422, 'Request input contains invalid UTF-8.');
        }

        return $next($request);
    }

    /**
     * @param  mixed  $value
     */
    private function isClean(mixed $value): bool
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8');
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (! $this->isClean($v)) {
                    return false;
                }
            }
        }

        return true;
    }
}
