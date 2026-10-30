<?php
/**
 * 20G-8-E · Cache 失效确定性
 * ------------------------------------------------------------------
 * 目的：证明「一次写入 → **恰好一次**版本递增」，而不是「至少一次」。
 *
 * 为什么盯这个：20G-8 合并时把散落在各模型里的 `PageCache::flush()`
 * 收口到 `CacheInvalidationMap`，若模型层旧钩子没清干净就会**叠加**——
 * 一次 create 把版本推进 2。当时手工验证过，但没被任何测试锁住，
 * 属于典型的「看起来对、没有契约」。本Gate 把它变成可复跑的证据。
 *
 * 判据（三条同时成立才算确定性）：
 *   ① create / update / delete 各使当前站版本 **+1**（不是 +2）
 *   ② A 站写入不推进 B 站版本（缓存版本按站点分区）
 *   ③ 写入确实使缓存版本变化（证明监听器真的挂了，不是恰好相等）
 *
 * 在独立进程里跑（PageCache 版本号是进程级 Cache::forever，
 * 同进程连续断言会互相干扰）。
 *
 * 用法：GEO_DB=<sqlite> GEO_ROOT=<repo> php cache-invalidation-determinism.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
$db   = getenv('GEO_DB') ?: 'D:/Temp/g8e_cache.sqlite';

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE=$db");

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use App\Models\{Site, Entity, Content, EntityRelation, Category, Setting};
use App\Support\{SiteContext, PageCache, Catalog};

$PASS = 0; $FAIL = 0; $FAILURES = [];

function ok(string $id, string $desc, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] $id $desc\n";
    } else {
        $FAIL++;
        $FAILURES[] = "$id $desc" . ($detail !== '' ? " | $detail" : '');
        echo "  [FAIL] $id $desc" . ($detail !== '' ? " | $detail" : '') . "\n";
    }
}

/** 复位跨请求状态（每个断言前调用） */
function resetState(): void
{
    \App\Models\Scopes\SiteScope::resetRequestMemo();
    \App\Support\SiteCacheKey::resetRequestMemo();
    Setting::resetRequestMemo();
    Setting::flush();
    Catalog::flush();
    \App\Support\RequestScopedState::flushAll();
}

// ================================================================ 准备
echo "== 准备 ==\n";

$siteA = Site::where('slug', 'default')->first();
if (! $siteA) {
    echo "  [FATAL] 找不到 default 站点\n";
    exit(1);
}
$siteA->update(['status' => 'active', 'domain' => 'a.test']);

$siteB = Site::where('slug', 'site-b')->first() ?: Site::create([
    'slug' => 'site-b', 'name' => 'B站', 'domain' => 'b.test',
    'status' => 'active', 'is_default' => false,
]);
$siteB->update(['status' => 'active', 'domain' => 'b.test']);

echo "  A={$siteA->slug}(#{$siteA->id})  B={$siteB->slug}(#{$siteB->id})\n\n";

// ================================================================ E1 一次 create = 一次失效
echo "== E1 · create 使当前站版本 +1 ==\n";

foreach ([
    'Content' => function (Site $s) {
        return Content::create([
            'site_id' => $s->id, 'type' => 'article', 'title' => 'cache-c1',
            'slug' => 'cache-c1-'.uniqid(), 'status' => 'published',
            'published_at' => now(),
        ]);
    },
    'Entity' => function (Site $s) {
        return Entity::create([
            'site_id' => $s->id, 'type' => 'product', 'slug' => 'cache-e1-'.uniqid(),
            'name' => 'CacheE1', 'status' => 'published',
        ]);
    },
    'Category' => function (Site $s) {
        return Category::create([
            'site_id' => $s->id, 'slug' => 'cache-cat-'.uniqid(),
            'name' => 'CacheCat', 'type' => 'list', 'is_active' => true,
        ]);
    },
] as $label => $factory) {
    SiteContext::setSite($siteA);
    resetState();

    $v0 = PageCache::version();
    $model = $factory($siteA);
    $v1 = PageCache::version();

    ok("E1.{$label}", "{$label}::create 使版本 +1", $v1 === $v0 + 1,
        "{$v0} → {$v1}（delta=".($v1 - $v0).'）');

    // delete 也应恰好 +1
    resetState();
    SiteContext::setSite($siteA);
    $v2 = PageCache::version();
    $model->delete();
    $v3 = PageCache::version();
    ok("E1.{$label}.delete", "{$label}::delete 使版本 +1", $v3 === $v2 + 1,
        "{$v2} → {$v3}（delta=".($v3 - $v2).'）');
}
echo "\n";

