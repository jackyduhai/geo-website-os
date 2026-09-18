<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GEOFlow 接口鉴权：Authorization: Bearer <token>
 * Token 在后台「GEO 与对接」中生成与轮换；未配置 token 时一律拒绝。
 */
class VerifyGeoflowToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) Setting::get('sync_geoflow_token', '');
        if ($configured === '') {
            return $this->fail('对接 Token 未配置，接口拒绝访问', 503);
        }

        $presented = (string) $request->bearerToken();
        if (! hash_equals($configured, $presented)) {
            return $this->fail('Token 无效', 401);
        }

        return $next($request);
    }

    protected function fail(string $message, int $status): Response
    {
        return response()->json([
            'ok'      => false,
            'code'    => 'unauthorized',
            'message' => $message,
        ], $status);
    }
}
