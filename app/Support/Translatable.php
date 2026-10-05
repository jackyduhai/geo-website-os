<?php

namespace App\Support;

use App\Support\Localization\LocaleRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 可翻译资源 trait（Content / Entity）。
 * ------------------------------------------------------------------
 * 翻译模型 = 同表多行 + translation_group：
 *   - creating 时自动分配 translation_group(uuid) 与默认 locale；
 *   - translations() 关系关联同一 group 的所有语言行；
 *   - 默认语言（zh-CN）权威行保存后，把「跨语言共享列」单向同步到其余语言行
 *     （saveQuietly，不触发事件递归）；翻译列每行独立、不同步。
 *
 * 模型用 `protected static array $sharedTranslatableColumns = [...]` 声明共享列。
 */
trait Translatable
{
    public static function bootTranslatable(): void
    {
        static::creating(function ($model): void {
            if (empty($model->translation_group)) {
                $model->translation_group = (string) Str::uuid();
            }
            if (empty($model->locale)) {
                $model->locale = LocaleRegistry::default();
            }
        });

        // 默认语言权威行 → 单向同步共享列
        static::saved(function ($model): void {
            if ($model->locale === LocaleRegistry::default()) {
                $model->syncSharedColumns();
            }
        });

        // 删除 anchor 时级联处理同 translation_group 的其余翻译行，避免「中文删了、
        // 其他语言仍可访问」的泄漏（BUG-20B-002）。Content 为软删（级联软删），
        // Entity 为硬删（级联硬删）；forceDelete 时物理删除。批量操作不触发模型
        // 事件以避免递归；缓存失效由 anchor 自身的 deleted 事件负责。
        static::deleting(function ($model): void {
            $force = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
            $base = static::where('translation_group', $model->translation_group)
                ->whereKeyNot($model->getKey());
            if ($force) {
                $base->forceDelete();
            } else {
                $base->delete();
            }
        });
    }

    /** 同一 translation_group 的所有语言行。 */
    public function translations(): HasMany
    {
        return $this->hasMany(static::class, 'translation_group', 'translation_group');
    }

    /** 当前语言行（无则 null）。 */
    public function translation(string $locale): ?static
    {
        return $this->translations()->where('locale', $locale)->first();
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where($this->getTable().'.locale', $locale);
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->translations()->where('locale', $locale)->exists();
    }

    /**
     * 该翻译组中「已发布」语言行的 locale 集合（资源级 hreflang 依据）。
     * 未发布 / 无翻译的语言不输出对等 hreflang，避免 hreflang 指向 404 页面。
     *
     * @return array<int,string>
     */
    public function publishedLocaleCodes(): array
    {
        return $this->translations()
            ->where('status', 'published')
            ->pluck('locale')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 基于当前（默认语言）行创建新语言翻译行：复制共享列，
     * translated 提供该语言独立字段（name/title/slug/summary/...）。
     */
    public function createTranslation(string $locale, array $translated = []): static
    {
        $row = new static();
        $row->translation_group = $this->translation_group;
        $row->locale = $locale;
        $row->site_id = $this->site_id;

        foreach (static::sharedTranslatableColumns() as $col) {
            // 源值为 null（如未显式设置、由 DB 默认值填充的 NOT NULL 列）时跳过，
            // 让新语言行沿用数据库默认值，避免复制 null 违反 NOT NULL。
            if ($this->{$col} !== null) {
                $row->{$col} = $this->{$col};
            }
        }
        foreach ($translated as $key => $value) {
            $row->{$key} = $value;
        }

        $row->save();

        return $row;
    }

    /** 默认语言行 → 同步共享列到其余语言行。 */
    public function syncSharedColumns(): void
    {
        $shared = static::sharedTranslatableColumns();
        if ($shared === []) {
            return;
        }

        $siblings = $this->translations()->whereKeyNot($this->getKey())->get();

        foreach ($siblings as $sibling) {
            $dirty = false;
            foreach ($shared as $col) {
                // 权威行为 null（DB 默认值未回读）时不覆盖兄弟行，避免写入 null。
                if ($this->{$col} !== null && $sibling->{$col} !== $this->{$col}) {
                    $sibling->{$col} = $this->{$col};
                    $dirty = true;
                }
            }
            if ($dirty) {
                $sibling->saveQuietly();
            }
        }
    }

    /** 该模型跨语言共享列（模型静态属性声明；缺省为空）。 */
    public static function sharedTranslatableColumns(): array
    {
        return static::$sharedTranslatableColumns ?? [];
    }
}
