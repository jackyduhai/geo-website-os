<?php

namespace App\Services\Sync;

use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Group;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\Gate\ContentGate;
use App\Support\ContentFieldContract;
use App\Support\Localization\LocaleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * GEOFlow 内容同步核心（20G-2）
 *
 * 写入纪律：
 *   1. 总开关关闭 → 所有写入口拒绝（不只是 upsert）；
 *   2. 人工锁定（lock_manual）的内容不被上游覆盖，冲突只记录、等人处理；
 *   3. 所有内容必须通过与后台相同的 ContentGate 才能发布；
 *   4. external_id + content_hash 幂等，重复推送不产生重复内容；
 *   5. 每次接收都写 sync_logs，可审计。
 *
 * 字段规则的唯一来源是 ContentFieldContract；本类不定义任何长度 / 枚举 /
 * 格式常量，避免与后台表单形成两份定义（Contract Drift）。
 */
class GeoflowSync
{
    /** 内容字段类型（契约派生，非本类定义） */
    protected const TYPES = ['article', 'page', 'product'];

    /**
     * 响应 action 取值（20G-2 响应契约）。
     *
     * 命名保持与既有接口契约一致（create / update / skip / unpublish），
     * 不做「更优雅」的重命名——action 是上游分支判断的依据，
     * 单方面改字面量等于破坏契约。本类所有返回路径都引用这些常量，
     * 避免同一语义在不同分支出现两种拼写。
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';
    public const ACTION_UNPUBLISH = 'unpublish';
    public const ACTION_CONFLICT = 'conflict';

    public function __construct(protected ContentGate $gate)
    {
    }

    /**
     * 统一写入边界（C-2 · Kill Switch）。
     *
     * 语义：sync_geoflow_enabled 关闭时，所有「具有写能力」的入口都必须拒绝，
     * 而不只是 upsert。历史实现只保护 upsert，导致运维关闭开关后 unpublish
     * 仍可批量下架全站内容——止杀手段在最危险路径上失效。
     */
    public function assertEnabled(): void
    {
        if (Setting::get('sync_geoflow_enabled') !== '1') {
            throw new SyncDisabledException('官网侧 GEOFlow 接收开关未开启，拒绝写入');
        }
    }

