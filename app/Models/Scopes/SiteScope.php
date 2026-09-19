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
    /**
     * 表资格（有 site_id 列）请求级记忆化。
     * 只缓存 TRUE：FALSE 仅出现在迁移过渡期（列尚未创建），必须每次重查，
     * 否则迁移期的一次 false 会毒化整个进程的生命周期（P-STEP 10 修复）。
     */
    private static array $eligibleMemo = [];

    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        if (empty(self::$eligibleMemo[$table])) {
            // 表不存在或没有 site_id 列时不应用（兼容早期 migration）
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
