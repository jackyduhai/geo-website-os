<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\GeoflowSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GEOFlow 对接接口（Bearer Token 鉴权见 VerifyGeoflowToken）
 */
class GeoflowController extends Controller
{
    public function __construct(protected GeoflowSync $sync)
    {
    }

    public function upsert(Request $request): JsonResponse
    {
        $result = $this->sync->upsert($request->json()->all());
        $content = $result['content'] ?? null;

        return response()->json([
            'ok'         => $result['ok'],
            'action'     => $result['action'] ?? null,
            'message'    => $result['message'] ?? null,
            'errors'     => $result['errors'] ?? null,
            'warnings'   => $result['warnings'] ?? null,
            'content'    => $content ? [
                'id'           => $content->id,
                'external_id'  => $content->external_id,
                'status'       => $content->status,
                'title'        => $content->title,
                'content_hash' => $content->content_hash,
                'url'          => $content->url(),
            ] : null,
        ], $result['status'] ?? 200);
    }

    public function check(Request $request): JsonResponse
    {
        return response()->json($this->sync->check($request->json()->all()));
    }

    public function unpublish(Request $request): JsonResponse
    {
        $externalId = (string) $request->json('external_id', '');
        if ($externalId === '') {
            return response()->json(['ok' => false, 'message' => 'external_id 必填'], 422);
        }
        $result = $this->sync->unpublish($externalId);

        return response()->json($result, $result['status']);
    }

    public function status(string $externalId): JsonResponse
    {
        $content = $this->sync->status($externalId);
        if (! $content) {
            return response()->json(['ok' => false, 'message' => '未找到对应内容'], 404);
        }

        return response()->json([
            'ok'             => true,
            'id'             => $content->id,
            'status'         => $content->status,
            'lock_manual'    => $content->lock_manual,
            'content_hash'   => $content->content_hash,
            'synced_at'      => optional($content->synced_at)?->toIso8601String(),
            'updated_at'     => optional($content->updated_at)->toIso8601String(),
        ]);
    }
}
