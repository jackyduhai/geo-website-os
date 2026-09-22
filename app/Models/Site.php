<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use App\Support\PageCache;
use App\Support\SiteContext;
use Throwable;

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
     * TD-12 单一事实源：Site.name 是站点显示名的唯一权威；settings.site_name 仅作为
     * 镜像，供 SEO / Schema / 视图等既有消费者读取。任何路径创建 / 更新站点（安装、
     * Seeder、后台、CLI）后都单向同步该镜像，避免两处可编辑导致名称分叉。
     */
    protected static function booted(): void
    {
        static::saved(function (self $site): void {
            try {
                if (Schema::hasTable((new Setting())->getTable())) {
                    $name = trim((string) $site->name);
                    if ($name !== '') {
                        // 显式带 site_id，creating 自动填充不会覆盖到当前上下文站点。
                        Setting::withoutSiteScope()->updateOrCreate(
                            ['site_id' => $site->id, 'key' => 'site_name'],
                            ['site_id' => $site->id, 'value' => $name]
                        );
                        Setting::flush();
                    }
                }
            } catch (Throwable) {
                // 安装早期 settings 表尚未就绪时静默跳过，安装命令随后会写入默认设置。
            }

            // TD-08b：站点名称 / 域名 / Logo / metadata 影响全站前台 HTML、canonical、
            // Schema、GEO，任何路径保存（后台 / tinker / import / Seeder）都失效整页缓存，
            // 不再只依赖 SiteController 手动 flush。
            try {
                PageCache::flush();
            } catch (Throwable) {
                // 缓存存储未就绪（安装极早期）时静默跳过。
            }

            // 18B 登记 / TD-08b：同进程（CLI / queue worker / 嵌套 SubRequest）中
            // currentSite 可能 memo 了改名前的旧实例；若被保存站点正是当前上下文，
            // 用最新实例替换 memo，避免后续请求读到 stale name / domain / metadata。
            if (SiteContext::hasSite()
                && SiteContext::currentSite()?->id === $site->id) {
                SiteContext::setSite($site);
            }
        });

        static::deleted(function (self $site): void {
            try {
                PageCache::flush();
            } catch (Throwable) {
                // 缓存存储未就绪时静默跳过。
            }
        });
    }

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
