<?php

namespace App\Services\Sync;

use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Group;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\Gate\ContentGate;
use Illuminate\Support\Facades\DB;

/**
 * GEOFlow 内容同步核心
 *
 * 纪律（与架构方案一致）：
 * 1. 总开关关闭 → 接口可达但拒绝写入；
 * 2. 人工锁定（lock_manual）的内容不被上游覆盖，冲突只记录、等人处理；
 * 3. 所有内容必须通过与后台相同的 ContentGate 才能发布；
 * 4. external_id + content_hash 幂等，重复推送不产生重复内容；
 * 5. 每次接收都写 sync_logs，可审计。
 */
class GeoflowSync
{
    /** 允许上游写入的字段白名单 */
    protected const FILLABLE = [
        'type', 'title', 'slug', 'summary', 'body',
        'geo_conclusion', 'geo_explanation', 'geo_evidence', 'geo_boundary',
        'geo_faq', 'geo_key_facts',
        'seo_title', 'seo_desc', 'source_note', 'owner', 'published_at',
        'reviewed_at', 'review_due',
    ];

    public function __construct(protected ContentGate $gate)
    {
    }

    /**
     * 推送/更新单条内容
     *
     * @return array{ok:bool, action:string, status:int, content?:Content, errors?:array, warnings?:array, message?:string}
     */
    public function upsert(array $payload): array
    {
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        if ($externalId === '') {
            return $this->reject('', 'external_id 必填', $payload, 422, 'validation');
        }

        if (Setting::get('sync_geoflow_enabled') !== '1') {
            return $this->reject($externalId, '官网侧 GEOFlow 接收开关未开启，拒绝写入', $payload, 403, 'disabled');
        }

        // 基本合法性
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->reject($externalId, 'title 必填', $payload, 422, 'validation');
        }
        $type = in_array($payload['type'] ?? 'article', ['article', 'page', 'product'], true)
            ? $payload['type'] : 'article';

