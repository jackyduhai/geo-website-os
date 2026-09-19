<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * 无鉴权健康检查：供监控与 GEOFlow 探活
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'service' => 'geo-os',
            'time'    => now()->toIso8601String(),
            'db'      => [
                'contents'  => Content::count(),
                'published' => Content::where('status', 'published')->count(),
            ],
            'geoflow' => [
                'push_enabled' => Setting::get('sync_geoflow_enabled') === '1',
                'pull_enabled' => Setting::get('sync_pull_enabled') === '1',
            ],
        ]);
    }
}
