<?php

namespace App\Models;

use App\Support\BelongsToSite;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 事实库（单一事实源）
 *
 * llms.txt 的「主体信息」节、JSON-LD 的 Organization 节点、页面事实块
 * 三处输出都从这里取数，保证口径完全一致。
 *
 * ── 多语言模型（20G-3）────────────────────────────────────────────
 * facts 与 Content / Entity 共用同一套行级翻译模型（同表多行 + translation_group），
 * **不另起第三套双语机制**：
 *
 *   key                = 事实语义身份（如 FACT-COMPANY-NAME），跨语言恒定。
 *                        跨语言引用（Content.fact_refs、GEO 语义节点、
 *                        ContentGate 的存在性校验）只认它，不受 locale 影响。
 *   translation_group  = 翻译实体身份，标识「这几行是同一事实的多语言」。
 *                        由系统按 key 派生（TG-FACT-{key}），运营人员不直接编辑。
 *   label / value      = 逐语言独立字段（不参与跨语言同步）。
 *
 * 共享列（key / is_public / sort / group / source / owner / reviewed_at /
 * review_due / unit / source_url）由默认语言权威行单向同步 —— 一次改
 * 「是否公开 / 排序 / 依据」，所有语言同时生效，避免「中文公开、英文未公开」
 * 这种口径分裂。
 *
 * ⚠️ **不允许跨语言 fallback**（20G-3 硬约束）：
 * publicRows() / publicMap() 等公共取数一律带显式 locale，
 * 缺翻译时宁可少一条事实，也不回退到另一语言 ——
 * GEO 场景下「英文站点输出中文事实」是数据污染，不是优雅降级。
 */
class Fact extends Model
{
    use BelongsToSite, Translatable;

    protected $guarded = [];

    protected $casts = [
        'is_public' => 'boolean',
        'reviewed_at' => 'date',
        'review_due' => 'date',
        'sort' => 'integer',
    ];

    /**
     * 跨语言共享列：默认语言权威行保存后单向同步到其余语言行。
     *
     * `key` 在共享列表里 —— 它是语义身份，中英必须一致，
     * 否则 Content.fact_refs 的引用在另一语言下会失效。
     * `label` / `value` 刻意不在其中：它们是逐语言翻译内容。
     */
    protected static array $sharedTranslatableColumns = [
        'key', 'group', 'source', 'owner', 'reviewed_at', 'review_due',
        'is_public', 'sort', 'unit', 'source_url',
    ];

    /**
     * 请求级内存缓存：事实只读且在同一请求内被大量复用，避免每个区块都查一次库。
     *
     * key 形如 `rows:en` —— **必须带 locale 维度**（20G-3）：
     * 同一进程内会先后渲染 zh / en 两套输出（GEO 双语抓取、队列预热等场景），
     * 不分片会命中前一次语言的结果 → 语言串档。
     */
    private static array $memo = [];

    /** 后台写入事实后调用，保证同进程（artisan serve / 队列）下不读到旧值 */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /**
     * 可直接对外的事实：key → value 映射。
     *
     * @param  string|null $locale 缺省取当前请求语言（LocaleContext）
     * @return array<string,string>
     */
    public static function publicMap(?string $locale = null): array
    {
        $locale = self::resolveLocale($locale);
        $memoKey = 'map:'.$locale;

        if (! array_key_exists($memoKey, self::$memo)) {
            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('is_public', true)
                ->orderBy('sort')
                ->pluck('value', 'key')
                ->toArray();
        }

        return self::$memo[$memoKey];
    }

    /**
     * 按 label 取值，便于模板中写 $facts['成立时间']。
     *
     * label 逐语言不同，故按 locale 分片缓存。
     */
    public static function publicByLabel(?string $locale = null): Collection
    {
        $locale = self::resolveLocale($locale);
        $memoKey = 'label:'.$locale;

        if (! isset(self::$memo[$memoKey])) {
            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('is_public', true)
                ->orderBy('sort')
                ->get()
                ->keyBy('label');
        }

        return self::$memo[$memoKey];
    }

    /**
     * 对外公开事实的模型集合（按 sort），供首页/关于页事实条遍历 label/value。
     *
     * 全站最核心的取数口（AppServiceProvider 全站注入 publicFacts +
     * GeoGraphBuilder::facts()），因此**必须**带语言维度：20G-3 之前这里
     * 不过滤 locale，导致 `/en/geo.json` 的 facts 段直接输出中文 label / value。
     */
    public static function publicRows(?string $locale = null): Collection
    {
        $locale = self::resolveLocale($locale);
        $memoKey = 'rows:'.$locale;

        if (! isset(self::$memo[$memoKey])) {
            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('is_public', true)
                ->orderBy('sort')
                ->get();
        }

        return self::$memo[$memoKey];
    }

