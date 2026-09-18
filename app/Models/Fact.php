<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 事实库（单一事实源）
 *
 * llms.txt 的「主体信息」节、JSON-LD 的 Organization 节点、页面事实块
 * 三处输出都从这里取数，保证口径完全一致。
 */
class Fact extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_public' => 'boolean',
        'reviewed_at' => 'date',
        'review_due' => 'date',
        'sort' => 'integer',
    ];

    /** 请求级内存缓存：事实只读且在同一请求内被大量复用，避免每个区块都查一次库 */
    private static array $memo = [];

    /** 后台写入事实后调用，保证同进程（artisan serve / 队列）下不读到旧值 */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /** 可直接对外的事实 */
    public static function publicMap(): array
    {
        if (! array_key_exists('map', self::$memo)) {
            self::$memo['map'] = static::where('is_public', true)
                ->orderBy('sort')
                ->pluck('value', 'key')
                ->toArray();
        }
        return self::$memo['map'];
    }

    /** 按 label 取值，便于模板中写 $facts['成立时间'] */
    public static function publicByLabel(): Collection
    {
        if (! isset(self::$memo['label'])) {
            self::$memo['label'] = static::where('is_public', true)->orderBy('sort')->get()->keyBy('label');
        }
        return self::$memo['label'];
    }

    /** 对外公开事实的模型集合（按 sort），供首页/关于页事实条遍历 label/value */
    public static function publicRows(): Collection
    {
        if (! isset(self::$memo['rows'])) {
            self::$memo['rows'] = static::where('is_public', true)->orderBy('sort')->get();
        }
        return self::$memo['rows'];
    }

    /** 按分组取 */
    public static function groupMap(string $group): array
    {
        $key = 'group:' . $group;
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = static::where('group', $group)
                ->where('is_public', true)
                ->orderBy('sort')
                ->pluck('value', 'key')
                ->toArray();
        }
        return self::$memo[$key];
    }

    /** 复核已过期或 30 天内到期的项 */
    public static function scopeNeedsReview($query)
    {
        return $query->whereNotNull('review_due')
            ->where('review_due', '<=', now()->addDays(30));
    }
}