        return DB::transaction(function () use ($payload, $externalId, $title, $type) {
            $existing = Content::where('external_id', $externalId)->first();

            $attributes = $this->mapPayload($payload, $type, $existing);

            // 新建时 slug 必需（中文标题无法自动转写）
            if (! $existing && empty($attributes['slug'])) {
                return $this->reject($externalId, '新建内容必须提供合法 slug（小写英文/连字符）', $payload, 422, 'validation');
            }

            // 幂等：内容指纹未变化直接跳过，不产生重复写入
            $incomingHash = (new Content($attributes))->computeHash();
            if ($existing && $incomingHash === $existing->content_hash) {
                SyncLog::write('in', 'skip', [
                    'external_id' => $externalId, 'content_id' => $existing->id,
                    'status' => 'ok', 'message' => '内容指纹未变化，跳过', 'payload' => $payload,
                ]);

                return ['ok' => true, 'action' => 'skip', 'status' => 200, 'content' => $existing,
                    'message' => '内容未变化'];
            }

            // 人工锁定：内容有变化时不覆盖，只记录冲突等人处理
            if ($existing && $existing->lock_manual) {
                SyncLog::write('in', 'conflict', [
                    'external_id' => $externalId, 'content_id' => $existing->id,
                    'status' => 'warn', 'message' => '上游内容与人工修改版本冲突，保留人工版本，待人工裁决', 'payload' => $payload,
                ]);

                return ['ok' => false, 'action' => 'conflict', 'status' => 409, 'content' => $existing,
                    'message' => '该内容已被人工修改并锁定，未覆盖；请在后台人工合并'];
            }

            // 门禁（与后台手动发布同一套规则）
            $candidate = new Content(array_merge(
                $existing ? $existing->getAttributes() : [],
                $attributes,
                ['status' => 'published']
            ));
            $result = $this->gate->check($candidate);

            if (! $result['passed']) {
                return $this->reject($externalId, '未通过 GEO 门禁，已拒绝写入', $payload, 422, 'reject', $result['errors']);
            }

            $autoPublish = Setting::get('sync_auto_publish') === '1';
            $attributes['status'] = $autoPublish ? 'published' : 'draft';
            if ($autoPublish && empty($attributes['published_at'])) {
                $attributes['published_at'] = now();
            }
            $attributes['external_source'] = 'geoflow';
            $attributes['synced_at'] = now();

            if ($existing) {
                $existing->fill($attributes);
                $existing->content_hash = $existing->computeHash();
                $existing->save();
                $content = $existing;
                $action = 'update';
            } else {
                $content = new Content($attributes);
                $content->external_id = $externalId;
                $content->content_hash = $content->computeHash();
                $content->save();
                $action = 'create';
            }

            ContentRevision::create([
                'content_id' => $content->id,
                'user_id'    => null,
                'note'       => 'GEOFlow 推送' . ($action === 'create' ? '新建' : '更新'),
                'snapshot'   => $content->only(self::FILLABLE),
            ]);

            SyncLog::write('in', $action, [
                'external_id' => $externalId, 'content_id' => $content->id,
                'status' => 'ok',
                'message' => $autoPublish ? '门禁通过，已发布' : '门禁通过，进入草稿待人工发布',
                'payload' => $payload,
            ]);

            return [
                'ok' => true, 'action' => $action, 'status' => 200, 'content' => $content,
                'warnings' => $result['warnings'],
                'message' => $autoPublish ? '已接收并发布' : '已接收，存为草稿待人工发布',
            ];
        });
    }

    /**
     * 只做门禁预检，不落库
     */
    public function check(array $payload): array
    {
        $attributes = $this->mapPayload($payload, $payload['type'] ?? 'article', null);
        $candidate = new Content(array_merge($attributes, ['status' => 'published']));

        return $this->gate->check($candidate);
    }

    public function unpublish(string $externalId): array
    {
        $content = Content::where('external_id', $externalId)->first();
        if (! $content) {
            return ['ok' => false, 'status' => 404, 'message' => '未找到该外部 ID 对应内容'];
        }
        $content->update(['status' => 'archived', 'synced_at' => now()]);
        SyncLog::write('in', 'unpublish', [
            'external_id' => $externalId, 'content_id' => $content->id,
            'status' => 'ok', 'message' => '上游要求下架，已归档',
        ]);

        return ['ok' => true, 'status' => 200, 'content' => $content, 'message' => '已下架归档'];
    }

    public function status(string $externalId): ?Content
    {
        return Content::where('external_id', $externalId)->first();
    }

    // ---------------------------------------------------------------

    protected function mapPayload(array $payload, string $type, ?Content $existing): array
    {
        $attrs = ['type' => $type];
        foreach (self::FILLABLE as $key) {
            if ($key === 'type' || ! array_key_exists($key, $payload)) {
                continue;
            }
            $attrs[$key] = $payload[$key];
        }

        // 栏目/分组按 slug 解析
        if (array_key_exists('category_slug', $payload)) {
            $attrs['category_id'] = $payload['category_slug']
                ? optional(Category::where('slug', $payload['category_slug'])->first())->id
                : null;
        }
        if (array_key_exists('group_slug', $payload)) {
            $attrs['group_id'] = $payload['group_slug']
                ? optional(Group::where('slug', $payload['group_slug'])->first())->id
                : null;
        }

        // slug 规范化
        if (! empty($attrs['slug'])) {
            $attrs['slug'] = \Illuminate\Support\Str::slug((string) $attrs['slug']);
        } elseif (! $existing) {
            $attrs['slug'] = null;
        }

        // 数组字段兜底
        foreach (['geo_evidence', 'geo_faq', 'geo_key_facts'] as $json) {
            if (isset($attrs[$json]) && is_string($attrs[$json])) {
                $attrs[$json] = json_decode($attrs[$json], true) ?: [];
            }
        }

        return array_filter($attrs, fn ($v) => $v !== null, ARRAY_FILTER_USE_BOTH)
            + ($existing ? $existing->only(['slug', 'category_id', 'group_id']) : []);
    }

    protected function reject(?string $externalId, string $message, array $payload, int $status, string $action, array $errors = []): array
    {
        SyncLog::write('in', $action, [
            'external_id' => (string) $externalId, 'status' => 'error',
            'message' => $message . ($errors ? '：' . implode('；', $errors) : ''),
            'payload' => $payload,
        ]);

        return ['ok' => false, 'action' => $action, 'status' => $status, 'message' => $message, 'errors' => $errors];
    }
}
