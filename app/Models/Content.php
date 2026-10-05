<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\BelongsToSite;
use App\Support\ContentFieldContract;
use App\Support\PublicUrl;
use App\Support\Translatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 内容（文章 article / 单页 page）
 *
 * 17B 起产品不再是 Content 类型，而是正式 Entity（type=product），由 Catalog 投影。
 *
 * GEO 四层结构以独立字段承载，模板按固定顺序渲染：
 *   结论 → 解释 → 证据 → 边界
 * 这样既能保证 AI 提取的稳定性，也能让答案块成为 DOM 首个区块，方便人扫读。
 */
class Content extends Model
{
    use SoftDeletes, BelongsToSite, Translatable;

    protected $guarded = [];

    /** 内容指纹 Schema 版本（20G-2）。变更投影字段或算法时 +1，旧 hash 整体失效。 */
    public const HASH_SCHEMA_VERSION = 2;

    /** 跨语言共享列（默认语言权威行单向同步）；title/slug/summary/body/GEO 文本按语言独立。 */
    protected static array $sharedTranslatableColumns = [
        'type', 'status', 'published_at', 'category_id', 'group_id', 'cover_id',
        'og_image_id', 'owner', 'reviewed_at', 'review_due', 'source_note',
        'external_id', 'external_source', 'synced_at', 'content_hash',
        'lock_manual', 'slot',
    ];

    /**
     * 全局作用域 not_slot：叙事插槽（contents.slot 非空）是结构化页面的可运营
     * 片段，不是独立内容——默认从搜索、catch-all、后台内容列表、仪表盘计数、
     * sitemap/llms/feed、首页选稿等所有常规查询中排除；仅 Narrative 取数层用
     * withoutGlobalScope('not_slot') 旁路读取。
     */
    protected static function booted(): void
    {
        // 叙事插槽片段（contents.slot 非空）不是独立页面，全站任何 Content 查询默认排除，
        // 防止其泄漏到搜索、catch-all、后台内容列表、仪表盘计数、sitemap/llms/feed 与首页选稿。
        // 需要读取 slot 时显式 Content::withoutGlobalScope('not_slot')。
        // slot 列随建表迁移（2026_09_14_000003）创建，并由 2026_09_17_000002 为存量库补列。
        static::addGlobalScope('not_slot', function ($builder) {
            $builder->whereNull($builder->getModel()->getTable() . '.slot');
        });

        // M-2（与 Entity::deleted 对称）：content_entity / content_tag 都没有 DB 外键
        // 约束（多态关联 + 跨类型引用，无法用 FK 表达），因此**物理删除**内容时必须
        // 显式清理关联行，否则留下永久孤儿：不泄漏前台，但会持续堆积且让
        // 「该内容关联了哪些实体」的计算结果失真。
        //
        // 只在 forceDelete 时清理：软删（deleted_at）保留关联是正确语义——内容随时可恢复，
        // 提前删关联会让恢复后的内容丢失实体指向。Entity 侧是硬删模型故无条件清理。
        static::forceDeleted(function (self $content): void {
            ContentEntity::where('content_id', $content->id)->delete();
            DB::table('content_tag')->where('content_id', $content->id)->delete();
        });
    }

    protected $casts = [
        'geo_evidence'  => 'array',
        'geo_faq'       => 'array',
        'geo_key_facts' => 'array',
        'fact_refs'     => 'array',
        'lock_manual'   => 'boolean',
        'published_at'  => 'datetime',
        'synced_at'     => 'datetime',
        'reviewed_at'   => 'date',
        'review_due'    => 'date',
    ];