    /**
     * 统一输入校验与规范化（C-7 · C-11 · C-12 · Step 3-4）。
     *
     * upsert 与 check 共用本方法，避免两个端点各自理解一套字段语义。
     * 全部非法输入一律 422 拒绝，不做「猜测性降级」。
     *
     * 四态语义（严格区分，勿混）：
     *   missing        字段不存在 → 不修改（返回中不出现该键）
     *   null           显式 null → 清空（仅 nullable=true 的字段允许）
     *   valid value    合法值 → 规范化后返回
     *   invalid value  非法值 → 422（type=null / type=[] / 引用不存在 / 非法 JSON …）
     *
     * 规则全部读自 ContentFieldContract，本方法不定义任何长度 / 枚举常量。
     *
     * @throws SyncValidationException
     */
    public function validateAndNormalizePayload(array $payload): array
    {
        $errors = [];
        $out = [];

        // ---- 0. 契约自检：分类矛盾必须先暴露（开发期fail-fast）----
        foreach (ContentFieldContract::audit() as $problem) {
            $errors[] = '契约定义错误：'.$problem;
        }

        // ---- 1. external_id 必填 + 长度 ----
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        if ($externalId === '') {
            $errors[] = 'external_id 必填';
        } elseif (mb_strlen($externalId) > 128) {
            $errors[] = 'external_id 超长（上限 128）';
        }

        // ---- 2. 逐字段按契约校验 ----
        // category_slug / group_slug 是「引用型」字段：它们不是表列，
        // 只用于解析出 category_id / group_id，绝不能原样进模型。
        $referenceFields = ['category_slug', 'group_slug'];

        foreach (ContentFieldContract::all() as $field => $spec) {
            if (in_array($field, $referenceFields, true)) {
                continue;   // 引用字段在第 5 步单独处理
            }
            if (! array_key_exists($field, $payload)) {
                continue;   // missing → 不修改
            }
            $value = $payload[$field];

            // null 分支：仅 nullable 字段允许清空
            if ($value === null) {
                if (! ($spec['nullable'] ?? false)) {
                    $errors[] = sprintf('字段 %s 不允许为 null（清除该字段请省略它）', $field);
                } else {
                    $out[$field] = null;
                }
                continue;
            }

            // 类型断言（C-11：数组 / 对象不得穿透到模型）
            $isJson = (bool) ($spec['json'] ?? false);
            if ($isJson) {
                if (is_string($value)) {
                    $decoded = json_decode(trim($value), true);
                    if ($value !== '' && ! is_array($decoded)) {
                        $errors[] = sprintf('字段 %s 不是合法 JSON 数组（%s）', $field, json_last_error_msg());
                        continue;
                    }
                    $value = $value === '' ? [] : $decoded;
                } elseif (! is_array($value)) {
                    $errors[] = sprintf('字段 %s 必须是数组或 JSON 字符串', $field);
                    continue;
                }
                $length = mb_strlen(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
            } else {
                if (! is_scalar($value)) {
                    $errors[] = sprintf('字段 %s 必须是标量，收到 %s', $field, get_debug_type($value));
                    continue;
                }
                $length = mb_strlen((string) $value);
            }

            // 长度上限（契约派生）
            $max = $spec['max'] ?? null;
            if ($max !== null && $length > $max) {
                $errors[] = sprintf('字段 %s 超长（%d > %d）', $field, $length, $max);
                continue;
            }

            // 枚举（type 走这里：null 已在上面被拒，数组/对象也已在此被拒）
            if (isset($spec['enums']) && ! in_array($value, $spec['enums'], true)) {
                $errors[] = sprintf('字段 %s 必须是 %s 之一', $field, implode('/', $spec['enums']));
                continue;
            }

            // 日期格式
            if (($spec['type'] ?? null) === 'date' && strtotime((string) $value) === false) {
                $errors[] = sprintf('字段 %s 不是合法日期时间', $field);
                continue;
            }

            // slug 格式（仅 slug 字段有 format）
            if (isset($spec['format']) && ! preg_match($spec['format'], (string) $value)) {
                $errors[] = sprintf('字段 %s 格式非法（应匹配 %s）', $field, $spec['format']);
                continue;
            }

            $out[$field] = $value;
        }

        // ---- 3. type 缺省（非 nullable 字段的缺省与「显式 null」不同）----
        if (! array_key_exists('type', $out)) {
            $out['type'] = array_key_exists('type', $payload) ? null : 'article';
            if ($out['type'] === null) {
                // 上游显式传了 type: null → 上面已记错误，此处仅为避免后续用到 null
                $out['type'] = 'article';
            }
        }

        // ---- 4. title 必填 ----
        if (trim((string) ($out['title'] ?? '')) === '') {
            $errors[] = 'title 必填';
        }

        // ---- 5. 引用型字段：slug → id，解析失败 422（绝不当作清空）----
        foreach (['category_slug' => [Category::class, 'category_id'],
                  'group_slug' => [Group::class, 'group_id']] as $key => [$model, $dest]) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null) {
                $out[$dest] = null;   // 显式 null = 清空归属
                continue;
            }
            if (! is_scalar($value) || trim((string) $value) === '') {
                $out[$dest] = null;
                continue;
            }
            $found = $model::where('slug', trim((string) $value))->first();
            if (! $found) {
                $errors[] = sprintf('%s "%s" 在本站不存在（不视为清空，请检查上游数据）', $key, (string) $value);
                continue;
            }
            $out[$dest] = $found->id;
        }

        if ($errors !== []) {
            throw new SyncValidationException($errors);
        }