// ================================================================ E2 update = 一次失效
echo "== E2 · update 使版本 +1 ==\n";

SiteContext::setSite($siteA);
resetState();
$ent = Entity::create([
    'site_id' => $siteA->id, 'type' => 'product', 'slug' => 'cache-e2-'.uniqid(),
    'name' => 'E2 原名', 'status' => 'draft',
]);

resetState();
SiteContext::setSite($siteA);
$v0 = PageCache::version();
$ent->update(['name' => 'E2 新名', 'status' => 'published']);
$v1 = PageCache::version();
ok('E2.update', 'Entity::update 使版本 +1', $v1 === $v0 + 1,
    "{$v0} → {$v1}（delta=".($v1 - $v0).'）');

// EntityRelation 也要恰好 +1（曾与模型层钩子叠加）
resetState();
SiteContext::setSite($siteA);
$e1 = Entity::create(['site_id' => $siteA->id, 'type' => 'product',
    'slug' => 'cache-rel-a-'.uniqid(), 'name' => 'RelA', 'status' => 'published']);
resetState();
SiteContext::setSite($siteA);
$v2 = PageCache::version();
EntityRelation::create([
    'site_id' => $siteA->id,
    'from_entity_id' => $e1->id, 'to_entity_id' => $e1->id,
    'relation_type' => 'related_to', 'sort_order' => 1,
]);
$v3 = PageCache::version();
ok('E2.relation', 'EntityRelation::create 使版本 +1（不与模型层钩子叠加）',
    $v3 === $v2 + 1, "{$v2} → {$v3}（delta=".($v3 - $v2).'）');
echo "\n";

// ================================================================ E3 跨站不互相推进
echo "== E3 · A 站写入不推进 B 站版本 ==\n";

// 先在 B 站上下文取基线
resetState();
$versionB = SiteContext::withSite($siteB, static fn (): int => PageCache::version());

resetState();
SiteContext::setSite($siteA);
$versionA = PageCache::version();

Entity::create([
    'site_id' => $siteA->id, 'type' => 'product', 'slug' => 'cache-e3-'.uniqid(),
    'name' => 'E3', 'status' => 'published',
]);

resetState();
$versionAAfter = SiteContext::withSite($siteA, static fn (): int => PageCache::version());
$versionBAfter = SiteContext::withSite($siteB, static fn (): int => PageCache::version());

ok('E3.A-bumped', 'A 站写入推进 A 站版本 +1', $versionAAfter === $versionA + 1,
    "{$versionA} → {$versionAAfter}");
ok('E3.B-untouched', 'B 站版本不受 A 站写入影响', $versionBAfter === $versionB,
    "B: {$versionB} → {$versionBAfter}");
echo "\n";

// ================================================================ E4 跨语言不互相误伤
echo "== E4 · locale 维度的缓存隔离 ==\n";

/**
 * 版本号是**站点级**（不是站点+locale级）—— 这是设计选择：
 * 整页缓存版本一涨，该站所有语言的页面一起失效。
 *
 * 正确性代价是「过度失效」（zh 写入会让 en 页面缓存也失效），
 * 代价只是多生成一次页面，不会输出错内容。
 * 反过来若做成 locale 级，一旦漏登记就会输出**过期且错误**的页面 —— 那才是危险。
 *
 * 故本组断言的是「不出现错内容」，而非「不误伤」：
 * 只要写入后版本确实推进，两种语言都会重新生成 → 正确性有保证。
 */