    // ---------- 关系 ----------

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** 封面图（媒体库文件）；无封面时列表回退为纯文字卡，不显示破图 */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_image_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->latest();
    }

    /** 标签（多对多，站点隔离；扁平标签，非层级栏目）。 */
    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'content_tag');
    }

    /** Content ↔ Entity 类型化关系行。 */
    public function entityLinks(): HasMany
    {
        return $this->hasMany(ContentEntity::class);
    }

    /** 关联的实体（加载用，按 relation_type 过滤：about 核心，mention 顺带）。 */
    public function relatedEntities(string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        $q = Entity::whereIn('id', $this->entityLinks()
            ->when($type, fn ($w) => $w->where('relation_type', $type))
            ->pluck('entity_id'));
        return $q->get();
    }

    // ---------- 作用域 ----------

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ---------- GEO 辅助 ----------

    /** 四层结构是否完整（门禁的第一道判据） */
    public function hasAnswerStructure(): bool
    {
        return trim((string) $this->geo_conclusion) !== ''
            && trim((string) $this->geo_explanation) !== ''
            && trim((string) $this->geo_boundary) !== ''
            && count($this->evidenceList()) >= 2;
    }

    /** 证据列表，过滤空项 */
    public function evidenceList(): array
    {
        $rows = $this->geo_evidence ?: [];
        return array_values(array_filter($rows, function ($r) {
            return is_array($r) && trim((string) ($r['label'] ?? '')) !== ''
                && trim((string) ($r['value'] ?? '')) !== '';
        }));
    }

    /** FAQ 列表，过滤空项 */
    public function faqList(): array
    {
        $rows = $this->geo_faq ?: [];
        return array_values(array_filter($rows, function ($r) {
            return is_array($r)
                && trim((string) ($r['q'] ?? '')) !== ''
                && trim((string) ($r['a'] ?? '')) !== '';
        }));
    }

    /** 关键事实列表 */
    public function keyFactList(): array
    {
        $rows = $this->geo_key_facts ?: [];
        return array_values(array_filter($rows, function ($r) {
            return is_array($r) && trim((string) ($r['key'] ?? '')) !== ''
                && trim((string) ($r['value'] ?? '')) !== '';
        }));
    }

    // SEO Resolution 已统一收敛至 SeoMetaResolver（5.6-C 冻结契约）；
    // legacy 辅助方法 metaTitle/metaDescription/canonicalUrl 已删除（STEP 08）。

    /**
     * 前台相对路径：/{栏目完整路径}/{slug}，语义化且唯一。
     * 与绝对 URL 分离，便于 PublicUrl 在 HTTP / CLI 两种 host 口径下复用同一条路径。
     */
    public function path(): string
    {
        $segs = [];
        $node = $this->category;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segs, $node->slug);
            $node = $node->parent;
        }

        $segs[] = $this->slug;

        return '/' . implode('/', $segs);
    }

    /**
     * 前台规范绝对地址：/{栏目完整路径}/{slug}（TD-09 经 PublicUrl 裁决 host）。
     * 同时用于 canonical、Article/FAQ JSON-LD、sitemap / llms、面包屑与 301 跳转目标，
     * 必须是站点规范 URL，不能用 url() 跟随临时请求 origin。
     */
    public function url(): string
    {
        return PublicUrl::content($this);
    }

    /** 页面类型到 schema.org 类型的映射（Content 仅 article / page；产品是 Entity） */
    public function schemaType(): string
    {
        return match ($this->type) {
            'page'    => 'WebPage',
            default   => 'Article',
        };
    }

    /**
     * 正文 Markdown 渲染为 HTML（前台唯一出口）。
     * 按「内容 id + 更新时间」缓存解析结果：知识文章正文较长，MISS 渲染可省 200–400ms；
     * 内容一保存 updated_at 即变化，键自然失效，绝不发旧渲染；外层另有整页缓存兜底。
     * html_input=escape：正文来源含后台编辑与 GEOFlow 外部同步，原始 HTML 一律
     * 实体转义而非放行，封死存储型 XSS；与后台预览（mdPreview）保持同一渲染选项。
     */
    public function bodyHtml(): string
    {
        if ($this->body === null || $this->body === '') {
            return '';
        }

        $key = 'content:mdhtml:'.$this->getKey().':'.optional($this->updated_at)->timestamp.':'.strlen($this->body);

        return Cache::store('file')->remember($key, now()->addDays(7), function () {
            return self::renderMarkdown((string) $this->body);
        });
    }

    /**
     * Markdown → HTML 的**唯一**渲染选项（前台正文、后台预览、Narrative 共用）。
     *
     * 安全策略集中于此，业务层不得自行拼 Str::markdown 选项：
     * 两份渲染策略必然分叉，那是 20G-1 收敛前的真实漏洞形态。
     *   - html_input='escape'      原始 HTML 实体转义，封死存储型 XSS
     *   - allow_unsafe_links=false  javascript: 等危险协议不生成可执行链接
     */
    public static function renderMarkdown(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * 内容指纹，供对接幂等判断（20G-2 统一为 Schema v2）。
     *
     * 遍历 ContentFieldContract::hashableFields() 而非手工列举字段：
     * 手工列举会漏字段（历史上只覆盖 7 个，导致 slug / category / geo_faq
     * 等变更被误判为「内容未变化」而静默 skip）。
     *
     * 重要：本方法必须作用在**最终将要持久化的状态**上。若在
     * published_at 等派生默认值填充之前调用，hash 与库中实际值不一致，
     * 同一份 payload 每次推送都会被判「变化」。
     */
    public function computeHash(): string
    {
        $projection = [];

        foreach (ContentFieldContract::hashableFields() as $field) {
            $projection[$field] = $this->getAttribute($field);
        }

        return hash('sha256', json_encode([
            'v' => self::HASH_SCHEMA_VERSION,
            'fields' => $projection,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** 契约自检：hashable / syncable / revisionable 分层不得矛盾。 */
    public static function syncContractConflicts(): array
    {
        return ContentFieldContract::audit();
    }
}
