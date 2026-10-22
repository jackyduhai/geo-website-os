<?php

namespace App\Support\Templates;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Support\PageCacheInvalidation;
use App\Support\SiteContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Template Activation Service（W3 F1）。
 * --------------------------------------------------
 * 模板激活的唯一编排入口，Web {@see \App\Http\Controllers\Admin\TemplateController}
 * 与 CLI {@see \App\Console\Commands\TemplateActivate} 共用，保证锁、事务、
 * 幂等、缓存与审计语义只有一份实现。
 *
 * 一致性边界（单个数据库事务），顺序为「先锁站点行，再读配方/决定状态」：
 *  1. 对本站 sites 行加悲观行锁（lockForUpdate）；
 *  2. 锁内读取配方并按指定键过滤（失败关闭：指定键不存在即抛错，不写任何数据）；
 *  3. marker（template_active_pack）写入；注册包模板；
 *  4. 持久化「本次激活」的提交后缓存失效记录（每次激活独立 id，进程崩溃可恢复）；
 *  5. 逐配方应用（RecipeApplier 已做接管 + 幂等，不再逐配方 flush）；
 *  6. 成功审计在事务内，与最终状态一致。
 *
 * 任一阶段失败 → 整体回滚（无 marker 残留、无半页、无成功审计），并在
 * 回滚之后单独记录一次 template.activate_failed 事件。
 *
 * 缓存失效在事务提交后执行（数据库回滚无法撤销文件缓存）。提交后处理的
 * 异常被就地捕获：记录保留为 retryable，由 pagecache:recover-invalidations
 * 与 6 小时 TTL 兜底，且不会把「已提交」误报为「已回滚」。
 */
class TemplateActivationService
{
    /**
     * 为站点激活模板包。
     *
     * @param array<int,string>|null $onlyRecipes 仅应用指定配方键（默认全部）
     * @return array{recipes:array<string,array>,invalidation:string|null,cache:string}
     *
     * @throws Throwable 当激活失败（已回滚）时抛出原始异常，由调用方决定响应
     */
    public static function activate(Site $site, string $pack, ?array $onlyRecipes = null): array
    {
        $invalidationId = null;
        $previousActive = '';
        $mutated = false; // 是否已在本进程改过激活态（memo/注册）；只有改过才需要恢复

        try {
            $result = DB::transaction(function () use ($site, $pack, $onlyRecipes, &$invalidationId, &$previousActive, &$mutated) {
                // 1. 先锁定本站行，且必须是事务内的第一条数据库语句（在任何依赖站点
                //    上下文的一致性读之前）。REPEATABLE READ 下事务 read view 由第一条
                //    一致性读建立：若先进入 SiteContext::withSite()（其 reapply 会读 active
                //    设置）再去加锁，排队事务即使在领先者提交后才拿到锁，快照仍停留在
                //    提交前，导致 marker 的 updateOrCreate 看不到已存在行而走 INSERT、撞
                //    (site_id,key) 唯一键（F2-F1）。把锁前移，排队事务在拿锁后才建立快照。
                //    此处无需站点上下文（按 id 直查并显式关闭全局 scope）。
                $locked = Site::withoutGlobalScopes()
                    ->where('id', $site->id)
                    ->lockForUpdate()
                    ->first();
                if ($locked === null) {
                    throw new RuntimeException("Site not found for activation: {$site->id}");
                }

                return SiteContext::withSite($locked, function () use ($locked, $pack, $onlyRecipes, &$invalidationId, &$previousActive, &$mutated) {
                    // 2. 尽早读出「写 marker 前」的权威 DB 激活态（此时尚未改任何状态），
                    //    供失败后恢复进程内状态使用。必须经 Eloquent 模型读取（value 有
                    //    json cast，查询构造器 value() 会返回带引号的原始 JSON 字符串，R10）。
                    $previousRow = Setting::withoutSiteScope()
                        ->where('site_id', $locked->id)
                        ->where('key', TemplatePackageManager::SETTINGS_KEY)
                        ->first();
                    $previousActive = $previousRow === null ? '' : (string) $previousRow->value;

                    // 3. 锁内读取配方；显式指定键时失败关闭（任一指定键不存在即拒绝）。
                    //    若此处抛异常，本进程尚未改激活态，catch 不应触碰 memo/注册。
                    $allRecipes = TemplatePackageManager::recipes($pack);
                    $recipes = self::resolveRecipes($allRecipes, $onlyRecipes);

                    // 4. marker 写入（事务内，不刷缓存）。
                    Setting::withoutSiteScope()->updateOrCreate(
                        ['site_id' => $locked->id, 'key' => TemplatePackageManager::SETTINGS_KEY],
                        ['value' => $pack]
                    );
                    // 一旦开始修改进程内激活态即置位：primeActiveMemo/register 任一失败
                    // 都会在 catch 中恢复（权威旧态已在第 2 步读出，R7）。
                    $mutated = true;
                    TemplatePackageManager::primeActiveMemo($pack);
                    // 注册是激活成功的必要条件；注册失败（如 template.json 损坏）必须回滚（R12）。
                    // 仅含配方、无 template.json 的包（如 saas-pro）属合法「无包级模板」，
                    // register() 返回成功，不会因此误判。
                    if (! TemplatePackageManager::register()) {
                        throw new RuntimeException("模板包注册失败，激活已回滚：{$pack}");
                    }

                    // 5. 持久化本次激活的提交后缓存失效意图（与事务同提交/同回滚）。
                    $invalidationId = PageCacheInvalidation::request($locked);

                    // 6. 逐配方应用（接管 + 幂等；不再逐配方 flush）。
                    $summary = [];
                    foreach ($recipes as $key => $recipe) {
                        $summary[$key] = RecipeApplier::apply($locked, $pack, $recipe);
                    }

                    // 7. 成功审计（事务内，与最终状态一致）。
                    AuditLog::record('template.activate', "激活模板包：{$pack}", [], 'Setting');

                    return ['recipes' => $summary, 'invalidation' => $invalidationId];
                });
            });
        } catch (Throwable $e) {
            // 事务已回滚：仅当本进程确实改过激活态时，才恢复到权威旧值（R7）；
            // 随后单独记录失败事件（不记为成功）。
            if ($mutated) {
                TemplatePackageManager::restoreActive($previousActive);
            }
            self::recordFailure($site, $pack, $e);

            throw $e;
        }

        // 提交后：执行本次激活的缓存失效。任何异常就地捕获 → 记录保留待恢复，
        // 不向外抛出（否则会把「已提交」误报为「已回滚」）。
        $cacheStatus = 'pending';
        if ($invalidationId !== null) {
            try {
                $cacheStatus = PageCacheInvalidation::processSite($site, $invalidationId);
            } catch (Throwable) {
                $cacheStatus = 'retryable';
            }
        }
        $result['cache'] = $cacheStatus;

        return $result;
    }

