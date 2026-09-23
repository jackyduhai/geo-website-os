<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\BelongsToSite;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Schema;

/**
 * 栏目
 */
class Category extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'is_nav' => 'boolean',
        'is_active' => 'boolean',
        'is_index' => 'boolean',
        'sort' => 'integer',
        'parent_id' => 'integer',
    ];

    // ---------- 栏目类型（v1.0 冻结，TD-16）----------
    // list          内容列表（文章 / 新闻）
    // product_list  产品列表（富卡片）
    // page          单页：栏目地址直接规范到其下首篇文章（无尾斜杠、不进 sitemap）
    // external      外链跳转：导航指向 external_url，不产生站内栏目页
    public const TYPE_LIST = 'list';
    public const TYPE_PRODUCT_LIST = 'product_list';
    public const TYPE_PAGE = 'page';
    public const TYPE_EXTERNAL = 'external';

    public const TYPES = [
        self::TYPE_LIST,
        self::TYPE_PRODUCT_LIST,
        self::TYPE_PAGE,
        self::TYPE_EXTERNAL,
    ];

    /** 历史别名（建表注释期的旧枚举值），仅用于读取兼容，新数据不再写入。 */
    private const LEGACY_SINGLE = 'single';
    private const LEGACY_PRODUCT = 'product';

    /** 单页型：渲染其下首篇文章，规范地址是文章 URL（无尾斜杠、不进 sitemap）。 */
    public function isSinglePage(): bool
    {
        return $this->type === self::TYPE_PAGE || $this->type === self::LEGACY_SINGLE;
    }

    /** 产品列表型：前台使用产品富卡片模板。 */
    public function isProductList(): bool
    {
        return $this->type === self::TYPE_PRODUCT_LIST || $this->type === self::LEGACY_PRODUCT;
    }

    /** 外链型：不产生站内可索引栏目地址。 */
    public function isExternalLink(): bool
    {
        return $this->type === self::TYPE_EXTERNAL;
    }

    /** 不进 sitemap 的栏目类型（单页直接渲染文章、外链无站内页；含历史 single 别名）。 */
    public static function typesExcludedFromSitemap(): array
    {
        return [self::TYPE_PAGE, self::TYPE_EXTERNAL, self::LEGACY_SINGLE];
    }

    // ---------- 关系 ----------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class)->orderBy('sort');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    // ---------- 作用域 ----------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInNav($query)
    {
        return $query->where('is_active', true)->where('is_nav', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    // ---------- 辅助 ----------

    /**
     * 栏目前台地址（自动拼接完整层级路径）。
     *
     * 经 PublicUrl 裁决规范 host（TD-09）：该地址同时用于 canonical、CollectionPage
     * JSON-LD、sitemap / llms 与可见面包屑，必须是站点规范绝对 URL，不能用 url() 跟随
     * 临时请求 origin，避免 CLI / 队列 / SubRequest 下 host 分叉。
     */
    public function url(): string
    {
        $segs = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segs, $node->slug);
            $node = $node->parent;
        }
        return PublicUrl::url(implode('/', $segs) . '/');
    }

    /** 该栏目在导航中的层级深度（0 为顶级） */
    public function depth(): int
    {
        $d = 0;
        $node = $this;
        while ($node->parent_id) {
            $d++;
            $node = $node->parent;
            if ($d > 10) {
                break;
            }
        }
        return $d;
    }

    /**
     * 前台显示名称（P-STEP 18F）：固定栏目（products / solutions / knowledge /
     * about / contact / cooperation / factory）走 nav 翻译键、随前台语言切换；
     * 自定义栏目 v1 无翻译模型，回退其 name。
     */
    public function displayName(): string
    {
        $key = 'nav.' . $this->slug;
        $translated = __($key);

        return $translated === $key ? (string) $this->name : $translated;
    }

    /** 面包屑：从根到当前 */
    public function breadcrumbs(): array
    {
        $crumbs = [];
        $node = $this;
        while ($node) {
            array_unshift($crumbs, ['name' => $node->displayName(), 'url' => $node->url()]);
            $node = $node->parent;
        }
        return $crumbs;
    }
}
