<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\BelongsToSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 内容（文章 / 单页 / 产品）
 *
 * GEO 四层结构以独立字段承载，模板按固定顺序渲染：
 *   结论 → 解释 → 证据 → 边界
 * 这样既能保证 AI 提取的稳定性，也能让答案块成为 DOM 首个区块，方便人扫读。
 */
class Content extends Model
{
    use SoftDeletes, BelongsToSite;

    protected $guarded = [];

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

    /** 前台地址：/{栏目完整路径}/{slug}，语义化且唯一 */
    public function url(): string
    {
        return url($this->path());
    }

    /** 页面类型到 schema.org 类型的映射 */
    public function schemaType(): string
    {
        return match ($this->type) {
            'product' => 'Product',
            'page'    => 'WebPage',
            default   => 'Article',
        };
    }

    /**
     * 正文 Markdown 渲染为 HTML（前台唯一出口）。
     * 按「内容 id + 更新时间」缓存解析结果：知识文章正文较长，MISS 渲染可省 200–400ms；
     * 内容一保存 updated_at 即变化，键自然失效，绝不发旧渲染；外层另有整页缓存兜底。
     * 输出与 Str::markdown($this->body) 完全一致，不做任何额外改写。
     */
    public function bodyHtml(): string
    {
        if ($this->body === null || $this->body === '') {
            return '';
        }

        $key = 'content:mdhtml:'.$this->getKey().':'.optional($this->updated_at)->timestamp.':'.strlen($this->body);

        return Cache::store('file')->remember($key, now()->addDays(7), function () {
            return (string) Str::markdown((string) $this->body);
        });
    }

    /** 内容指纹，供对接幂等判断 */
    public function computeHash(): string
    {
        return hash('sha256', json_encode([
            $this->title,
            $this->summary,
            $this->body,
            $this->geo_conclusion,
            $this->geo_explanation,
            $this->geo_evidence,
            $this->geo_boundary,
        ], JSON_UNESCAPED_UNICODE));
    }
}
