<?php

namespace App\Models;

use App\Support\SiteCacheKey;
use Illuminate\Database\Eloquent\Model;
use App\Support\BelongsToSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * 站点设置（键值对）
 *
 * 「可自定义样式」的落点：主题色、字体、容器宽度、联系方式、备案号等
 * 全部存这里，后台改完即时生效，不需要改代码也不需要重新部署。
 */
class Setting extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    protected $casts = [
        'value' => 'json',
    ];

    public static function cacheKey(): string
    {
        return SiteCacheKey::settings();
    }

    /** 请求级内存缓存：同一请求内多次读取不再反复访问缓存存储（database 驱动下即省掉大量 cache SELECT） */
    private static ?array $requestMemo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allCached();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::flush();
    }

    /** 整表缓存 + 请求级内存，前台每次请求最多查一次缓存存储 */
    public static function allCached(): array
    {
        if (self::$requestMemo !== null) {
            return self::$requestMemo;
        }
        return self::$requestMemo = Cache::rememberForever(self::cacheKey(), function () {
            return static::pluck('value', 'key')->toArray();
        });
    }

    public static function flush(): void
    {
        self::$requestMemo = null;
        Cache::forget(self::cacheKey());
    }

    /**
     * 仅清空「请求级内存」、不动持久缓存。
     * 每个请求开始时由 AppServiceProvider 调用：PHP-FPM/内置服进程会复用，
     * static 属性跨请求存活，必须逐请求复位，才是真正的请求级缓存（避免读到上个请求的旧设置）。
     */
    public static function resetRequestMemo(): void
    {
        self::$requestMemo = null;
    }

    /** 按分组取，后台设置页用 */
    public static function forGroup(string $group): array
    {
        return static::where('group', $group)->orderBy('sort')->get()->toArray();
    }
}
