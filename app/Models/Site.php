<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 站点模型 Site
 *
 * GEO Website OS 多站点基础层。
 * 每个 Site 拥有独立的 Entity / Content / Setting / Media / SEO 数据。
 *
 * 核心原则：
 * - Site 层不依赖任何具体业务（Facts / Content / Controller / Blade）
 * - 不允许出现业务绑定方法（如 getXxxSite() 形式的硬编码）
 * - default site 通过 slug='default' 或 id=1 识别
 */
class Site extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const DEFAULT_SLUG = 'default';

    /**
     * 获取默认站点。
     * v1.0 单站点模式下始终返回 id=1 的 default site。
     */
    public static function default(): ?self
    {
        return static::where('slug', self::DEFAULT_SLUG)->first();
    }

    /**
     * 获取默认站点 ID，不存在时返回 null。
     */
    public static function defaultId(): ?int
    {
        return static::where('slug', self::DEFAULT_SLUG)->value('id');
    }

    /**
     * 站点是否处于活跃状态。
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 站点是否为默认站点。
     */
    public function isDefault(): bool
    {
        return $this->slug === self::DEFAULT_SLUG;
    }
}