        return [
            'external_id' => $externalId,
            // 规范化后的最终属性集（不含系统派生字段，如 status / published_at 默认值）
            'attributes'  => $out,
        ];
    }

    /**
     * 解析「最终将要持久化的状态」（Step 5）。
     *
     * 这是 20G-2 修掉的关键缺陷：指纹必须作用在**最终落库状态**上。
     * 历史实现先算 hash、后填 published_at（auto_publish 的 now()），
     * 导致同一份 payload 第二次推送时 hash 必然不同 → 误判 changed →
     * 每次推送都写 revision + synclog，同步噪音永不收敛。
     *
     * 正确顺序：Merge Existing → Resolve Derived Defaults → Hash → Save
     *
     * @param  array<string,mixed> $normalized validateAndNormalizePayload 的输出
     * @return array{attributes:array, derived:array}
     */
    protected function resolveFinalPersistedState(array $normalized, ?Content $existing): array
    {
        $attrs = $normalized['attributes'];
        $derived = [];
        $autoPublish = Setting::get('sync_auto_publish') === '1';

        // status 由 auto_publish + 门禁推导，属系统派生（不参与 hash）
        $derived['status'] = $autoPublish ? 'published' : 'draft';

        // published_at 的四态规则：
        //   missing + existing 有值 → 保留 existing（不覆盖）
        //   missing + 无值 + autoPublish → 生成一次 now()，作为最终值参与 hash
        //   explicit null           → 清空（并由调用方校验是否允许自动发布）
        //   explicit value          → 使用提供值
        if (! array_key_exists('published_at', $attrs)) {
            if ($existing && $existing->published_at) {
                $attrs['published_at'] = $existing->published_at;
            } elseif ($autoPublish) {
                $attrs['published_at'] = now();
                $derived['published_at_generated'] = true;
            }
        }

        // locale 的三态规则（C-20 · RC-5，与 published_at 同一类问题）：
        //   `contents.locale` 是 **DB 默认值列**（DEFAULT 'zh-CN'）。
        //   payload 通常**不传** locale（单语站点 / 管道不关心语言维度），
        //   若直接让 hash 覆盖它，会出现：
        //     首次推送 → 新建内存模型无该属性 → hash 侧读到 NULL
        //     二次推送 → 从库读回'zh-CN'        → hash 侧读到 'zh-CN'
        //     ⇒ hash 不稳定 ⇒ 幂等 skip 退化为 changed，写 revision + synclog
        //   这与 20G-2 修掉的 published_at 缺陷**完全同型**，
        //   故按同一手法在算 hash 前把最终态解析出来。
        //
        //   missing + existing 有值 → 保留 existing（换语言必须显式声明，
        //     否则一次「漏传字段」就会把已发布内容静默改成默认语言）
        //   missing + 无 existing    → 取站点默认语言
        //   explicit value           → 使用提供值（此时它确实进 hash，
        //     因为「把这条内容改成另一种语言」本身就是内容变更）
        if (! array_key_exists('locale', $attrs) || $attrs['locale'] === null) {
            if ($existing && $existing->locale) {
                $attrs['locale'] = $existing->locale;
            } else {
                $attrs['locale'] = (string) LocaleRegistry::default();
                $derived['locale_defaulted'] = true;
            }
        }

        return ['attributes' => $attrs, 'derived' => $derived];
    }

    /**
     * 推送/更新单条内容
     *
     * @return array{ok:bool, action:string, status:int, content?:Content, errors?:array, warnings?:array, message?:string, changed_fields?:array}
     */
    public function upsert(array $payload): array
    {
        $externalId = trim((string) ($payload['external_id'] ?? ''));

        // C-2：写入能力总开关。放在最前，未开启时任何写入口都拒绝。
        try {
            $this->assertEnabled();
        } catch (SyncDisabledException $e) {
            return $this->reject($externalId, $e->getMessage(), $payload, $e->httpStatus(), $e->errorCode());
        }

        // C-7 / C-11 / C-12：统一输入边界。upsert 与 check 共用同一套规则。
        try {
            $normalized = $this->validateAndNormalizePayload($payload);
        } catch (SyncValidationException $e) {
            return $this->reject($externalId, '载荷校验失败', $payload, $e->httpStatus(), $e->errorCode(), $e->errors());
        }

        $externalId = $normalized['external_id'];

        return DB::transaction(function () use ($payload, $normalized, $externalId) {
            $existing = Content::where('external_id', $externalId)->lockForUpdate()->first();

            // Step 4-5：合并 + 解析派生默认值，得到「最终将要落库的状态」。
            // 必须在算hash 之前完成：否则 auto_publish 补的 published_at
            // 不在 hash 输入里，同一 payload 第二次推送必然 hash 不同 → 误判 changed
            // → 每次推送都写 revision + synclog，同步噪音永不收敛。
            $resolved = $this->resolveFinalPersistedState($normalized, $existing);
            $attributes = $resolved['attributes'];

            if (! empty($attributes['slug'])) {
                $attributes['slug'] = \Illuminate\Support\Str::slug((string) $attributes['slug']);
            }

            // 新建时 slug 必需（中文标题无法自动转写）
            if (! $existing && empty($attributes['slug'])) {
                return $this->reject($externalId, '新建内容必须提供合法 slug（小写英文/连字符）', $payload, 422, 'validation');
            }

            // slug 站内唯一预检（与 DB UNIQUE(site_id, slug, locale) 同口径）：
            // 撞唯一索引会以 SQL 500收场，这里提前给 422。
            //
            // ⚠️ 必须带 locale 维度（C-19 · RC-5 发现）：
            //   20G-3 把行级翻译模型引入 contents 后，DB 约束已是
            //   `UNIQUE(site_id, slug, locale)`，后台 ContentController 的
            //   `Rule::unique('contents','slug')` 也已带 `where('locale', ...)`。
            //   但此处仍是站点级单维判断 → GEOFlow（AI 内容管道的**主入口**）
            //   无法发布 zh/en 同 slug 的双语内容，而后台与 DB 层都允许。
            //   同一slug 的另一语言行不是「冲突」，只是**不同语言维度的另一篇内容**。
            if (! empty($attributes['slug'])) {
                $conflictLocale = $attributes['locale']
                    ?? \App\Support\Localization\LocaleContext::current();
                $conflict = Content::where('slug', $attributes['slug'])
                    ->where('locale', $conflictLocale)
                    ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                    ->first();
                if ($conflict) {
                    return $this->reject($externalId, "slug 冲突：已被本站内容 #{$conflict->id}（{$conflict->slug}）占用", $payload, 422, 'validation');
                }
            }

            // 幂等：指纹作用在最终落库状态上（Step 6-7）。
            // 必须 clone 后 fill，而不是 new Content(原始属性)：
            // 后者会把库内 JSON 字符串二次编码，cast 解一次后仍是字符串。
            $projected = $existing ? clone $existing : new Content();
            $projected->fill($attributes);
            $incomingHash = $projected->computeHash();

            if ($existing && $incomingHash === $existing->content_hash) {
                SyncLog::write('in', 'skip', [
                    'external_id' => $externalId, 'content_id' => $existing->id,
                    'status' => 'ok', 'message' => '内容指纹未变化，跳过', 'payload' => $payload,
                ]);

                return [
                    'ok' => true, 'action' => self::ACTION_SKIP, 'status' => 200,
                    'content' => $existing, 'code' => 'ok', 'changed_fields' => [],
                    'message' => '内容未变化',
                ];
            }

            // 人工锁定：内容有变化时不覆盖，只记录冲突等人处理
            if ($existing && $existing->lock_manual) {
                SyncLog::write('in', 'conflict', [
                    'external_id' => $externalId, 'content_id' => $existing->id,
                    'status' => 'warn', 'message' => '上游内容与人工修改版本冲突，保留人工版本，待人工裁决', 'payload' => $payload,
                ]);

                return [
                    'ok' => false, 'action' => 'conflict', 'code' => 'conflict',
                    'status' => 409, 'content' => $existing, 'errors' => [],
                    'message' => '该内容已被人工修改并锁定，未覆盖；请在后台人工合并',
                ];
            }

            // 门禁（与后台手动发布同一套规则）
            // 门禁（与后台手动发布同一套规则）
            $candidate = $existing ? clone $existing : new Content();
            $candidate->fill($attributes);
            $candidate->status = 'published';
            $result = $this->gate->check($candidate);

            if (! $result['passed'] && $this->gate->isEnforced()) {
                return $this->reject($externalId, '未通过 GEO 门禁，已拒绝写入', $payload, 422, 'reject', $result['errors']);
            }

            // 非强制模式：错误降级为警告（与后台发布同一开关，不留宽松后门分叉）
            $gateWarnings = $result['warnings'];
            if (! $result['passed']) {
                $gateWarnings = array_merge($gateWarnings, array_map(fn ($e) => '[门禁未强制] '.$e, $result['errors']));
            }

            // status / published_at 已在 resolveFinalPersistedState() 中落定，
            // 此处只补系统生成字段（不参与 hash）。
            $attributes['status'] = $resolved['derived']['status'];
            $attributes['external_source'] = 'geoflow';
            $attributes['synced_at'] = now();

            // changed_fields：本次真正变动的受管字段，供上游对账（C-4 配套）
            $hashable = ContentFieldContract::hashableFields();
            $changedFields = $existing
                ? array_values(array_filter(
                    $hashable,
                    fn ($f) => $existing->getAttribute($f) != ($attributes[$f] ?? $existing->getAttribute($f))
                ))
                : $hashable;

            if ($existing) {
                $existing->fill($attributes);
                $existing->content_hash = $existing->computeHash();
                $existing->save();
                $content = $existing;
                $action = self::ACTION_UPDATE;
            } else {
                // C-4 并发兜底（Step 11）：DB 唯一约束只保证「第二个最终失败」，
                // 业务层必须把失败翻译成契约内结果，而不是让 QueryException 冒泡成 500。
                //
                // 注意：唯一键冲突 ≠ 幂等命中。冲突后必须 reload 并比较
                // canonical hash：payload 相同才是幂等 skip；不同则说明
                // 两个并发推送携带了不同内容，应走 update/conflict 语义。
                try {
                    $content = new Content($attributes);
                    $content->external_id = $externalId;
                    $content->content_hash = $content->computeHash();
                    $content->save();
                    $action = self::ACTION_CREATE;
                } catch (\Illuminate\Database\QueryException $e) {
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    $winner = Content::where('external_id', $externalId)->lockForUpdate()->first();

                    // reload 后重算：这次针对的是「已落库的真实状态」
                    $replay = $winner ? clone $winner : new Content();
                    $replay->fill($attributes);
                    $replayHash = $replay->computeHash();
                    $sameAsWinner = $winner && $replayHash === $winner->content_hash;

                    if ($sameAsWinner) {
                        SyncLog::write('in', 'skip', [
                            'external_id' => $externalId, 'content_id' => $winner->id,
                            'status' => 'warn',
                            'message' => '并发重复推送，命中唯一约束且内容一致，按幂等处理',
                            'payload' => $payload,
                        ]);

                        return [
                            'ok' => true, 'action' => self::ACTION_SKIP, 'code' => 'ok', 'status' => 200,
                            'content' => $winner, 'changed_fields' => [],
                            'message' => '并发重复推送，内容已存在且一致',
                        ];
                    }

                    // 内容不同：不能静默skip。若赢家已被人工锁定 → conflict；
                    // 否则按 update 处理（保持与 upsert 主路径一致）。
                    if ($winner?->lock_manual) {
                        SyncLog::write('in', 'conflict', [
                            'external_id' => $externalId, 'content_id' => $winner->id,
                            'status' => 'warn',
                            'message' => '并发推送内容不一致，且目标已被人工锁定，保留人工版本',
                            'payload' => $payload,
                        ]);

                        return [
                            'ok' => false, 'action' => 'conflict', 'code' => 'conflict', 'status' => 409,
                            'content' => $winner, 'errors' => [],
                            'message' => '并发推送内容不一致，且该内容已被人工锁定，未覆盖',
                        ];
                    }

                    $winner->fill($attributes);
                    $winner->content_hash = $winner->computeHash();
                    $winner->save();
                    ContentRevision::create([
                        'content_id' => $winner->id,
                        'user_id'    => null,
                        'note'       => 'GEOFlow 并发更新',
                        'snapshot'   => $winner->only(ContentFieldContract::revisionableFields()),
                    ]);
                    SyncLog::write('in', 'updated', [
                        'external_id' => $externalId, 'content_id' => $winner->id,
                        'status' => 'warn',
                        'message' => '并发推送内容不一致，已按后到者更新',
                        'payload' => $payload,
                    ]);

                    return [
                        'ok' => true, 'action' => self::ACTION_UPDATE, 'code' => 'ok', 'status' => 200,
                        'content' => $winner, 'changed_fields' => $hashable,
                        'warnings' => $gateWarnings,
                        'message' => '并发推送，已更新既有内容',
                    ];
                }
            }

            ContentRevision::create([
                'content_id' => $content->id,
                'user_id'    => null,
                'note'       => 'GEOFlow 推送' . ($action === self::ACTION_CREATE ? '新建' : '更新'),
                'snapshot'   => $content->only(ContentFieldContract::revisionableFields()),
            ]);

            $willPublish = ($resolved['derived']['status'] ?? 'draft') === 'published';

            SyncLog::write('in', $action, [
                'external_id' => $externalId, 'content_id' => $content->id,
                'status' => 'ok',
                'message' => $willPublish ? '门禁通过，已发布' : '门禁通过，进入草稿待人工发布',
                'payload' => $payload,
            ]);

            return [
                'ok' => true, 'action' => $action, 'code' => 'ok', 'status' => 200,
                'content' => $content,
                'external_id' => $externalId,
                'warnings' => $gateWarnings,
                'changed_fields' => $changedFields,
                'message' => $willPublish ? '已接收并发布' : '已接收，存为草稿待人工发布',
            ];
        });
    }

    /** 判断 QueryException 是否为唯一键冲突（并发重复推送） */
    protected function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * 只做门禁预检，不落库。
     *
     * C-7：与 upsert 共用 validateAndNormalizePayload()，
     * 不再直接读 $payload['type']，避免数组/对象值触发 TypeError → 500。
     */
    public function check(array $payload): array
    {
        // check 是**只读预检**，不写库，因此不受 sync_geoflow_enabled 约束。
        // 开关的语义是「关闭写入能力」，而非「关闭一切可访问性」——
        // GEOFlow 上线前要反复调预检，若被开关拦住就无法自检。
        // 但响应中回带 push_enabled，让上游能感知当前写入能力状态。
        try {
            $normalized = $this->validateAndNormalizePayload($payload);
        } catch (SyncValidationException $e) {
            return [
                'ok' => false, 'passed' => false, 'code' => $e->errorCode(),
                'errors' => $e->errors(), 'warnings' => [],
                'push_enabled' => Setting::get('sync_geoflow_enabled') === '1',
                'message' => '载荷校验失败',
            ];
        }

        // 门禁预检：与 upsert 走同一套规范化结果，避免两端语义漂移。
        $candidate = new Content();
        $candidate->fill($normalized['attributes']);
        $candidate->status = 'published';

        $result = $this->gate->check($candidate);

        return array_merge([
            'ok' => true,
            'code' => $result['passed'] ? 'ok' : 'gate_rejected',
            'push_enabled' => Setting::get('sync_geoflow_enabled') === '1',
        ], $result);
    }

    /**
     * 上游要求下架。
     *
     * C-2：受总开关保护——「关闭 GEOFlow」必须关闭全部写能力，而非只关 upsert。
     * C-6：受 lock_manual 保护——lock_manual=1 表示「已被人工接管」，
     *      自动同步不得改status；若确需强制下架，应走后台人工操作并留AuditLog。
     * Revision：status 虽非正文，但改变了前台事实可见性，必须留快照以便回滚与追溯。
     */
    public function unpublish(string $externalId): array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return $this->reject('', 'external_id 必填', [], 422, 'validation');
        }

        try {
            $this->assertEnabled();
        } catch (SyncDisabledException $e) {
            return $this->reject($externalId, $e->getMessage(), [], $e->httpStatus(), $e->errorCode());
        }

        $content = Content::where('external_id', $externalId)->first();
        if (! $content) {
            return $this->reject($externalId, '未找到该外部 ID 对应内容', [], 404, 'not_found');
        }

        if ($content->lock_manual) {
            SyncLog::write('in', 'conflict', [
                'external_id' => $externalId, 'content_id' => $content->id,
                'status' => 'warn',
                'message' => '内容已被人工锁定，上游请求下架已拒绝',
            ]);

            return [
                'ok' => false, 'action' => 'conflict', 'status' => 409, 'content' => $content,
                'code' => 'conflict',
                'message' => '该内容已被人工修改并锁定，未下架；请在后台人工处理',
            ];
        }

        $before = ['status' => $content->status, 'synced_at' => optional($content->synced_at)?->toIso8601String()];

        $content->update(['status' => 'archived', 'synced_at' => now()]);
        $content->content_hash = $content->computeHash();
        $content->saveQuietly();

        ContentRevision::create([
            'content_id' => $content->id,
            'user_id'    => null,
            'note'       => 'GEOFlow 下架',
            'snapshot'   => [
                'external_id' => $externalId,
                'before'      => $before,
                'after'       => ['status' => 'archived'],
                'source'      => 'geoflow',
                'reason'      => '上游要求下架',
                'at'          => now()->toIso8601String(),
            ],
        ]);

        SyncLog::write('in', 'unpublish', [
            'external_id' => $externalId, 'content_id' => $content->id,
            'status' => 'ok', 'message' => '上游要求下架，已归档',
        ]);

        return [
            'ok' => true, 'action' => self::ACTION_UNPUBLISH, 'status' => 200,
            'content' => $content, 'code' => 'ok',
            'changed_fields' => ['status'],
            'message' => '已下架归档',
        ];
    }

    public function status(string $externalId): ?Content
    {
        return Content::where('external_id', trim($externalId))->first();
    }

    // ---------------------------------------------------------------
    // 注：原 mapPayload() 已移除。其四态语义（missing / null / valid / invalid）
    // 现由 validateAndNormalizePayload() 统一实现——它是唯一的输入边界，
    // upsert 与 check 共用。保留两个入口正是 Contract Drift 的温床：
    // 历史上 mapPayload 用 array_filter 丢弃 null（C-5），
    // 而 check 又绕过它直接读 $payload['type']（C-7），两处语义不一致。
    // ---------------------------------------------------------------

    /**
     * 统一失败响应（C-6 · 响应契约）。
     *
     * 所有拒绝路径都经本方法，保证：
     *   - 必有 ok=false 与机器可读 code；
     *   - 必留external_id，上游能确认是哪条内容被拒；
     *   - 必写 SyncLog，可审计。
     */
    protected function reject(?string $externalId, string $message, array $payload, int $status, string $action, array $errors = []): array
    {
        SyncLog::write('in', $action, [
            'external_id' => (string) $externalId, 'status' => 'error',
            'message' => $message . ($errors ? '：' . implode('；', $errors) : ''),
            'payload' => $payload,
        ]);

        return [
            'ok' => false,
            'action' => $action,
            'code' => $action,
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
            'external_id' => (string) $externalId,
        ];
    }
}
