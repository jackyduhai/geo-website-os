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
    /** 表资格（有 site_id 列）请求级记忆化：避免每次查询都做 schema 内省 */
    private static array $eligibleMemo = [];

    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        // 表不存在或没有 site_id 列时不应用（兼容早期 migration）
        if (! isset(self::$eligibleMemo[$table])) {
            self::$eligibleMemo[$table] = Schema::hasTable($table) && Schema::hasColumn($table, 'site_id');
        }
        if (! self::$eligibleMemo[$table]) {
            return;
        }

        $siteId = SiteContext::currentSiteId();
        $builder->where($table . '.site_id', $siteId);
    }

    /** 请求级记忆化复位（AppServiceProvider::boot 调用；migration 变更表结构后也需复位） */
    public static function resetRequestMemo(): void
    {
        self::$eligibleMemo = [];
    }
}