    /**
     * 锁内解析要应用的配方（失败关闭）。
     * - 包本身零配方：直接拒绝（有效包必须至少含一个配方，R13）；
     * - 未指定 onlyRecipes：返回全部配方；
     * - 指定了键：每个键都必须存在，过滤后为空同样拒绝。
     *
     * @param array<string,array> $allRecipes
     * @param array<int,string>|null $onlyRecipes
     * @return array<string,array>
     */
    private static function resolveRecipes(array $allRecipes, ?array $onlyRecipes): array
    {
        if ($allRecipes === []) {
            throw new InvalidArgumentException('模板包没有任何可应用的配方，激活已中止。');
        }

        if ($onlyRecipes === null) {
            return $allRecipes;
        }

        $requested = array_values(array_unique(array_map('strval', $onlyRecipes)));
        $missing = array_values(array_diff($requested, array_keys($allRecipes)));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                '指定的配方键不存在，激活已中止：'.implode(',', $missing)
            );
        }

        $recipes = array_intersect_key($allRecipes, array_flip($requested));
        if ($recipes === []) {
            throw new InvalidArgumentException('未选择任何有效配方，激活已中止。');
        }

        return $recipes;
    }

    /** 回滚之后单独记录失败事件；失败审计本身不得掩盖原始异常。 */
    private static function recordFailure(Site $site, string $pack, Throwable $e): void
    {
        try {
            SiteContext::withSite($site, static function () use ($pack, $e): void {
                AuditLog::record(
                    'template.activate_failed',
                    "模板激活失败（已回滚）：{$pack}",
                    ['error' => $e->getMessage()],
                    'Setting'
                );
            });
        } catch (Throwable) {
            // 忽略失败审计自身的异常
        }
    }
}
