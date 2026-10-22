<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PageCache 提交后失效 —— 以数据库「待失效意图」为唯一事实来源。
 * --------------------------------------------------
 * 为什么需要：数据库提交与缓存失效是两个不同的故障边界。激活事务提交后，
 * 进程可能在执行 {@see PageCache::flush()} 之前崩溃；仅靠进程内回调无法
 * 保证失效。
 *
 * 存储：复用现有 settings 表，不新增表、不做迁移。每站点一行
 * （key = {@see self::PENDING_KEY}，value = 本次激活的 token）。
 *
 * 提交一致性（关键）：
 *  - 意图在激活事务内、持有站点行锁时写入，因此与激活「同提交、同回滚」。
 *    另一条数据库连接在事务提交前根本看不到这行（事务隔离），恢复进程在
 *    锁站点行时还会被未提交的激活事务阻塞（lockForUpdate）——
 *    因此恢复不可能在数据库提交之前消费失效（R4）。
 *  - 提交后处理：先 flush（PageCache 版本号 +1、Setting::flush 清设置缓存），
 *    再按 token 条件清除意图。
 *  - 崩溃恢复：提交后、flush 前崩溃 → 意图已提交，恢复扫描能发现并补刷；
 *    flush 后、清除前崩溃 → 恢复再刷一次（flush 幂等，保守无害）。
 *
 * 并发与串激活：
 *  - 清除按 token 条件进行（持站点行锁、读出权威 value 后比对）。
 *    激活 A 不会误删更新的激活 B 写入的新 token；B 自己的提交后处理会负责 B。
 *  - flush 是 per-site 版本号 bump，天然幂等、可合并。
 */
class PageCacheInvalidation
{
    /** settings 中「待失效意图」的键。 */
    public const PENDING_KEY = 'template_cache_invalidation_pending';

    /**
     * 在激活事务内、持有站点行锁时登记待失效意图。
     * 意图与激活事务同提交/同回滚；写失败会让激活事务整体失败，不会得到假成功。
     */
    public static function request(Site $site): string
    {
        $token = bin2hex(random_bytes(16));

        Setting::withoutSiteScope()->updateOrCreate(
            ['site_id' => $site->id, 'key' => self::PENDING_KEY],
            ['value' => $token]
        );

        return $token;
    }

    /**
     * 提交后处理本次激活（仅在 DB 事务提交成功后调用）。
     * 整段在站点行锁内完成「读当前意图 → flush → 按 token 条件清除」，
     * 任何异常都使意图保留并返回 retryable（不报 completed）。
     *
     * @return string completed|retryable
     */
    public static function processSite(Site $site, string $token): string
    {
        return self::invalidateUnderLock($site, $token);
    }

    /**
     * 恢复扫描：为每个仍登记待失效意图的站点补刷并清除。
     * 锁站点行后才读取意图，保证只处理「已提交」的激活。
     *
     * @return array<int,array{site_id:int,before:string,after:string}>
     */
    public static function recover(): array
    {
        $summary = [];

        $rows = Setting::withoutSiteScope()->where('key', self::PENDING_KEY)->get();
        foreach ($rows as $row) {
            $site = Site::withoutGlobalScopes()->find($row->site_id);
            if ($site === null) {
                // 站点已不存在：清理其残留意图（清理失败也不影响其它站点）。
                try {
                    Setting::withoutSiteScope()
                        ->where('site_id', $row->site_id)
                        ->where('key', self::PENDING_KEY)
                        ->delete();
                } catch (Throwable) {
                    // 忽略：下次扫描再处理。
                }

                continue;
            }

            // 单个站点处理异常不得让整批恢复中断：记为 retryable，继续其它站点。
            $after = 'retryable';
            try {
                $after = self::recoverSite($site);
            } catch (Throwable) {
                $after = 'retryable';
            }

            $summary[] = [
                'site_id' => $site->id,
                'before'  => 'pending',
                'after'   => $after,
            ];
        }

        return $summary;
    }

    /** 恢复扫描：处理该站点当前已提交的意图（不指定期望 token，清除当前值）。 */
    private static function recoverSite(Site $site): string
    {
        return self::invalidateUnderLock($site, null);
    }

    /**
     * 在同一个站点行锁事务内完成「读当前意图 → flush → 条件清除」。
     * 持锁覆盖读、刷、清三步，故任何激活事务的意图写入都无法插到中间（R11）。
     *
     * @param string|null $expectToken
     *   - 提交后即时处理：传本次激活 token；只有当前意图仍是它才清除，
     *     若已被更新激活覆盖，则保留新 token（仅刷新）。
     *   - 恢复扫描（null）：清除当前已提交意图（无论 token）。
     */
    private static function invalidateUnderLock(Site $site, ?string $expectToken): string
    {
        try {
            return DB::transaction(function () use ($site, $expectToken) {
                return SiteContext::withSite($site, function () use ($site, $expectToken) {
                    // 锁站点行：等待任何进行中的激活事务提交/回滚，之后再读意图（R4）。
                    $locked = Site::withoutGlobalScopes()
                        ->where('id', $site->id)
                        ->lockForUpdate()
                        ->first();
                    if ($locked === null) {
                        return 'completed';
                    }

                    $row = Setting::withoutSiteScope()
                        ->where('site_id', $locked->id)
                        ->where('key', self::PENDING_KEY)
                        ->first();
                    if ($row === null) {
                        // 无待处理意图（已清除，或激活已回滚）。
                        return 'completed';
                    }

                    $currentToken = (string) $row->value;

                    // 持锁刷新：PageCache 版本号 bump（幂等）+ 清设置缓存。
                    // 若抛异常，整个事务回滚、意图保留，外层返回 retryable。
                    PageCache::flush();
                    Setting::flush();

                    // 条件清除：即时处理只清自己的 token（不删更新激活的新 token）；
                    // 恢复扫描清除当前已提交意图。
                    $shouldClear = $expectToken === null || $currentToken === $expectToken;
                    if ($shouldClear) {
                        $row->delete();
                    }

                    return 'completed';
                });
            });
        } catch (Throwable) {
            // flush / 删除失败：删除随事务回滚，意图保留，供重试。
            return 'retryable';
        }
    }
}