    /**
     * 按分组取 key → value。
     *
     * `group` 属共享列（各语言一致），但 value 逐语言不同，故仍按 locale 分片。
     */
    public static function groupMap(string $group, ?string $locale = null): array
    {
        $locale = self::resolveLocale($locale);
        $memoKey = 'group:'.$group.':'.$locale;

        if (! array_key_exists($memoKey, self::$memo)) {
            self::$memo[$memoKey] = static::forLocale($locale)
                ->where('group', $group)
                ->where('is_public', true)
                ->orderBy('sort')
                ->pluck('value', 'key')
                ->toArray();
        }

        return self::$memo[$memoKey];
    }

    /**
     * 复核已过期或 30 天内到期的项 */
    public function scopeNeedsReview($query)
    {
        return $query->whereNotNull('review_due')
            ->where('review_due', '<=', now()->addDays(30));
    }

    protected static function boot(): void
    {
        /**
         * translation_group 按 key 派生确定性值（`TG-FACT-{key}`），
         * **覆盖 Translatable 默认的随机 UUID**。
         *
         * 原因：facts 的语义身份是 `key`（Content.fact_refs、GEO 节点都按 key 引用），
         * 而运营人员新增「英文版某事实」时，系统要能只凭 key 就把它归到中文行所在的组。
         * 若组 id 是随机 UUID，就必须在后台额外提供「选择翻译组」的下拉，
         * 运营人员会看到并可能手工改错 —— 这正是 20G-3 要消除的认知负担。
         *
         * ⚠️ **必须用 `boot()` 注册，不能放 `booted()` 里判 empty()**（20G-3 实测踩坑）：
         * trait 的 boot 在 `booted()` 之前执行，所以 `Translatable::bootTranslatable()`
         * 注册的 creating 钩子**先跑**，已经把 `translation_group` 填成 UUID。
         * 等到 `booted()` 里的钩子跑时 `empty($fact->translation_group)` 恒为 false
         * → 派生逻辑永远不生效（实测得到 `a02fa756-...` 的 UUID）。
         *
         * 本类把钩子写在这里（早于 trait），后续 trait 钩子里的 `empty()` 判断
         * 就能看到空值并正确派生。已有值不覆盖：允许数据修复场景显式指定归组。
         */
        static::creating(function (self $fact): void {
            $key = trim((string) $fact->key);
            if ($key !== '' && empty($fact->translation_group)) {
                $fact->translation_group = self::groupForKey($key);
            }
        });

        /**
         * ⚠️ `parent::boot()` **必须放在最后**。
         *
         * Laravel 的 `Model::boot()` 内部第一件事就是 `static::bootTraits()`，
         * 它会依次跑 `bootBelongsToSite()` / `bootTranslatable()`，
         * 由它们注册各自的 creating 钩子。
         *
         * 若把 `parent::boot()` 放在前面：trait 钩子先注册 → 先执行 →
         * 它已把 translation_group 填成 UUID → 我们的 `empty()` 恒为 false
         * → 派生逻辑永不生效（实测得到 `703e84d7-...`）。
         *
         * 放在后面则我们的钩子**先注册先执行**，此时 translation_group 仍为空，
         * 正确派生为 `TG-FACT-{key}`；随后 trait 的钩子看到非空值，不会覆盖。
         */
        parent::boot();
    }

    /**
     * 语义身份 → 翻译组 的派生规则（系统唯一出口）。
     *
     * 后台新增某语言的翻译行时据此归组，不把 group id 暴露给运营人员手工编辑。
     */
    public static function groupForKey(string $key): string
    {
        return 'TG-FACT-'.$key;
    }

    /**
     * 解析目标语言：显式参数 > 当前请求语言 > 默认语言。
     *
     * 绝不返回 null —— 取数口没有「不按语言过滤」这个选项，
     * 否则就是 20G-3 要修的那个缺陷本身。
     */
    private static function resolveLocale(?string $locale): string
    {
        return $locale
            ?? (LocaleContext::has() ? LocaleContext::current() : LocaleRegistry::default());
    }

    /**
     * 供翻译编辑界面用：某 key 在各语言下的行，按语言排序。
     *
     * 带 SiteScope（当前站点）—— 运营人员在哪个站点编辑就只能看到哪个站点的行。
     *
     * @return Collection<int, static>
     */
    public function translationRows(): Collection
    {
        return static::query()
            ->where('key', $this->key)
            ->orderBy('locale')
            ->get();
    }
}