resetState();
SiteContext::setSite($siteA);
$v0 = PageCache::version();
Entity::create([
    'site_id' => $siteA->id, 'type' => 'product', 'slug' => 'cache-e4-'.uniqid(),
    'name' => 'E4', 'status' => 'published', 'locale' => 'zh-CN',
]);
$v1 = PageCache::version();
ok('E4.version-bumped', 'zh 写入推进版本（en 页面随之重生成，保证不输出旧内容）',
    $v1 === $v0 + 1, "{$v0} → {$v1}");

// zh/en 两行写入各自都应推进恰好 1
$zhEnt = Entity::where('site_id', $siteA->id)->where('slug', 'like', 'cache-e4-%')->first();
resetState();
SiteContext::setSite($siteA);
$v2 = PageCache::version();
Entity::create([
    'site_id' => $siteA->id, 'type' => 'product', 'slug' => 'cache-e4-'.uniqid(),
    'name' => 'E4 EN', 'status' => 'published', 'locale' => 'en',
]);
$v3 = PageCache::version();
ok('E4.en-bumped', 'en 写入同样只推进 +1（未被 zh 写入叠加）',
    $v3 === $v2 + 1, "{$v2} → {$v3}（delta=".($v3 - $v2).'）');
echo "\n";

// ================================================================ E5 登记清单与实际挂载一致
echo "== E5 · CacheInvalidationMap 登记完整且无重复挂载 ==\n";

$map = \App\Support\CacheInvalidationMap::MODELS;
ok('E5.count', '登记模型数 = 15', count($map) === 15, '实际 '.count($map));
ok('E5.unique', '登记清单无重复', count($map) === count(array_unique($map, SORT_REGULAR)));

foreach ([Entity::class, EntityRelation::class, Content::class, Category::class, Site::class] as $must) {
    ok('E5.has.'.class_basename($must),
        class_basename($must).' 已登记在失效清单里', in_array($must, $map, true));
}

// AppServiceProvider 必须只调一次 register()（叠加注册是本Gate 的核心防回归点）
$providerSrc = (string) file_get_contents($root.'/app/Providers/AppServiceProvider.php');
$registerCalls = substr_count($providerSrc, 'CacheInvalidationMap::register()');
ok('E5.register-once', 'AppServiceProvider 只调一次 CacheInvalidationMap::register()',
    $registerCalls === 1, "实际 {$registerCalls} 次");

// 模型层不得再有PageCache::flush()（否则与清单叠加）
$staleHooks = [];
foreach ([Entity::class, EntityRelation::class, Content::class] as $cls) {
    // ReflectionClass 已返回绝对路径，不要再与 $root 拼接（会得到重复路径）
    $file = (string) (new ReflectionClass($cls))->getFileName();
    if (! is_file($file)) {
        $staleHooks[] = class_basename($cls).'(路径不存在)';
        continue;
    }
    // 剥离注释：逐行处理，不用正则（正则分隔符与模式里的 / 冲突易踩坑）
    $raw = (string) file_get_contents($file);
    $lines = preg_split('~\R~', $raw) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = (string) preg_replace('~//.*$~', '', $line);
        $line = (string) preg_replace('~/\*.*?\*/~s', '', $line);
        $kept[] = $line;
    }
    $code = implode("\n", $kept);
    if (str_contains($code, 'PageCache::flush')) {
        $staleHooks[] = class_basename($cls);
    }
}
ok('E5.no-stale-hooks', '模型层无遗留的 PageCache::flush()（否则会与清单叠加）',
    $staleHooks === [], '仍有: '.implode(', ', $staleHooks));
echo "\n";

// ================================================================ 汇总
echo "========================================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo "  - {$f}\n";
    }
}
echo "========================================\n";
exit($FAIL === 0 ? 0 : 1);
