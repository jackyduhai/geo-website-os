<?php

namespace App\Support;

/**
 * 内容字段契约（20G-2 · Step 1）
 * ==================================================================
 * 规则的**唯一来源**。后台表单（ContentController::validateForm）与
 * GEOFlow 接口（GeoflowSync::validateAndNormalizePayload）都必须从这里读，
 * 不得各自硬编码长度 / 枚举 / 格式。
 *
 * 为什么必须抽这一层（20G-2 的核心目标）：
 *   过去两边各写一份「title max 200」。某次只改了 Admin 侧，GEOFlow 侧
 *   仍是旧值 —— 这就是 Contract Drift：规则从Controller 搬到 Service
 *   只是换了个地方，只有单一规则源才能真正消灭它。
 *
 * 每个字段的契约维度：
 *   type        PHP 类型（string / int / array / date）
 *   nullable    是否允许显式 null（清空）；false 时传 null 属非法
 *   max         长度或数值上限（null = 不限）
 *   format      正则或枚举（enum 时用 enums 数组）
 *   normalizer  规范化回调（null = 不处理）
 *   hashable    是否参与 GEOFlow 内容指纹
 *   revisionable是否进入 Revision 快照
 *   syncable    是否允许 GEOFlow 写入
 */
final class ContentFieldContract
{
    /**
     * 字段契约表。
     *
     * hashable 决定 GEOFlow 指纹覆盖范围 —— 判定标准是「该字段是否构成
     * 这条内容的对外事实」。系统生成字段（synced_at / status 等）不在此表，
     * 见 Content::SYNC_GENERATED_FIELDS。
     *
     * @var array<string,array<string,mixed>>
     */
    private const FIELDS = [
        // ---------- 内容事实 ----------
        'type' => [
            'type' => 'string', 'nullable' => false, 'max' => 20,
            'enums' => ['article', 'page', 'product'],
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
            // type 不是 nullable 字段：传 null 属非法（区别于「省略」）
        ],
        'title' => [
            'type' => 'string', 'nullable' => true, 'max' => 200,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'slug' => [
            'type' => 'string', 'nullable' => true, 'max' => 200,
            'format' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        // C-20 · RC-5 发现：GEOFlow 契约原本**没有 locale**，
        // 导致该字段在 normalize 阶段被直接丢弃 ——
        // 于是所有 GEOFlow 写入的内容都落成 DB 默认 `locale='zh-CN'`，
        // AI 内容管道（产品的主入口）**根本无法发布非默认语言的内容**，
        // 且与 slug 预检叠加后，同一 slug 的 zh/en 两版会互相冲突。
        //
        // 枚举取 LocaleRegistry::supported()，不硬编码语言码：
        // 与 SetLocale 门禁、`site_supported_locales` 同一事实源。
        //
        //⚠️ 不设 'format' 正则：语言码形如 `zh-CN` / `en` / `zh-TW`，
        //   含连字符且大小写有语义，正则化会误拒合法值。
        //   合法性由 enums 兜底（不在 supported 列表 → 422 校验失败）。
        //
        //   enums 不写死在 const 里：PHP 的 const 数组不能调用静态方法，
        //   而语言列表的事实源是 `localization.supported` 配置，
        //   写死会与 SetLocale 门禁 / site_supported_locales 产生第二份事实源。
        //   故在 all() 中运行时注入。
        'locale' => [
            'type' => 'string', 'nullable' => true, 'max' => 16,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'summary' => [
            'type' => 'string', 'nullable' => true, 'max' => 1000,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'body' => [
            'type' => 'string', 'nullable' => true, 'max' => 200000,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],

        // ---------- 结构归属（由 slug 引用解析，不直接写 id）----------
        'category_slug' => [
            'type' => 'string', 'nullable' => true, 'max' => 200,
            'hashable' => false, 'revisionable' => false, 'syncable' => true,
            'note' => '解析为 category_id；解析失败必须 422，不得当作清空',
        ],
        'group_slug' => [
            'type' => 'string', 'nullable' => true, 'max' => 200,
            'hashable' => false, 'revisionable' => false, 'syncable' => true,
            'note' => '解析为 group_id；解析失败必须 422',
        ],

        // 解析后的 id 才是真正落库并参与指纹的字段。
        // 换栏目 = 内容结构事实变化，必须体现在 hash 里，
        // 否则上游把文章从 A 栏目移到 B 栏目会被判为「无变化」而静默 skip。
        'category_id' => [
            'type' => 'int', 'nullable' => true, 'max' => null,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
            'derived_from' => 'category_slug',
        ],
        'group_id' => [
            'type' => 'int', 'nullable' => true, 'max' => null,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
            'derived_from' => 'group_slug',
        ],

        // ---------- GEO 事实 ----------
        'geo_conclusion' => [
            'type' => 'string', 'nullable' => true, 'max' => 2000,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'geo_explanation' => [
            'type' => 'string', 'nullable' => true, 'max' => 8000,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'geo_evidence' => [
            'type' => 'array', 'nullable' => true, 'max' => 8000,
            'json' => true,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'geo_boundary' => [
            'type' => 'string', 'nullable' => true, 'max' => 4000,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'geo_faq' => [
            'type' => 'array', 'nullable' => true, 'max' => 20000,
            'json' => true,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'geo_key_facts' => [
            'type' => 'array', 'nullable' => true, 'max' => 8000,
            'json' => true,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],

        // ---------- 责任与来源 ----------
        'owner' => [
            'type' => 'string', 'nullable' => true, 'max' => 60,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'source_note' => [
            'type' => 'string', 'nullable' => true, 'max' => 255,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],

        // ---------- 时效 ----------
        'published_at' => [
            'type' => 'date', 'nullable' => true, 'max' => null,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
            'note' => '省略=不修改；null=清空；系统补的 now() 必须在 hash 之前落定',
        ],
        'reviewed_at' => [
            'type' => 'date', 'nullable' => true, 'max' => null,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
        'review_due' => [
            'type' => 'date', 'nullable' => true, 'max' => null,
            'hashable' => true, 'revisionable' => true, 'syncable' => true,
        ],
    ];

    /**
     * 状态字段：参与 Revision 快照但**不参与内容指纹**。
     *
     * 为什么要分开：status 改变前台可见性（是重大事实变更，必须留痕），
     * 但它由 auto_publish + 门禁推导，不是外部传入的内容事实。
     * 若纳入 hash，每次同步都会因状态流转而误判 changed。
     *
     * @var array<int,string>
     */
    private const STATE_FIELDS = ['status', 'lock_manual', 'auto_publish'];

    /** 审计字段：进入 Revision 快照用于追溯，同样不参与 hash */
    private const AUDIT_FIELDS = ['external_id', 'external_source', 'request_id', 'actor'];

    public static function all(): array
    {
        $fields = self::FIELDS;

        // C-20：locale 的合法值集合在运行时从配置解析 ——
        // const 数组无法调用静态方法，且语言列表会随站点配置变化，
        // 写死会与 SetLocale 门禁 / site_supported_locales 产生第二份事实源。
        if (isset($fields['locale'])) {
            $supported = \App\Support\Localization\LocaleRegistry::supported();
            if ($supported !== []) {
                $fields['locale']['enums'] = $supported;
            }
        }

        return $fields;
    }

    public static function has(string $field): bool
    {
        return isset(self::FIELDS[$field]);
    }

    public static function get(string $field): ?array
    {
        return self::FIELDS[$field] ?? null;
    }

    public static function maxOf(string $field): ?int
    {
        return self::FIELDS[$field]['max'] ?? null;
    }

    public static function isNullable(string $field): bool
    {
        return (bool) (self::FIELDS[$field]['nullable'] ?? false);
    }

    public static function isJson(string $field): bool
    {
        return (bool) (self::FIELDS[$field]['json'] ?? false);
    }

    public static function isHashable(string $field): bool
    {
        return (bool) (self::FIELDS[$field]['hashable'] ?? false);
    }

    /** 参与 GEOFlow 写入的字段（slug 引用字段也含在内） */
    public static function syncableFields(): array
    {
        return array_keys(array_filter(self::FIELDS, fn ($d) => $d['syncable'] ?? false));
    }

    /** 参与 GEOFlow 内容指纹的字段（hashable ∩ syncable） */
    public static function hashableFields(): array
    {
        return array_keys(array_filter(
            self::FIELDS,
            fn ($d) => ($d['hashable'] ?? false) && ($d['syncable'] ?? false)
        ));
    }

    /** 参与 Revision 快照的字段（内容 + 状态 + 审计） */
    public static function revisionableFields(): array
    {
        $content = array_keys(array_filter(self::FIELDS, fn ($d) => $d['revisionable'] ?? false));

        return array_values(array_unique(array_merge($content, self::STATE_FIELDS, self::AUDIT_FIELDS)));
    }

    public static function stateFields(): array
    {
        return self::STATE_FIELDS;
    }

    public static function auditFields(): array
    {
        return self::AUDIT_FIELDS;
    }

    /**
     * Laravel 验证规则片段（供后台表单复用同一份长度/枚举定义）。
     * 后台可在自己的规则里叠加 unique / exists / required 等站点相关约束，
     * 但长度与枚举必须来自这里，不得硬编码。
     */
    public static function laravelRules(string $field): array
    {
        $d = self::FIELDS[$field] ?? null;
        if ($d === null) {
            return ['nullable'];
        }

        $rules = [];
        $type = $d['type'] ?? 'string';

        $rules[] = $type === 'array' ? 'array' : ($type === 'date' ? 'date' : 'string');

        if (($d['max'] ?? null) !== null) {
            $rules[] = 'max:'.$d['max'];
        }
        if (isset($d['enums'])) {
            $rules[] = 'in:'.implode(',', $d['enums']);
        }
        if (isset($d['format'])) {
            $rules[] = 'regex:'.$d['format'];
        }

        return $rules;
    }

    /**
     * 契约自检：确保分类互不矛盾、syncable ⊇ hashable。
     * 供测试调用，避免后续新增字段时破坏分层。
     */
    public static function audit(): array
    {
        $problems = [];

        $hashable = self::hashableFields();
        $syncable = self::syncableFields();

        foreach ($hashable as $f) {
            if (! in_array($f, $syncable, true)) {
                $problems[] = "字段 {$f} 标为 hashable 但未标syncable，语义矛盾";
            }
        }

        foreach (self::FIELDS as $name => $d) {
            if (($d['nullable'] ?? false) && isset($d['enums']) && $name === 'type') {
                $problems[] = 'type 不得为 nullable（null 与「省略」语义必须区分）';
            }
        }

        $revision = self::revisionableFields();
        foreach (self::STATE_FIELDS as $s) {
            if (! in_array($s, $revision, true)) {
                $problems[] = "状态字段 {$s} 必须进入 Revision 快照";
            }
        }

        return $problems;
    }
}
