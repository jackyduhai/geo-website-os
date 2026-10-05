<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\GeoflowSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GEOFlow 对接接口（Bearer Token 鉴权见 VerifyGeoflowToken）
 *
 * 响应契约（20G-2 · C-6 统一）：所有出口经 ok()/fail() 收口，结构一致。
 *   成功 { ok:true,  action, code, external_id, request_id, changed_fields, data }
 *   失败 { ok:false, code, message, external_id, request_id, errors }
 *
 * request_id 用于「上游日志 <-> SyncLog <-> HTTP 响应」三方对账：
 * GEOFlow 是外部系统调用，线上排障没有 correlation id 会很痛苦。
 * 优先透传上游的 X-Request-Id，缺失则生成 ULID。
 */
class GeoflowController extends Controller
{
    public function __construct(protected GeoflowSync $sync)
    {
    }

    /** 请求关联 ID：优先透传上游 X-Request-Id，缺失则生成 ULID。 */
    protected function requestId(Request $request): string
    {
        $upstream = (string) $request->header('X-Request-Id', '');

        return $upstream !== '' ? $upstream : (string) Str::ulid();
    }

    /** 成功响应：只暴露契约字段，不返回整个 Eloquent 模型。 */
    protected function ok(Request $request, array $result, ?int $status = null): JsonResponse
    {
        $content = $result['content'] ?? null;

        return response()->json([
            'ok'             => true,
            'action'         => $result['action'] ?? null,
            'code'           => $result['code'] ?? 'ok',
            'external_id'    => $result['external_id'] ?? ($content->external_id ?? null),
            'request_id'     => $this->requestId($request),
            'changed_fields' => $result['changed_fields'] ?? [],
            'message'        => $result['message'] ?? null,
            'errors'         => $result['errors'] ?? [],
            'warnings'       => $result['warnings'] ?? [],
            'data'           => $content ? [
                'id'           => $content->id,
                'external_id'  => $content->external_id,
                'status'       => $content->status,
                'title'        => $content->title,
                'content_hash' => $content->content_hash,
                'url'          => $content->url(),
            ] : null,
        ], $status ?? ($result['status'] ?? 200));
    }

    /**
     * 失败响应：与成功同构。errors 恒为数组（不省略）。
     *
     * action 必须回传：人工锁定冲突返回 409 + action='conflict'、写开关关闭返回
     * 503 + action='disabled'，上游据此区分「重试即可」与「需人工介入」。
     */
    protected function fail(Request $request, string $code, string $message, int $status, array $errors = [], ?string $externalId = null, ?string $action = null): JsonResponse
    {
        return response()->json([
            'ok'          => false,
            'action'      => $action,
            'code'        => $code,
            'message'     => $message,
            'external_id' => $externalId,
            'request_id'  => $this->requestId($request),
            'errors'      => $errors,
            'data'        => null,
        ], $status);
    }

    public function upsert(Request $request): JsonResponse
    {
        $result = $this->sync->upsert($request->json()->all());

        if (($result['ok'] ?? false) !== true) {
            return $this->fail(
                $request,
                (string) ($result['code'] ?? 'sync_failed'),
                (string) ($result['message'] ?? '同步失败'),
                (int) ($result['status'] ?? 422),
                (array) ($result['errors'] ?? []),
                (string) ($result['external_id'] ?? ''),
                $result['action'] ?? null
        );
        }

        return $this->ok($request, $result);
    }

    public function check(Request $request): JsonResponse
    {
        $result = $this->sync->check($request->json()->all());

        // check 是预检：HTTP 恒 200，业务结果在 passed 字段。
        // 但契约失败（422 类，如非法 JSON / 类型越界）仍走 422 通道。
        if (($result['code'] ?? 'ok') !== 'ok' && ($result['passed'] ?? false) === false) {
            return response()->json([
                'ok'         => false,
                'code'       => $result['code'] ?? 'validation',
                'message'    => $result['message'] ?? null,
                'external_id'=> $result['external_id'] ?? null,
                'request_id' => $this->requestId($request),
                'errors'     => $result['errors'] ?? [],
                'data'       => null,
            ], 422);
        }

        return response()->json([
            'ok'           => true,
            'code'         => $result['code'] ?? 'ok',
            'external_id'  => $result['external_id'] ?? null,
            'request_id'   => $this->requestId($request),
            'passed'       => (bool) ($result['passed'] ?? false),
            // check 是只读预检，不受写开关约束；回带当前写入能力供上游自检
            'push_enabled' => (bool) ($result['push_enabled'] ?? false),
            'errors'       => $result['errors'] ?? [],
            'warnings'     => $result['warnings'] ?? [],
        ]);
    }

    public function unpublish(Request $request): JsonResponse
    {
        $externalId = (string) $request->json('external_id', '');
        if ($externalId === '') {
            return $this->fail($request, 'missing_external_id', 'external_id 必填', 422, [], null);
        }
        $result = $this->sync->unpublish($externalId);

        if (($result['ok'] ?? false) !== true) {
            return $this->fail(
                $request,
                (string) ($result['code'] ?? 'unpublish_failed'),
                (string) ($result['message'] ?? '下线失败'),
                (int) ($result['status'] ?? 422),
                (array) ($result['errors'] ?? []),
                $externalId,
                $result['action'] ?? null
            );
        }

        return $this->ok($request, $result);
    }

    public function status(Request $request, string $externalId): JsonResponse
    {
        $content = $this->sync->status($externalId);
        if (! $content) {
            return $this->fail($request, 'not_found', '未找到对应内容', 404, [], $externalId);
        }

        return response()->json([
            'ok'          => true,
            'code'        => 'ok',
            'external_id' => $externalId,
            'request_id'  => $this->requestId($request),
            'data'        => [
                'id'           => $content->id,
                'status'       => $content->status,
                'title'        => $content->title,
                'slug'         => $content->slug,
                'lock_manual'  => (bool) $content->lock_manual,
                'content_hash' => $content->content_hash,
                'synced_at'    => optional($content->synced_at)?->toIso8601String(),
                'updated_at'   => optional($content->updated_at)->toIso8601String(),
            ],
        ]);
    }
}
