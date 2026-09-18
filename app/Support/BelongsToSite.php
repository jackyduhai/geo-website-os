<?php

namespace App\Support;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * Site-scoped Model trait
 *
 * 为所有 Site-scoped Model 统一提供：
 * - 全局查询 Scope（自动按 current site_id 过滤）
 * - withoutSiteScope() 受控 bypass（Admin / 系统维护）
 * - creating 事件自动填充 site_id
 *
 * 使用方式：
 *   class Content extends Model { use BelongsToSite; }
 *
 * 安全原则：
 * - 正常业务查询自动隔离，无需手动 where('site_id', ...)
 * - withoutSiteScope() 仅用于 Admin / CLI / 系统维护
 * - 写入时自动绑定当前 SiteContext 的 site_id
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope(new SiteScope());

        static::creating(function (Model $model) {
            $table = $model->getTable();

            // 表没有 site_id 列时不自动填充（兼容早期 migration）
            if (!Schema::hasColumn($table, 'site_id')) {
                return;
            }

            if ($model->site_id === null) {
                $model->site_id = SiteContext::currentSiteId();
            }
        });
    }

    /**
     * 查询 Scope：绕过 Site 全局 Scope
     * 用法：Content::withoutSiteScope()->where(...)
     * 仅用于 Admin / CLI / 系统维护操作
     */
    public function scopeWithoutSiteScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SiteScope::class);
    }

    /**
     * 站点关联
     */
    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
