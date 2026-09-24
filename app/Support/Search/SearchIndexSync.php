<?php

namespace App\Support\Search;

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Support\Facades\Cache;

/**
 * 搜索索引增量同步（P-STEP 18H-1）。
 * ------------------------------------------------------------------
 * 让索引不依赖「管理员记得手动 reindex」：
 *
 *   - Content / Entity（搜索主体）保存 / 删除 → 立即增量重算该语言行（先删后按准入插）；
 *   - SeoMeta / Category / EntityRelation / Setting / Site 变更会改变「准入」（noindex、
 *     栏目启用、场景可用性、语言配置），但批量 seed 时逐条全量重建代价过高——这里只
 *     标记该站点 dirty（持久到 file store、跨进程可见），引擎在查询前由
 *     {@see flushDirty()} 懒重建，一次请求至多重建一次。
 *
 * 写入仍由 {@see SearchIndexBuilder} 完成，本类只负责事件接线，不产生第二事实源。
 */
class SearchIndexSync
{
    /**
     * 事件是否已在「当前实例 / 当前应用的事件 dispatcher」上注册。
     * 必须是实例属性而非 static：Feature 测试每个用例重建应用（新的事件 dispatcher），
     * static 标志会跨用例残留为 true，导致新 dispatcher 上不再注册 saved 监听、
     * create 不触发增量同步。生产进程只 boot 一次，实例属性同样保证 boot 内幂等。
     */
    private bool $registered = false;
    private static bool $suppressed = false;

    private const DIRTY_KEY = 'search:dirty:sites';

    public function __construct(private SearchIndexBuilder $builder)
    {
    }

    /** 注册模型事件（当前应用实例内幂等）。 */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        // —— 搜索主体：保存 / 删除 → 增量重算该语言行 ——
        Content::saved(function (Content $c): void { $this->upsertContent($c); });
        Content::deleted(function (Content $c): void { $this->forgetContent($c); });
        // Entity 是 Catalog 派生读模型的来源：增量 upsert 会经 PublicUrl::entity() 读取
        // Catalog，可能在批量写入中途预热「半成品」memo（此刻后续实体尚未写入）。处理完
        // 必须 flush，避免缓存残缺 dataset（导致 scenes() 为空、Service 不被索引）。
        Entity::saved(function (Entity $e): void {
            $this->upsertEntity($e);
            Catalog::flush();
        });
        Entity::deleted(function (Entity $e): void {
            $this->forgetEntity($e);
            Catalog::flush();
        });

        // —— 影响准入但非搜索主体 → 标记站点 dirty，查询前懒重建 ——
        foreach ([SeoMeta::class, Category::class, EntityRelation::class, Setting::class, Site::class] as $model) {
            $model::saved(function ($m): void {
                $this->markDirty($this->siteOf($m));
                // EntityRelation 是 Catalog 关系派生的权威源，变更必须失效 catalog memo。
                if ($m instanceof EntityRelation) {
                    Catalog::flush();
                }
            });
            $model::deleted(function ($m): void {
                $this->markDirty($this->siteOf($m));
                if ($m instanceof EntityRelation) {
                    Catalog::flush();
                }
            });
        }
    }

    public function upsertContent(Content $c): void
    {
        if (! self::$suppressed) {
            $this->builder->upsertContent($c);
        }
    }

    public function upsertEntity(Entity $e): void
    {
        if (! self::$suppressed) {
            $this->builder->upsertEntity($e);
        }
    }

    public function forgetContent(Content $c): void
    {
        if (! self::$suppressed) {
            $this->builder->removeDocument('content', (int) $c->id, (int) $c->site_id, (string) $c->locale);
        }
    }

    public function forgetEntity(Entity $e): void
    {
        if (! self::$suppressed) {
            $this->builder->removeDocument((string) $e->type, (int) $e->id, (int) $e->site_id, (string) $e->locale);
        }
    }

    /** 标记某站索引可能过期（去重、持久、跨进程可见）。 */
    public function markDirty(?int $siteId): void
    {
        if (self::$suppressed || $siteId === null) {
            return;
        }

        $ids = $this->dirtyIds();
        $ids[$siteId] = true;
        Cache::store('file')->forever(self::DIRTY_KEY, array_keys($ids));
    }

    /**
     * 引擎查询前调用：若该站被标记 dirty，则全量重建本站并清除标记。
     */
    public function flushDirty(int $siteId): void
    {
        $ids = $this->dirtyIds();
        if (! isset($ids[$siteId])) {
            return;
        }

        // rebuildSite 依赖「当前 SiteContext」：PublicIndex / PublicUrl / Catalog 都按
        // SiteContext 解析站点。查询传入的 siteId 可能与当前上下文不同（CLI / SubRequest /
        // 一次进程内对非当前站点查询），必须先切到目标站点再重建；否则会在他站上下文下
        // 投影，把他站数据重复写入本站并触发唯一约束冲突。
        $site = Site::find($siteId);
        if ($site !== null) {
            SiteContext::withSite($site, function () use ($siteId): void {
                $this->builder->rebuildSite($siteId);
            });
        } else {
            $this->builder->rebuildSite($siteId);
        }

        unset($ids[$siteId]);
        Cache::store('file')->forever(self::DIRTY_KEY, array_keys($ids));
    }

    /** 清除所有 dirty 标记（测试隔离 / 安装完成后）。 */
    public function clearDirty(): void
    {
        Cache::store('file')->forget(self::DIRTY_KEY);
    }

    /** @return array<int,true> */
    private function dirtyIds(): array
    {
        $ids = Cache::store('file')->get(self::DIRTY_KEY, []);

        return array_flip(array_map('intval', (array) $ids));
    }

    private function siteOf($model): ?int
    {
        if ($model instanceof Site) {
            return (int) $model->id;
        }

        if (isset($model->site_id) && $model->site_id !== null) {
            return (int) $model->site_id;
        }

        return SiteContext::currentSiteId();
    }

    /** 批量导入 / seed 期间临时关闭增量同步；结束后应做一次 reindex。 */
    public static function suppress(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }
}
