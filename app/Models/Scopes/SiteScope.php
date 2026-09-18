<?php

namespace App\Models\Scopes;

use App\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Site 全局查询作用域
 *
 * 自动为所有 Site-scoped Model 添加 where('site_id', currentSiteId()) 条件。
 * 通过 withoutSiteScope() 受控绕过（Admin / CLI / 系统维护）。
 */
class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        // 表不存在或没有 site_id 列时不应用（兼容早期 migration）
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'site_id')) {
            return;
        }

        $siteId = SiteContext::currentSiteId();
        $builder->where($table . '.site_id', $siteId);
    }
}

